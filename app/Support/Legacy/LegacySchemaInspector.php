<?php

namespace App\Support\Legacy;

/**
 * Identifica, na estrutura de um dump legado, a tabela de credenciais e a
 * origem dos saldos.
 *
 * A detecção é heurística e sempre conservadora: quando há ambiguidade nada é
 * escolhido e o operador precisa informar a tabela/coluna explicitamente. O
 * relatório (dry-run) mostra as tabelas rejeitadas e o motivo.
 */
final class LegacySchemaInspector
{
    private const EMAIL_COLUMNS = ['email', 'e_mail', 'email_principal', 'mail', 'correio', 'user_email'];

    private const PASSWORD_COLUMNS = ['senha', 'password', 'senha_hash', 'password_hash', 'passwd', 'pwd', 'hash', 'pass'];

    private const NAME_COLUMNS = ['nome', 'name', 'nome_completo', 'display_name', 'apelido'];

    private const PHONE_COLUMNS = ['telefone', 'phone', 'celular', 'fone', 'whatsapp'];

    private const KEY_COLUMNS = ['id', 'codigo', 'cod', 'cod_usuario', 'user_id', 'usuario_id'];

    private const USER_REFERENCE_COLUMNS = ['usuario_id', 'user_id', 'id_usuario', 'id_user', 'cliente_id', 'id_cliente'];

    private const CREDIT_COLUMNS = ['saldo', 'saldo_atual', 'saldo_creditos', 'creditos', 'credito', 'credit', 'credits', 'balance', 'balance_cents'];

    private const CREDIT_IGNORED_COLUMNS = ['comissao', 'valor_minuto', 'preco', 'price', 'taxa', 'desconto'];

    private const USER_TABLE_HINTS = ['usuari', 'cliente', 'user', 'membro', 'conta', 'cadastr', 'pessoa'];

    /**
     * @param  array<string, LegacyDumpTable>  $tables
     * @param  array<string, string|null>  $overrides
     */
    public function inspect(array $tables, array $overrides = []): LegacyImportPlan
    {
        $issues = [];
        $notes = [];
        $rejected = [];

        $userTable = $this->resolveUserTable($tables, $overrides, $issues, $rejected);

        $emailColumn = $this->resolveColumn($overrides, 'email_column', $userTable, self::EMAIL_COLUMNS, 'e-mail', true, $issues);
        $passwordColumn = $this->resolveColumn($overrides, 'password_column', $userTable, self::PASSWORD_COLUMNS, 'senha', true, $issues);
        $nameColumn = $this->resolveColumn($overrides, 'name_column', $userTable, self::NAME_COLUMNS, 'nome', false, $issues);
        $phoneColumn = $this->resolveColumn($overrides, 'phone_column', $userTable, self::PHONE_COLUMNS, 'telefone', false, $issues);
        $keyColumn = $this->resolveColumn($overrides, 'user_key_column', $userTable, self::KEY_COLUMNS, 'identificador', false, $issues);

        if ($userTable !== null && $keyColumn === null) {
            $notes[] = 'A tabela de usuários não possui coluna identificadora; a chave de idempotência usará o e-mail.';
        }

        [$creditsTable, $creditsUserColumn, $creditsBalanceColumn] = $this->resolveCredits(
            $tables,
            $overrides,
            $userTable,
            $keyColumn,
            $issues,
            $notes,
            $rejected,
        );

        if ($creditsTable !== null && $keyColumn === null) {
            $issues[] = 'Não é possível relacionar os saldos com os usuários sem uma coluna identificadora na tabela de usuários.';
        }

        if ($userTable !== null && $emailColumn !== null && $passwordColumn === null) {
            $notes[] = "A tabela {$userTable->name} não possui coluna de senha; nenhum usuário poderá ser importado com credencial.";
        }

        return new LegacyImportPlan(
            userTable: $userTable,
            emailColumn: $emailColumn,
            passwordColumn: $passwordColumn,
            nameColumn: $nameColumn,
            phoneColumn: $phoneColumn,
            legacyKeyColumn: $keyColumn,
            creditsTable: $creditsTable,
            creditsUserColumn: $creditsUserColumn,
            creditsBalanceColumn: $creditsBalanceColumn,
            blockingIssues: array_values(array_unique($issues)),
            notes: array_values(array_unique($notes)),
            rejectedCandidates: $rejected,
        );
    }

