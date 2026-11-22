<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PharData;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Backup automático do banco e do storage (arcana:backup).
 *
 * O comando roda a cada seis horas pelo scheduler e é a única cópia dos dados
 * fora do banco de produção. O que precisa continuar valendo: o conjunto sai
 * completo (dump legível, arquivo de storage, manifesto com hashes), a retenção
 * deixa um único conjunto e nunca apaga diretórios do operador, e a execução
 * concorrente não disputa os arquivos do backup em andamento.
 *
 * O dump é lido e conferido aqui porque um arquivo gzip válido com SQL quebrado
 * só apareceria na hora de restaurar — quando já não há mais nada a fazer.
 */
class BackupCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $destination;

    private string $storageSource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->destination = storage_path('framework/testing/backup-'.Str::lower(Str::random(8)));
        $this->storageSource = storage_path('framework/testing/backup-source-'.Str::lower(Str::random(8)));

        File::makeDirectory($this->storageSource.'/imagens', 0o775, true, true);
        File::put($this->storageSource.'/imagens/carta.jpg', 'conteudo-da-carta');

        config([
            'backup.directory' => $this->destination,
            'backup.keep' => 1,
            'backup.storage_paths' => [$this->storageSource],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        File::deleteDirectory($this->destination);
        File::deleteDirectory($this->storageSource);

        parent::tearDown();
    }

    public function test_command_writes_the_dump_the_storage_archive_and_the_manifest(): void
    {
        $user = User::factory()->create(['email' => 'alvo-do-backup@arcana.test']);

        $this->artisan('arcana:backup')
            ->expectsOutputToContain('Backup concluído')
            ->assertExitCode(0);

        $sets = glob($this->destination.'/arcana-backup-*') ?: [];

        $this->assertCount(1, $sets);

        $set = $sets[0];

        $this->assertFileExists($set.'/database.sql.gz');
        $this->assertFileExists($set.'/files.tar.gz');
        $this->assertFileExists($set.'/manifest.json');

        $dump = gzdecode((string) file_get_contents($set.'/database.sql.gz'));

        $this->assertIsString($dump);
        $this->assertStringContainsString('CREATE TABLE "users"', $dump);
        $this->assertStringContainsString($user->email, $dump);

        $manifest = json_decode((string) file_get_contents($set.'/manifest.json'), true);

        $this->assertIsArray($manifest);
        $this->assertSame('sqlite', $manifest['database']['driver']);
        $this->assertSame(hash_file('sha256', $set.'/database.sql.gz'), $manifest['database']['sha256']);
        $this->assertGreaterThan(0, $manifest['database']['tables']);
        $this->assertGreaterThan(0, $manifest['database']['rows']);
        $this->assertSame(1, $manifest['retention']);
        $this->assertSame(1, $manifest['files']['count']);
        $this->assertSame(hash_file('sha256', $set.'/files.tar.gz'), $manifest['files']['sha256']);

        $this->assertTrue(
            $this->archiveContains($set.'/files.tar.gz', 'imagens/carta.jpg'),
            'O arquivo de storage não contém a imagem enviada para o teste.',
        );
    }

    public function test_retention_keeps_only_the_latest_set_and_preserves_operator_directories(): void
    {
        File::makeDirectory($this->destination.'/manual-do-operador', 0o775, true, true);
        File::put($this->destination.'/manual-do-operador/copia.tar.gz', 'arquivo do operador');

        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00', 'UTC'));
        $this->artisan('arcana:backup', ['--database-only' => true])->assertExitCode(0);

        Carbon::setTestNow(Carbon::parse('2026-01-01 06:00:00', 'UTC'));
        $this->artisan('arcana:backup', ['--database-only' => true])->assertExitCode(0);

        $sets = glob($this->destination.'/arcana-backup-*') ?: [];

        $this->assertCount(1, $sets, 'A retenção deveria manter um único conjunto.');
        $this->assertSame($this->destination.'/arcana-backup-2026-01-01T060000Z', $sets[0]);
        $this->assertFileExists($this->destination.'/manual-do-operador/copia.tar.gz');
    }

    public function test_database_only_option_skips_the_storage_archive(): void
    {
        $this->artisan('arcana:backup', ['--database-only' => true])->assertExitCode(0);

        $sets = glob($this->destination.'/arcana-backup-*') ?: [];

        $this->assertCount(1, $sets);
        $this->assertFileDoesNotExist($sets[0].'/files.tar.gz');

        $manifest = json_decode((string) file_get_contents($sets[0].'/manifest.json'), true);

        $this->assertIsArray($manifest);
        $this->assertNull($manifest['files']);
        $this->assertSame([], $manifest['storage_paths']);
    }

    public function test_command_reports_storage_paths_it_cannot_read(): void
    {
        config(['backup.storage_paths' => [
            $this->storageSource,
            storage_path('framework/testing/inexistente-'.Str::lower(Str::random(8))),
        ]]);

        $this->artisan('arcana:backup')
            ->expectsOutputToContain('Itens fora do arquivo de storage')
            ->assertExitCode(0);

        $sets = glob($this->destination.'/arcana-backup-*') ?: [];

        $manifest = json_decode((string) file_get_contents($sets[0].'/manifest.json'), true);

        $this->assertIsArray($manifest);
        $this->assertSame(1, $manifest['files']['count']);
        $this->assertCount(1, $manifest['files']['skipped']);
    }

    public function test_command_rejects_a_retention_below_one(): void
    {
        $this->artisan('arcana:backup', ['--keep' => 0])
            ->expectsOutputToContain('A opção --keep precisa ser um número inteiro maior ou igual a 1.')
            ->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($this->destination);
    }

    public function test_command_is_skipped_while_another_backup_holds_the_lock(): void
    {
        $lock = Cache::lock('arcana:backup', 60);

        $this->assertTrue($lock->get());

        try {
            $this->artisan('arcana:backup')
                ->expectsOutputToContain('Outro backup está em execução')
                ->assertExitCode(0);
        } finally {
            $lock->release();
        }

        $this->assertDirectoryDoesNotExist($this->destination);
    }

    public function test_scheduler_runs_the_backup_every_six_hours(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $event = collect($schedule->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'arcana:backup'));

        $this->assertNotNull($event, 'O arcana:backup não está agendado em routes/console.php.');
        $this->assertSame('0 */6 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(120, $event->expiresAt);
    }

    /**
     * O nome dentro do tar é relativo à raiz do projeto, então a comparação é
     * por sufixo: `storage/framework/.../imagens/carta.jpg`.
     */
    private function archiveContains(string $archivePath, string $suffix): bool
    {
        $archive = new PharData($archivePath);
        $found = false;

        foreach (new RecursiveIteratorIterator($archive) as $entry) {
            if ($entry->isFile() && str_ends_with($entry->getPathname(), $suffix)) {
                $found = true;

                break;
            }
        }

        unset($archive);

        return $found;
    }
}
