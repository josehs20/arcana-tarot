<?php

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LegacyUserImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_dry_run_reports_planned_users_without_writing(): void
    {
        $path = $this->write($this->usersDump([
            [1, 'Maria Silva', 'maria@example.com', 'segredo123', '11999999999', '150.75'],
        ], withBalance: true));

        $this->artisan('arcana:import-legacy-users', ['file' => $path])
            ->expectsOutputToContain('SIMULAÇÃO')
            ->expectsOutputToContain('seria importado')
            ->expectsOutputToContain('nenhum dado foi gravado')
            ->assertExitCode(0);

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Wallet::query()->count());
        $this->assertSame(0, CreditLedgerEntry::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_execute_imports_user_with_compatible_password_and_credits(): void
    {
        $hash = Hash::make('senha-legada-123');

        $path = $this->write($this->usersDump([
            [7, 'Maria Silva', 'Maria@Example.com', $hash, '(11) 99999-9999', '150.75'],
        ], withBalance: true));

        $this->artisan('arcana:import-legacy-users', ['file' => $path, '--execute' => true])
            ->expectsOutputToContain('importado')
            ->assertExitCode(0);

        $user = User::query()->where('email', 'maria@example.com')->sole();

        $this->assertSame('Maria Silva', $user->name);
        $this->assertSame(UserRole::Client, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertSame('11999999999', $user->phone);
        $this->assertTrue(Hash::check('senha-legada-123', $user->password));

        $wallet = Wallet::query()->where('user_id', $user->getKey())->sole();

        $this->assertSame(15075, $wallet->balance_cents);

        $entry = CreditLedgerEntry::query()->sole();

        $this->assertSame(LedgerEntryType::Credit, $entry->type);
        $this->assertSame(15075, $entry->amount_cents);
        $this->assertSame(15075, $entry->balance_after_cents);
        $this->assertSame('legacy-import:usuarios:7', $entry->idempotency_key);

        $audit = AuditLog::query()->where('action', 'user.imported_from_legacy')->sole();

        $this->assertSame($user->getKey(), $audit->subject_id);
        $this->assertSame('usuarios', $audit->metadata['source_table']);
        $this->assertSame('7', $audit->metadata['legacy_key']);
        $this->assertSame('bcrypt', $audit->metadata['password_format']);
        $this->assertTrue($audit->metadata['password_reused']);
        $this->assertSame(15075, $audit->metadata['credits_cents']);
        $this->assertSame(1, AuditLog::query()->where('action', 'user.import_batch_from_legacy')->count());
    }

    public function test_password_outside_laravel_support_forces_reset(): void
    {
        $path = $this->write($this->usersDump([
            [1, 'Md5', 'md5@example.com', md5('senha-antiga'), null],
            [2, 'Texto', 'texto@example.com', 'senha-antiga', null],
            [3, 'Vazio', 'vazio@example.com', null, null],
        ]));

        $this->artisan('arcana:import-legacy-users', ['file' => $path, '--execute' => true])
            ->expectsOutputToContain('redefinição de senha')
            ->assertExitCode(0);

        foreach (['md5@example.com', 'texto@example.com', 'vazio@example.com'] as $email) {
            $user = User::query()->where('email', $email)->sole();

            $this->assertFalse(Hash::check('senha-antiga', $user->password));
            $this->assertNotSame('senha-antiga', $user->password);
            $this->assertGreaterThan(40, mb_strlen($user->password));
        }

        $this->assertSame(3, AuditLog::query()->where('action', 'user.imported_from_legacy')->count());
        $this->assertFalse(
            AuditLog::query()->where('action', 'user.imported_from_legacy')->first()->metadata['password_reused'],
        );
    }

    public function test_credits_from_separate_table_use_last_balance_of_each_user(): void
    {
        $dump = $this->createUsersTable()
            .$this->insert('usuarios', ['id', 'nome', 'email', 'senha', 'telefone'], [
                [1, 'Maria', 'maria@example.com', 'segredo123', null],
                [2, 'João', 'joao@example.com', 'segredo123', null],
            ])
            .<<<'SQL'
CREATE TABLE `carteiras` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `saldo` decimal(10,2) NOT NULL DEFAULT '0.00'
);

SQL
            .$this->insert('carteiras', ['id', 'usuario_id', 'saldo'], [
                [1, 1, '30.00'],
                [2, 1, '120.00'],
                [3, 2, '10.10'],
            ]);

        $path = $this->write($dump);

        $this->artisan('arcana:import-legacy-users', ['file' => $path, '--execute' => true])
            ->assertExitCode(0);

        $maria = Wallet::query()->whereHas('user', fn ($query) => $query->where('email', 'maria@example.com'))->sole();
        $joao = Wallet::query()->whereHas('user', fn ($query) => $query->where('email', 'joao@example.com'))->sole();

        $this->assertSame(12000, $maria->balance_cents);
        $this->assertSame(1010, $joao->balance_cents);
    }

    public function test_duplicated_emails_are_reported_as_conflicts(): void
    {
        User::factory()->create(['email' => 'existente@example.com']);

        $path = $this->write($this->usersDump([
            [1, 'Ja Existe', 'existente@example.com', 'segredo123', null],
            [2, 'Nova', 'nova@example.com', 'segredo123', null],
            [3, 'Nova Repetida', 'nova@example.com', 'segredo123', null],
        ]));

        $this->artisan('arcana:import-legacy-users', ['file' => $path, '--execute' => true])
            ->expectsOutputToContain('e-mail já cadastrado no Arcana')
            ->expectsOutputToContain('e-mail repetido no dump')
            ->assertExitCode(0);

        $this->assertSame(2, User::query()->count());
        $this->assertTrue(User::query()->where('email', 'nova@example.com')->exists());
        $this->assertSame(1, AuditLog::query()->where('action', 'user.imported_from_legacy')->count());
    }

    public function test_rerun_does_not_duplicate_users_or_credits(): void
    {
        $path = $this->write($this->usersDump([
            [1, 'Maria', 'maria@example.com', 'segredo123', null, '20.00'],
        ], withBalance: true));

        $this->artisan('arcana:import-legacy-users', ['file' => $path, '--execute' => true])->assertExitCode(0);
        $this->artisan('arcana:import-legacy-users', ['file' => $path, '--execute' => true])
            ->expectsOutputToContain('e-mail já cadastrado no Arcana')
            ->assertExitCode(0);

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, Wallet::query()->count());
        $this->assertSame(1, CreditLedgerEntry::query()->count());
        $this->assertSame(2000, Wallet::query()->sole()->balance_cents);
        $this->assertSame(1, AuditLog::query()->where('action', 'user.imported_from_legacy')->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'user.import_batch_from_legacy')->count());
    }

    public function test_dump_without_credentials_is_blocked(): void
    {
        $path = $this->write(<<<'SQL'
CREATE TABLE `batata` (
  `id` int(11) NOT NULL,
  `usuario` varchar(50) NOT NULL,
  `senha` varchar(50) NOT NULL
);
INSERT INTO `batata` (`id`, `usuario`, `senha`) VALUES (1, 'jakson', '123');

SQL);

        $this->artisan('arcana:import-legacy-users', ['file' => $path, '--execute' => true])
            ->expectsOutputToContain('batata')
            ->expectsOutputToContain('Nenhuma tabela com e-mail e senha')
            ->expectsOutputToContain('Importação bloqueada')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    public function test_truncated_dump_is_blocked_on_execute_without_force(): void
    {
        $path = $this->write(
            $this->usersDump([[1, 'Maria', 'maria@example.com', 'segredo123', null]])
            .'<div class="alert alert-danger">#2006 - MySQL server has gone away</div>'
        );

        $this->artisan('arcana:import-legacy-users', ['file' => $path])
            ->expectsOutputToContain('ATENÇÃO')
            ->expectsOutputToContain('seria importado')
            ->assertExitCode(0);

        $this->assertSame(0, User::query()->count());

        $this->artisan('arcana:import-legacy-users', ['file' => $path, '--execute' => true])
            ->expectsOutputToContain('use --force')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());

        $this->artisan('arcana:import-legacy-users', ['file' => $path, '--execute' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(1, User::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'user.import_batch_from_legacy')->sole()->metadata['forced']);
    }

    public function test_missing_dump_file_is_rejected(): void
    {
        $this->artisan('arcana:import-legacy-users', ['file' => '/tmp/arcana-dump-inexistente.sql'])
            ->expectsOutputToContain('vazio ou ilegível')
            ->assertExitCode(1);
    }

    public function test_malformed_insert_aborts_with_error_and_keeps_previous_rows(): void
    {
        $path = $this->write(<<<'SQL'
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `email` varchar(120) NOT NULL,
  `senha` varchar(255) NOT NULL
);
INSERT INTO `usuarios` (`id`, `email`, `senha`) VALUES (1, 'maria@example.com', 'segredo123');
INSERT INTO `usuarios` (`id`, `email` VALUES (2, 'joao@example.com', 'segredo123');

SQL);

        $this->artisan('arcana:import-legacy-users', ['file' => $path, '--execute' => true])
            ->expectsOutputToContain('Lista de colunas inválida')
            ->assertExitCode(1);

        $this->assertSame(1, User::query()->count());
        $this->assertTrue(User::query()->where('email', 'maria@example.com')->exists());
    }

    public function test_negative_balance_imports_user_without_crediting(): void
    {
        $path = $this->write($this->usersDump([
            [1, 'Devedora', 'devedora@example.com', 'segredo123', null, '-10.00'],
        ], withBalance: true));

        $this->artisan('arcana:import-legacy-users', ['file' => $path, '--execute' => true])
            ->expectsOutputToContain('saldo negativo no legado')
            ->assertExitCode(0);

        $user = User::query()->sole();

        $this->assertSame(0, Wallet::query()->where('user_id', $user->getKey())->sole()->balance_cents);
        $this->assertSame(0, CreditLedgerEntry::query()->count());
    }

    public function test_limit_and_table_overrides_control_the_import(): void
    {
        $dump = <<<'SQL'
CREATE TABLE `tb_acesso` (
  `codigo` int(11) NOT NULL,
  `contato` varchar(120) NOT NULL,
  `segredo` varchar(255) NOT NULL,
  `apelido` varchar(120) DEFAULT NULL,
  `celular` varchar(20) DEFAULT NULL
);
INSERT INTO `tb_acesso` (`codigo`, `contato`, `segredo`, `apelido`, `celular`) VALUES
(11, 'primeira@example.com', 'segredo123', 'Primeira', '11999999999'),
(12, 'segunda@example.com', 'segredo123', 'Segunda', NULL);

SQL;

        $path = $this->write($dump);

        $this->artisan('arcana:import-legacy-users', ['file' => $path])
            ->expectsOutputToContain('Nenhuma tabela com e-mail e senha')
            ->assertExitCode(1);

        $this->artisan('arcana:import-legacy-users', [
            'file' => $path,
            '--execute' => true,
            '--users-table' => 'tb_acesso',
            '--email-column' => 'contato',
            '--password-column' => 'segredo',
            '--name-column' => 'apelido',
            '--phone-column' => 'celular',
            '--user-key-column' => 'codigo',
            '--credits-table' => 'none',
            '--limit' => 1,
        ])->assertExitCode(0);

        $this->assertSame(1, User::query()->count());
        $this->assertSame('Primeira', User::query()->sole()->name);
        $this->assertSame(0, Wallet::query()->sole()->balance_cents);
    }

    private function write(string $sql): string
    {
        $path = tempnam(sys_get_temp_dir(), 'arcana-legacy-');

        if ($path === false) {
            $this->fail('Não foi possível criar o arquivo temporário do teste.');
        }

        file_put_contents($path, $sql);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function createUsersTable(string $table = 'usuarios', bool $withBalance = false): string
    {
        $definition = <<<'SQL'
CREATE TABLE `__TABLE__` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(120) DEFAULT NULL,
  `email` varchar(120) NOT NULL,
  `senha` varchar(255) DEFAULT NULL,
  `telefone` varchar(20) DEFAULT NULL,
__BALANCE__  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

SQL;

        return str_replace(
            ['__TABLE__', '__BALANCE__  PRIMARY KEY (`id`)'],
            [
                $table,
                ($withBalance ? "  `saldo` decimal(10,2) NOT NULL DEFAULT '0.00',\n" : '').'  PRIMARY KEY (`id`)',
            ],
            $definition,
        );
    }

    /**
     * @param  array<int, array<int, string|int|null>>  $rows
     */
    private function usersDump(array $rows, bool $withBalance = false): string
    {
        $columns = ['id', 'nome', 'email', 'senha', 'telefone'];

        if ($withBalance) {
            $columns[] = 'saldo';
        }

        return $this->createUsersTable(withBalance: $withBalance)
            .$this->insert('usuarios', $columns, $rows);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, string|int|null>>  $rows
     */
    private function insert(string $table, array $columns, array $rows): string
    {
        $literals = array_map(
            fn (array $row): string => '('.implode(', ', array_map($this->literal(...), $row)).')',
            $rows,
        );

        return 'INSERT INTO `'.$table.'` (`'.implode('`, `', $columns).'`) VALUES'
            .PHP_EOL.implode(','.PHP_EOL, $literals).';'.PHP_EOL;
    }

    private function literal(string|int|null $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