    /**
     * @param  array<string, LegacyDumpTable>  $tables
     * @param  array<string, string|null>  $overrides
     * @param  array<int, string>  $issues
     * @param  array<int, array{table: string, reason: string}>  $rejected
     */
    private function resolveUserTable(array $tables, array $overrides, array &$issues, array &$rejected): ?LegacyDumpTable
    {
        $override = $this->override($overrides, 'users_table');

        if ($override !== null) {
            $table = $tables[mb_strtolower($override)] ?? null;

            if ($table === null) {
                $issues[] = "A tabela de usuários informada não existe no dump: {$override}.";
            }

            return $table;
        }

        $candidates = [];

        foreach ($tables as $table) {
            if ($table->findColumn(self::PASSWORD_COLUMNS) === null) {
                continue;
            }

            if ($table->findColumn(self::EMAIL_COLUMNS) === null) {
                $rejected[] = [
                    'table' => $table->name,
                    'reason' => 'possui coluna de senha, mas nenhuma coluna de e-mail; o Arcana autentica por e-mail.',
                ];

                continue;
            }

            $candidates[] = [
                'table' => $table,
                'score' => $this->userTableScore($table),
            ];
        }

        if ($candidates === []) {
            $issues[] = 'Nenhuma tabela com e-mail e senha foi encontrada no dump; não existem credenciais para importar.';

            return null;
        }

        usort($candidates, fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        $best = $candidates[0];

        if (count($candidates) > 1 && $candidates[1]['score'] === $best['score']) {
            $names = implode(', ', array_map(
                fn (array $candidate): string => $candidate['table']->name,
                $candidates,
            ));

            $issues[] = "Mais de uma tabela parece conter credenciais ({$names}); informe --users-table.";

            return null;
        }

        return $best['table'];
    }

    private function userTableScore(LegacyDumpTable $table): int
    {
        $score = 10;

        if ($this->matchesHint($table->name, self::USER_TABLE_HINTS)) {
            $score += 3;
        }

        if ($table->findColumn(self::NAME_COLUMNS) !== null) {
            $score += 2;
        }

        if ($table->findColumn(self::PHONE_COLUMNS) !== null) {
            $score += 1;
        }

        if ($table->findColumn(self::KEY_COLUMNS) !== null) {
            $score += 1;
        }

        return $score;
    }

    /**
     * @param  array<string, string|null>  $overrides
     * @param  array<int, string>  $candidates
     * @param  array<int, string>  $issues
     */
    private function resolveColumn(
        array $overrides,
        string $overrideKey,
        ?LegacyDumpTable $table,
        array $candidates,
        string $label,
        bool $required,
        array &$issues,
    ): ?string {
        $override = $this->override($overrides, $overrideKey);

        if ($override !== null) {
            if (! $this->isIdentifier($override)) {
                $issues[] = "O nome informado para {$label} não é um identificador válido: {$override}.";

                return null;
            }

            if ($table === null) {
                $issues[] = "Não é possível usar a coluna {$label} ({$override}) sem uma tabela de usuários.";

                return null;
            }

            $column = $table->column($override);

            if ($column === null) {
                $issues[] = "A coluna de {$label} informada ({$override}) não existe em {$table->name}.";

                return null;
            }

            return $column->name;
        }

        if ($table === null) {
            return null;
        }

        $column = $table->findColumn($candidates);

        if ($column === null && $required) {
            $issues[] = "A tabela {$table->name} não possui coluna de {$label}.";
        }

        return $column?->name;
    }

    /**
     * @param  array<string, LegacyDumpTable>  $tables
     * @param  array<string, string|null>  $overrides
     * @param  array<int, string>  $issues
     * @param  array<int, string>  $notes
     * @param  array<int, array{table: string, reason: string}>  $rejected
     * @return array{0: LegacyDumpTable|null, 1: string|null, 2: string|null}
     */
    private function resolveCredits(
        array $tables,
        array $overrides,
        ?LegacyDumpTable $userTable,
        ?string $keyColumn,
        array &$issues,
        array &$notes,
        array &$rejected,
    ): array {
        $override = $this->override($overrides, 'credits_table');

        if ($override !== null && in_array(mb_strtolower($override), ['none', 'nenhum', 'nao', 'não', '-'], true)) {
            $notes[] = 'Importação de saldos desativada explicitamente (--credits-table=none).';

            return [null, null, null];
        }

        if ($override !== null) {
            $table = $tables[mb_strtolower($override)] ?? null;

            if ($table === null) {
                $issues[] = "A tabela de saldos informada não existe no dump: {$override}.";

                return [null, null, null];
            }

            $balance = $this->resolveBalanceColumn($overrides, $table, $issues);
            $reference = $this->resolveColumn($overrides, 'credits_user_column', $table, self::USER_REFERENCE_COLUMNS, 'vínculo com o usuário', false, $issues);

            if ($reference === null && $userTable !== null && $table->is($userTable)) {
                $reference = $keyColumn;
            }

            if ($balance === null) {
                $issues[] = "A tabela de saldos {$table->name} não possui coluna de saldo; informe --credits-balance-column.";

                return [null, null, null];
            }

            if ($reference === null) {
                $issues[] = "A tabela de saldos {$table->name} não possui coluna de vínculo com o usuário; informe --credits-user-column.";

                return [null, null, null];
            }

            return [$table, $reference, $balance->name];
        }

        if ($userTable !== null && $keyColumn !== null) {
            $balance = $this->detectBalanceColumn($userTable);

            if ($balance !== null) {
                return [$userTable, $keyColumn, $balance->name];
            }
        }

        $candidates = [];

        foreach ($tables as $table) {
            if ($userTable !== null && $table->is($userTable)) {
                continue;
            }

            $balance = $this->detectBalanceColumn($table);

            if ($balance === null) {
                continue;
            }

            $reference = $table->findColumn(self::USER_REFERENCE_COLUMNS);

            if ($reference === null) {
                $rejected[] = [
                    'table' => $table->name,
                    'reason' => "possui coluna de saldo ({$balance->name}), mas nenhuma coluna que referencie o usuário.",
                ];

                continue;
            }

            $candidates[] = [$table, $reference->name, $balance->name];
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        if (count($candidates) > 1) {
            $names = implode(', ', array_map(fn (array $candidate): string => $candidate[0]->name, $candidates));

            $issues[] = "Mais de uma origem de saldo encontrada ({$names}); informe --credits-table.";

            return [null, null, null];
        }

        $notes[] = 'Nenhuma coluna de saldo foi encontrada no dump; os usuários serão importados apenas com identificação e senha.';

        return [null, null, null];
    }

    /**
     * @param  array<string, string|null>  $overrides
     * @param  array<int, string>  $issues
     */
    private function resolveBalanceColumn(array $overrides, LegacyDumpTable $table, array &$issues): ?LegacyDumpColumn
    {
        $override = $this->override($overrides, 'credits_balance_column');

        if ($override === null) {
            return $this->detectBalanceColumn($table);
        }

        if (! $this->isIdentifier($override)) {
            $issues[] = "O nome informado para a coluna de saldo não é um identificador válido: {$override}.";

            return null;
        }

        $column = $table->column($override);

        if ($column === null) {
            $issues[] = "A coluna de saldo informada ({$override}) não existe em {$table->name}.";
        }

        return $column;
    }

    private function detectBalanceColumn(LegacyDumpTable $table): ?LegacyDumpColumn
    {
        foreach ($table->columns as $column) {
            if (! $column->isNumeric()) {
                continue;
            }

            $name = strtolower($column->name);

            if (in_array($name, self::CREDIT_IGNORED_COLUMNS, true)) {
                continue;
            }

            if (in_array($name, self::CREDIT_COLUMNS, true) || str_starts_with($name, 'saldo_')) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $hints
     */
    private function matchesHint(string $name, array $hints): bool
    {
        $name = mb_strtolower($name);

        foreach ($hints as $hint) {
            if (str_contains($name, $hint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string|null>  $overrides
     */
    private function override(array $overrides, string $key): ?string
    {
        $value = $overrides[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function isIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_$]{1,64}$/', $value) === 1;
    }
}
