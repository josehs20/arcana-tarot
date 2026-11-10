<?php

namespace Tests\Feature;

use App\Enums\Gender;
use App\Enums\LedgerEntryType;
use App\Enums\UserRole;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Importação a partir da exportação CSV do phpMyAdmin.
 *
 * O CSV não traz o `CREATE TABLE` nem o nome das tabelas: cada bloco começa por
 * um cabeçalho de colunas. O comando precisa reconhecer os blocos do legado e
 * aplicar as mesmas regras do dump SQL.
 */
class LegacyUserImportCsvTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    /** @var array<int, string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        foreach ($this->temporaryDirectories as $directory) {
            @rmdir($directory);
        }

        parent::tearDown();
    }

    public function test_concatenated_csv_imports_credentials_credits_profile_and_skips_attendants(): void
    {
        $hash = Hash::make('senha-legada-123');

        $csv = $this->block(['id', 'nome', 'email', 'senha', 'saldo'], [
            [1, 'Maria Silva', 'Maria@Example.com', $hash, '150.75'],
            [2, 'Atendente Um', 'atendente@example.com', 'segredo123', '0.00'],
        ])
            .$this->block(['id', 'usuario_id', 'descricao', 'comissao', 'situacao'], [
                [1, 2, 'Atendente e suas especialidades.', '30.00', 'Offline'],
            ])
            .$this->block(['id', 'usuario_id', 'email', 'telefone', 'data_nascimento', 'sexo'], [
                [1, 1, 'maria@example.com', '(11) 98888-7777', '1985-01-31', 'Feminino'],
            ])
            .'<code>#2006 - MySQL server has gone away</code><br'.PHP_EOL;

        $path = $this->write('legado.csv', $csv);

        $this->artisan('arcana:import-legacy-users', ['file' => $path, '--execute' => true, '--force' => true])
            ->expectsOutputToContain('Origem das credenciais: eam_usuarios (e-mail: email, senha: senha)')
            ->expectsOutputToContain('Origem dos saldos: eam_usuarios.saldo (vínculo: id)')
            ->expectsOutputToContain('Dados complementares: eam_clientes')
            ->expectsOutputToContain('Contas fora da importação: eam_atendentes')
            ->expectsOutputToContain('arquivo termina com uma mensagem de erro')
            ->expectsOutputToContain('importar pelo fluxo próprio')
            ->assertExitCode(0);

        $user = User::query()->where('email', 'maria@example.com')->sole();

        $this->assertSame('Maria Silva', $user->name);
        $this->assertSame(UserRole::Client, $user->role);
        $this->assertSame('11988887777', $user->phone);
        $this->assertSame('1985-01-31', $user->birth_date?->format('Y-m-d'));
        $this->assertSame(Gender::Female, $user->gender);
        $this->assertTrue(Hash::check('senha-legada-123', $user->password));

        $wallet = Wallet::query()->where('user_id', $user->getKey())->sole();

        $this->assertSame(15075, $wallet->balance_cents);

        $entry = CreditLedgerEntry::query()->sole();

        $this->assertSame(LedgerEntryType::Credit, $entry->type);
        $this->assertSame(15075, $entry->amount_cents);
        $this->assertSame('legacy-import:eam_usuarios:1', $entry->idempotency_key);

        $this->assertFalse(User::query()->where('email', 'atendente@example.com')->exists());
        $this->assertSame(1, User::query()->count());
    }

    /**
     * Arquivo como o `astros21 (1).csv` real: só blocos sem credenciais, com o
     * erro da exportação no fim. O comando precisa listar o que encontrou e
     * bloquear explicando que falta a tabela de usuários.
     */
    public function test_csv_without_the_users_block_is_blocked(): void
    {
        $csv = $this->block(['id', 'nome', 'texto', 'criado'], [
            [1, 'Artigos', '&lt;h2&gt;Texto do artigo&lt;/h2&gt;', 'NULL'],
        ])
            .$this->block(['id', 'usuario_id', 'descricao', 'comissao', 'situacao'], [
                [1, 2, 'Descrição do atendente e suas especialidades.', '30.00', 'Offline'],
            ])
            .'<code>#2006 - MySQL server has gone away</code><br'.PHP_EOL;

        $path = $this->write('astros21 (1).csv', $csv);

        $this->artisan('arcana:import-legacy-users', ['file' => $path, '--execute' => true])
            ->expectsOutputToContain('Tabelas encontradas no arquivo (2)')
            ->expectsOutputToContain('astros21#1')
            ->expectsOutputToContain('não existe no arquivo CSV')
            ->expectsOutputToContain('Importação bloqueada')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    /**
     * Exportação de uma tabela por arquivo: o nome do arquivo identifica a
     * tabela mesmo quando as colunas não bastam para a assinatura.
     */
    public function test_single_block_csv_takes_the_table_name_from_the_file(): void
    {
        $path = $this->write(
            'eam_atendentes (1).csv',
            $this->block(['id', 'usuario_id', 'nome_exibicao'], [[1, 7, 'Atendente']]),
        );

        $this->artisan('arcana:import-legacy-users', ['file' => $path])
            ->expectsOutputToContain('Contas fora da importação: eam_atendentes (vínculo: usuario_id)')
            ->expectsOutputToContain('não existe no arquivo CSV')
            ->assertExitCode(1);
    }

    /**
     * Exportação de uma tabela por arquivo: o diretório é lido de uma vez e os
     * arquivos formam uma origem só, como no CSV com todos os blocos. Arquivos
     * que não são exportação (`.md`) ficam de fora.
     */
    public function test_directory_with_one_file_per_table_is_imported_in_a_single_run(): void
    {
        $hash = Hash::make('senha-legada-456');
        $directory = $this->directory();

        $this->writeIn($directory, 'eam_usuarios.csv', $this->block(
            ['id', 'usuario', 'senha', 'nome', 'email'],
            [
                [1, 'maria', $hash, 'Maria Silva', 'maria@example.com'],
                [2, 'joao', $hash, 'João Souza', 'joao@example.com'],
            ],
        ));

        $this->writeIn($directory, 'eam_clientes.csv', $this->block(
            ['id', 'usuario_id', 'sexo', 'data_nascimento', 'telefone'],
            [[1, 2, 'Masculino', '1990-05-04', '(21) 97777-1234']],
        ));

        $this->writeIn($directory, 'instrucoes.md', 'Exportação feita pelo phpMyAdmin.');

        $this->artisan('arcana:import-legacy-users', ['file' => $directory, '--execute' => true])
            ->expectsOutputToContain('Arquivos (2,')
            ->expectsOutputToContain('eam_clientes.csv')
            ->expectsOutputToContain('eam_usuarios.csv')
            ->expectsOutputToContain('Origem das credenciais: eam_usuarios (e-mail: email, senha: senha)')
            ->expectsOutputToContain('Dados complementares: eam_clientes')
            ->assertExitCode(0);

        $this->assertSame(2, User::query()->count());

        $maria = User::query()->where('email', 'maria@example.com')->sole();
        $this->assertNull($maria->phone);
        $this->assertTrue(Hash::check('senha-legada-456', $maria->password));

        $joao = User::query()->where('email', 'joao@example.com')->sole();
        $this->assertSame('21977771234', $joao->phone);
        $this->assertSame('1990-05-04', $joao->birth_date?->format('Y-m-d'));
        $this->assertSame(Gender::Male, $joao->gender);
    }

    /**
     * Os arquivos também podem ser informados um a um, sem depender do diretório.
     */
    public function test_files_can_be_informed_explicitly(): void
    {
        $hash = Hash::make('senha-legada-321');
        $directory = $this->directory();

        $users = $this->writeIn($directory, 'eam_usuarios.csv', $this->block(
            ['id', 'usuario', 'senha', 'email'],
            [[1, 'maria', $hash, 'maria@example.com']],
        ));

        $clients = $this->writeIn($directory, 'eam_clientes.csv', $this->block(
            ['id', 'usuario_id', 'telefone'],
            [[1, 1, '(11) 98888-7777']],
        ));

        $this->artisan('arcana:import-legacy-users', ['file' => [$users, $clients], '--execute' => true])
            ->expectsOutputToContain('Arquivos (2,')
            ->assertExitCode(0);

        $this->assertSame(
            '11988887777',
            User::query()->where('email', 'maria@example.com')->sole()->phone,
        );
    }

    /**
     * Diretório sem exportação legada precisa falhar com mensagem clara, sem
     * cair no leitor SQL e reportar "arquivo vazio".
     */
    public function test_directory_without_legacy_files_is_rejected(): void
    {
        $directory = $this->directory();

        $this->writeIn($directory, 'instrucoes.md', 'Apenas documentação.');

        $this->artisan('arcana:import-legacy-users', ['file' => $directory])
            ->expectsOutputToContain('Nenhum arquivo legado encontrado')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    /**
     * Com vários arquivos o aviso de exportação interrompida precisa dizer qual
     * arquivo está cortado.
     */
    public function test_truncated_file_in_the_directory_is_reported_by_name(): void
    {
        $directory = $this->directory();

        $this->writeIn($directory, 'eam_usuarios.csv', $this->block(
            ['id', 'usuario', 'senha', 'email'],
            [[1, 'maria', Hash::make('senha-legada-123'), 'maria@example.com']],
        ));

        $this->writeIn(
            $directory,
            'eam_clientes.csv',
            $this->block(['id', 'usuario_id', 'telefone'], [[1, 1, null]])
                .'<code>#2006 - MySQL server has gone away</code><br'.PHP_EOL,
        );

        $this->artisan('arcana:import-legacy-users', ['file' => $directory])
            ->expectsOutputToContain('ATENÇÃO: o arquivo eam_clientes.csv termina com uma mensagem de erro')
            ->assertExitCode(0);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, string|int|null>>  $rows
     */
    private function block(array $columns, array $rows): string
    {
        $lines = [$this->csvLine($columns)];

        foreach ($rows as $row) {
            $lines[] = $this->csvLine($row);
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * @param  array<int, string|int|null>  $fields
     */
    private function csvLine(array $fields): string
    {
        return implode(',', array_map(
            fn (string|int|null $value): string => $value === null
                ? 'NULL'
                : '"'.str_replace('"', '""', (string) $value).'"',
            $fields,
        ));
    }

    private function write(string $name, string $contents): string
    {
        return $this->writeIn($this->directory(), $name, $contents);
    }

    private function writeIn(string $directory, string $name, string $contents): string
    {
        $path = $directory.'/'.$name;

        if (file_put_contents($path, $contents) === false) {
            $this->fail('Não foi possível criar o arquivo temporário do teste.');
        }

        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir().'/arcana-legacy-csv-'.uniqid();

        if (! @mkdir($directory, 0777, true)) {
            $this->fail('Não foi possível criar o diretório temporário do teste.');
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
