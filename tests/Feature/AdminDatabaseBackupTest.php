<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Backup\DatabaseDumper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Tela "Banco de dados" do painel: backup manual e importação de dump.
 *
 * O que precisa continuar valendo: o administrador vê o conjunto que está no
 * disco, o botão grava um backup completo, e a importação apaga as tabelas e
 * recria tudo a partir do arquivo — sempre depois de um backup de segurança e
 * com a sessão encerrada. Um ponto e vírgula dentro de um valor de texto não
 * pode cortar o `INSERT` do dump.
 */
class AdminDatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $destination;

    private string $safety;

    private string $storageSource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->destination = storage_path('framework/testing/database-'.Str::lower(Str::random(8)));
        $this->safety = $this->destination.'/pre-import';
        $this->storageSource = storage_path('framework/testing/database-source-'.Str::lower(Str::random(8)));

        File::makeDirectory($this->storageSource, 0o775, true, true);
        File::put($this->storageSource.'/arquivo.txt', 'conteudo-do-storage');

        config([
            'backup.directory' => $this->destination,
            'backup.safety_directory' => $this->safety,
            'backup.keep' => 1,
            'backup.storage_paths' => [$this->storageSource],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->destination);
        File::deleteDirectory($this->storageSource);

        parent::tearDown();
    }

    public function test_the_screen_lists_the_backup_that_is_on_disk(): void
    {
        $admin = User::factory()->admin()->create();

        $this->artisan('arcana:backup')->assertExitCode(0);

        $sets = glob($this->destination.'/arcana-backup-*') ?: [];

        $this->assertCount(1, $sets);

        $this->actingAs($admin)
            ->get(route('admin.database.index'))
            ->assertOk()
            ->assertSee('Banco de dados')
            ->assertSee('Fazer backup agora')
            ->assertSee('Importar banco')
            ->assertSee(basename($sets[0]))
            ->assertSee('O banco atual será apagado.')
            ->assertSee('database.sql.gz');
    }

    public function test_only_admins_reach_the_database_screen(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)
            ->get(route('admin.database.index'))
            ->assertForbidden();

        $this->actingAs($client)
            ->post(route('admin.database.backup'))
            ->assertForbidden();

        $this->assertSame([], glob($this->destination.'/arcana-backup-*') ?: []);
    }

    public function test_admin_creates_a_backup_from_the_panel(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.database.backup'))
            ->assertRedirect(route('admin.database.index'))
            ->assertSessionHas('success');

        $sets = glob($this->destination.'/arcana-backup-*') ?: [];

        $this->assertCount(1, $sets);
        $this->assertFileExists($sets[0].'/database.sql.gz');
        $this->assertFileExists($sets[0].'/files.tar.gz');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'database.backup_created',
            'actor_id' => $admin->id,
        ]);
    }

    public function test_import_replaces_every_table_with_the_uploaded_dump(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin-do-painel@arcana.test']);
        $cliente = User::factory()->create([
            'name' => "Cliente; 'com ponto e vírgula' -- e traços",
        ]);

        $dump = $this->dump();

        $cliente->delete();
        $this->assertDatabaseMissing('users', ['id' => $cliente->id]);

        $this->sendImport($admin, $dump, 'banco.sql.gz')
            ->assertRedirect(route('login'))
            ->assertSessionHas('success');

        $this->assertGuest();

        $restored = User::query()->find($cliente->id);

        $this->assertNotNull($restored, 'O dump importado precisa devolver os registros apagados.');
        $this->assertSame("Cliente; 'com ponto e vírgula' -- e traços", $restored->name);
        $this->assertSame('admin-do-painel@arcana.test', (string) User::query()->find($admin->id)?->email);

        $safetySets = glob($this->safety.'/arcana-backup-*') ?: [];

        $this->assertCount(1, $safetySets);
        $this->assertFileExists($safetySets[0].'/manifest.json');

        $this->assertDatabaseHas('audit_logs', ['action' => 'database.imported']);

        /*
         * O evento `database.import_started` é gravado no banco que a própria
         * importação substitui — ele só sobrevive quando a importação falha.
         */
        $this->assertDatabaseMissing('audit_logs', ['action' => 'database.import_started']);
    }

    public function test_import_requires_the_confirmation_and_a_sql_file(): void
    {
        $admin = User::factory()->admin()->create();
        $cliente = User::factory()->create();

        $dump = $this->dump();

        $cliente->delete();

        $this->sendImport($admin, $dump, 'banco.sql', confirm: false)
            ->assertSessionHasErrors('confirmacao');

        $this->sendImport($admin, $dump, 'banco.txt')
            ->assertSessionHasErrors('database_file');

        $this->assertDatabaseMissing('users', ['id' => $cliente->id]);
        $this->assertSame([], glob($this->safety.'/arcana-backup-*') ?: []);
        $this->actingAs($admin)->get(route('admin.database.index'))->assertOk();
    }

    public function test_import_cancels_when_the_dump_comes_from_another_driver(): void
    {
        $admin = User::factory()->admin()->create();
        $cliente = User::factory()->create();

        /*
         * O teste roda em SQLite (phpunit.xml) e também pode rodar em MySQL: o
         * cabeçalho é sempre de um driver diferente do atual.
         */
        $current = DB::connection()->getDriverName();
        $other = $current === 'sqlite' ? 'mysql' : 'sqlite';

        $path = $this->destination.'/outro-driver.sql';
        File::ensureDirectoryExists($this->destination);
        File::put($path, "-- Driver: {$other} | Banco: arcana_tarot\nCREATE TABLE users (id INTEGER);\n");

        $this->sendImport($admin, $path, 'banco.sql')
            ->assertSessionHas('error', fn ($message): bool => str_contains((string) $message, "dump de {$other}"));

        $this->assertDatabaseHas('users', ['id' => $cliente->id]);
        $this->assertSame([], glob($this->safety.'/arcana-backup-*') ?: []);
    }

    /**
     * Dump real do banco de teste, no formato que o painel também aceita.
     */
    private function dump(): string
    {
        File::ensureDirectoryExists($this->destination);

        $path = $this->destination.'/dump-'.Str::lower(Str::random(6)).'.sql.gz';

        app(DatabaseDumper::class)->dump($path);

        return $path;
    }

    private function sendImport(User $admin, string $path, string $clientName, bool $confirm = true): TestResponse
    {
        return $this->actingAs($admin)
            ->from(route('admin.database.index'))
            ->post(route('admin.database.import'), [
                'database_file' => UploadedFile::fake()->createWithContent(
                    $clientName,
                    (string) file_get_contents($path),
                ),
                'confirmacao' => $confirm ? '1' : null,
            ]);
    }
}
