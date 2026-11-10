<?php

namespace App\Support\Legacy;

/**
 * Traduz a estrutura do dump legado para o `LegacyImportPlan`.
 *
 * As tabelas vêm do `LegacySchemaPreset`: a migração é de um banco único, então
 * elas não são adivinhadas nem configuráveis. Dentro de cada tabela as colunas
 * são identificadas por heurística conservadora — o que não tem nome conhecido
 * fica de fora e o relatório explica o motivo.
 *
 * Nenhum nome é interpolado em SQL: o plano é apenas descritivo e o importador
 * lê o dump pelos nomes exatos declarados nele.
 */
final class LegacySchemaInspector
{
    private const EMAIL_COLUMNS = ['email', 'e_mail', 'email_principal', 'mail', 'correio', 'user_email'];

    private const PASSWORD_COLUMNS = ['senha', 'password', 'senha_hash', 'password_hash', 'passwd', 'pwd', 'hash', 'pass'];

    private const NAME_COLUMNS = ['nome', 'name', 'nome_completo', 'display_name', 'apelido'];

    private const PHONE_COLUMNS = ['telefone', 'phone', 'celular', 'fone', 'whatsapp'];

    private const KEY_COLUMNS = ['id', 'codigo', 'cod', 'cod_usuario', 'user_id', 'usuario_id'];

    private const USER_REFERENCE_COLUMNS = ['usuario_id', 'user_id', 'id_usuario', 'id_user', 'cliente_id', 'id_cliente'];

    /**
     * Vínculo dos dados complementares. Aceita e-mail porque o legado pode não
     * ter coluna de id do usuário em `eam_clientes`.
     */
    private const PROFILE_REFERENCE_COLUMNS = [
        'usuario_id', 'user_id', 'id_usuario', 'id_user', 'cliente_id', 'id_cliente', 'email', 'e_mail', 'mail',
    ];

    private const CREDIT_COLUMNS = ['saldo', 'saldo_atual', 'saldo_creditos', 'creditos', 'credito', 'credit', 'credits', 'balance', 'balance_cents'];

    private const CREDIT_IGNORED_COLUMNS = ['comissao', 'valor_minuto', 'preco', 'price', 'taxa', 'desconto'];

    private const BIRTH_DATE_COLUMNS = ['data_nascimento', 'nascimento', 'dt_nascimento', 'data_nasc', 'birth_date', 'birthdate', 'data_de_nascimento'];

    private const GENDER_COLUMNS = ['sexo', 'genero', 'gênero', 'gender', 'sex'];

    /**
     * @param  array<string, LegacyDumpTable>  $tables
     * @param  string  $source  descrição do arquivo ("dump SQL", "arquivo CSV")
     */
    public function inspect(array $tables, string $source = 'dump'): LegacyImportPlan
    {
        $issues = [];
        $notes = [];
        $rejected = [];

        $userTable = $this->find($tables, LegacySchemaPreset::USERS_TABLE);

        if ($userTable === null) {
            $issues[] = 'A tabela de usuários do legado ('.LegacySchemaPreset::USERS_TABLE.') não existe no '.$source.'; exporte essa tabela para que as credenciais estejam disponíveis.';
        }

        $emailColumn = $this->columnName($userTable, self::EMAIL_COLUMNS);
        $passwordColumn = $this->columnName($userTable, self::PASSWORD_COLUMNS);
        $nameColumn = $this->columnName($userTable, self::NAME_COLUMNS);
        $phoneColumn = $this->columnName($userTable, self::PHONE_COLUMNS);
        $keyColumn = $this->columnName($userTable, self::KEY_COLUMNS);

        if ($userTable !== null && $emailColumn === null) {
            $issues[] = "A tabela {$userTable->name} não possui coluna de e-mail; o Arcana autentica por e-mail.";
        }

        if ($userTable !== null && $passwordColumn === null) {
            $issues[] = "A tabela {$userTable->name} não possui coluna de senha; nenhum usuário poderá ser importado com credencial.";
        }

        if ($userTable !== null && $keyColumn === null) {
            $notes[] = 'A tabela de usuários não possui coluna identificadora; a chave de idempotência usará o e-mail.';
        }

        [$creditsTable, $creditsUserColumn, $creditsBalanceColumn] = $this->resolveCredits(
            $tables,
            $userTable,
            $keyColumn,
            $source,
            $notes,
            $rejected,
        );

        if ($creditsTable !== null && $keyColumn === null) {
            $issues[] = 'Não é possível relacionar os saldos com os usuários sem uma coluna identificadora na tabela de usuários.';
        }

        [$profileTable, $profileUserColumn, $profilePhoneColumn, $profileBirthDateColumn, $profileGenderColumn] = $this->resolveProfile(
            $tables,
            $userTable,
            $phoneColumn,
            $source,
            $notes,
        );

        [$excludeTable, $excludeUserColumn] = $this->resolveExclusion($tables, $source, $notes);

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
            profileTable: $profileTable,
            profileUserColumn: $profileUserColumn,
            profilePhoneColumn: $profilePhoneColumn,
            profileBirthDateColumn: $profileBirthDateColumn,
            profileGenderColumn: $profileGenderColumn,
            excludeTable: $excludeTable,
            excludeUserColumn: $excludeUserColumn,
            blockingIssues: $issues,
            notes: $notes,
            rejectedCandidates: $rejected,
        );
    }

    /**
     * Tabela do dump pelo nome fixo do preset; nomes do dump são normalizados
     * em minúsculas pelo `LegacySqlDumpReader`.
     *
     * @param  array<string, LegacyDumpTable>  $tables
     */
    private function find(array $tables, string $name): ?LegacyDumpTable
    {
        return $tables[mb_strtolower($name)] ?? null;
    }

    /**
     * Primeira coluna encontrada seguindo a prioridade dos nomes candidatos.
     *
     * @param  array<int, string>  $candidates
     */
    private function columnName(?LegacyDumpTable $table, array $candidates): ?string
    {
        return $table?->findColumn($candidates)?->name;
    }

    /**
     * Origem dos saldos: a própria tabela de usuários quando ela tem coluna de
     * saldo; caso contrário, a única outra tabela do dump com saldo e vínculo de
     * usuário. Ambiguidade não bloqueia: os usuários entram sem saldo e o
     * relatório avisa.
     *
     * @param  array<string, LegacyDumpTable>  $tables
     * @param  string  $source  descrição do arquivo ("dump SQL", "arquivo CSV")
     * @param  array<int, string>  $notes
     * @param  array<int, array{table: string, reason: string}>  $rejected
     * @return array{0: LegacyDumpTable|null, 1: string|null, 2: string|null}
     */
    private function resolveCredits(
        array $tables,
        ?LegacyDumpTable $userTable,
        ?string $keyColumn,
        string $source,
        array &$notes,
        array &$rejected,
    ): array {
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

            $notes[] = "Mais de uma origem de saldo encontrada ({$names}); os saldos não serão importados.";

            return [null, null, null];
        }

        $notes[] = 'Nenhuma coluna de saldo foi encontrada no '.$source.'; os usuários serão importados apenas com identificação e senha.';

        return [null, null, null];
    }

    /**
     * Dados complementares do usuário (telefone, nascimento e sexo) que o legado
     * guarda em `eam_clientes`. Tabela, vínculo ou colunas ausentes não bloqueiam
     * a importação: os campos ficam vazios e o relatório avisa.
     *
     * @param  array<string, LegacyDumpTable>  $tables
     * @param  string  $source  descrição do arquivo ("dump SQL", "arquivo CSV")
     * @param  array<int, string>  $notes
     * @return array{0: LegacyDumpTable|null, 1: string|null, 2: string|null, 3: string|null, 4: string|null}
     */
    private function resolveProfile(
        array $tables,
        ?LegacyDumpTable $userTable,
        ?string $userPhoneColumn,
        string $source,
        array &$notes,
    ): array {
        $table = $this->find($tables, LegacySchemaPreset::PROFILE_TABLE);

        if ($table === null) {
            $notes[] = 'A tabela de dados complementares ('.LegacySchemaPreset::PROFILE_TABLE.') não existe no '.$source.'; telefone, nascimento e sexo não serão importados.';

            return [null, null, null, null, null];
        }

        if ($userTable !== null && $table->is($userTable)) {
            $notes[] = "A tabela de dados complementares ({$table->name}) é a mesma tabela dos usuários; nenhum dado complementar será importado.";

            return [null, null, null, null, null];
        }

        $reference = $this->columnName($table, self::PROFILE_REFERENCE_COLUMNS);

        if ($reference === null) {
            $notes[] = "A tabela de dados complementares {$table->name} não possui coluna de vínculo com o usuário; nenhum dado complementar será importado.";

            return [null, null, null, null, null];
        }

        $phone = $this->columnName($table, self::PHONE_COLUMNS);
        $birthDate = $this->columnName($table, self::BIRTH_DATE_COLUMNS);
        $gender = $this->columnName($table, self::GENDER_COLUMNS);

        if ($phone === null && $birthDate === null && $gender === null) {
            $notes[] = "A tabela de dados complementares {$table->name} não possui colunas de telefone, nascimento ou sexo; nenhum dado complementar será importado.";

            return [null, null, null, null, null];
        }

        $missing = array_keys(array_filter([
            'telefone' => $phone === null && $userPhoneColumn === null,
            'data de nascimento' => $birthDate === null,
            'sexo' => $gender === null,
        ]));

        if ($missing !== []) {
            $notes[] = "A tabela de dados complementares {$table->name} não possui ".implode(', ', $missing).'; os clientes importados ficarão sem esses dados.';
        }

        return [$table, $reference, $phone, $birthDate, $gender];
    }

    /**
     * Contas que não devem virar cliente — no legado, quem está em
     * `eam_atendentes` tem fluxo próprio de cadastro. Tabela ou vínculo ausentes
     * apenas avisam.
     *
     * @param  array<string, LegacyDumpTable>  $tables
     * @param  string  $source  descrição do arquivo ("dump SQL", "arquivo CSV")
     * @param  array<int, string>  $notes
     * @return array{0: LegacyDumpTable|null, 1: string|null}
     */
    private function resolveExclusion(array $tables, string $source, array &$notes): array
    {
        $table = $this->find($tables, LegacySchemaPreset::EXCLUDE_TABLE);

        if ($table === null) {
            $notes[] = 'A tabela de exclusão ('.LegacySchemaPreset::EXCLUDE_TABLE.') não existe no '.$source.'; nenhuma conta será ignorada por esse critério.';

            return [null, null];
        }

        $reference = $this->columnName($table, self::USER_REFERENCE_COLUMNS);

        if ($reference === null) {
            $notes[] = "A tabela de exclusão {$table->name} não possui coluna de vínculo com o usuário; nenhuma conta será ignorada por esse critério.";

            return [null, null];
        }

        return [$table, $reference];
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
}
