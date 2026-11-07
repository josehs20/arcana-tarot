<?php

namespace App\Support\Legacy;

use App\Enums\Gender;
use App\Enums\LegacyImportStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\CreditWalletManager;
use App\Support\PhoneNumber;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Importa usuários do banco legado para o Arcana.
 *
 * Regras de segurança:
 * - o arquivo nunca é executado como SQL, apenas interpretado pelo
 *   `LegacyDumpSource`;
 * - senhas só são preservadas quando o Laravel consegue verificá-las
 *   (bcrypt/argon2 com configuração compatível); qualquer outro formato gera
 *   uma senha inutilizável e exige redefinição pelo titular;
 * - nenhum valor de senha, hash ou credencial é gravado em log ou relatório;
 * - cada usuário é gravado em uma transação própria: uma linha inválida não
 *   desfaz as anteriores.
 */
final class LegacyUserImporter
{
    public function __construct(private readonly CreditWalletManager $wallets) {}

    public function plan(LegacyDumpSource $reader): LegacyImportPlan
    {
        return (new LegacySchemaInspector)->inspect($reader->tables(), $reader->sourceLabel());
    }

    /**
     * Percorre o dump gerando o resultado de cada registro. Sem `$execute`
     * nada é gravado: apenas consultas de conflito são feitas.
     *
     * @return Generator<int, LegacyImportEntry>
     */
    public function import(
        LegacyDumpSource $reader,
        LegacyImportPlan $plan,
        bool $execute,
        ?int $limit = null,
    ): Generator {
        if (! $plan->isReady() || $plan->userTable === null) {
            throw new LogicException('O plano de importação possui problemas bloqueantes.');
        }

        $userTable = $plan->userTable;
        $mapper = new LegacyUserRecordMapper($plan);
        $balances = $this->creditBalances($reader, $plan, $mapper);
        $profiles = $this->profileData($reader, $plan);
        $excludedKeys = $this->excludedKeys($reader, $plan);
        $existingEmails = $this->existingEmails();
        $processedEmails = [];
        $processed = 0;

        foreach ($reader->rows($userTable) as $raw) {
            if ($limit !== null && $processed >= $limit) {
                return;
            }

            $processed++;

            $row = LegacyRow::from($raw, $userTable);
            $legacyKey = $row->text($plan->legacyKeyColumn);
            $mapped = $mapper->map($row, $balances[$legacyKey] ?? 0, $profiles[$this->profileKey($row, $plan)] ?? null);

            if (is_string($mapped)) {
                yield new LegacyImportEntry(
                    legacyKey: $legacyKey,
                    status: LegacyImportStatus::Skipped,
                    reason: $mapped,
                );

                continue;
            }

            if (isset($excludedKeys[mb_strtolower($legacyKey)])) {
                yield $this->entryFor(
                    $mapped,
                    LegacyImportStatus::Skipped,
                    'usuário presente em '.$plan->excludeTable?->name.': importar pelo fluxo próprio dessa origem',
                );

                continue;
            }

            if (isset($processedEmails[$mapped->email])) {
                yield $this->entryFor($mapped, LegacyImportStatus::Conflict, 'e-mail repetido no dump');

                continue;
            }

            $processedEmails[$mapped->email] = true;

            if (isset($existingEmails[$mapped->email])) {
                yield $this->entryFor($mapped, LegacyImportStatus::Conflict, 'e-mail já cadastrado no Arcana');

                continue;
            }

            if (! $execute) {
                yield $this->entryFor($mapped, LegacyImportStatus::Planned, $this->balanceNote($mapped));

                continue;
            }

            $entry = $this->persist($mapped, $userTable->name);

            if ($entry->isSuccess()) {
                $existingEmails[$mapped->email] = true;
            }

            yield $entry;
        }
    }

    /**
     * Chave idempotente do lançamento de abertura de saldo.
     */
    public function idempotencyKey(LegacyUserRecord $record, string $sourceTable): string
    {
        return 'legacy-import:'.mb_strtolower($sourceTable).':'.$record->legacyKey;
    }

    private function persist(LegacyUserRecord $record, string $sourceTable): LegacyImportEntry
    {
        [$password, $reused] = $this->passwordFor($record);

        try {
            $user = DB::transaction(function () use ($record, $sourceTable, $password, $reused): User {
                $user = User::query()->create([
                    'name' => $record->name,
                    'email' => $record->email,
                    'phone' => $record->phone,
                    'birth_date' => $record->birthDate,
                    'gender' => $record->gender?->value,
                    'password' => $password,
                    'role' => UserRole::Client->value,
                    'is_active' => true,
                ]);

                $audit = AuditLog::query()->create([
                    'actor_id' => null,
                    'action' => 'user.imported_from_legacy',
                    'subject_type' => $user->getMorphClass(),
                    'subject_id' => $user->getKey(),
                    'metadata' => [
                        'source_table' => $sourceTable,
                        'legacy_key' => $record->legacyKey,
                        'password_format' => $record->passwordFormat->value,
                        'password_reused' => $reused,
                        'credits_cents' => max(0, $record->balanceCents),
                        'birth_date' => $record->birthDate,
                        'gender' => $record->gender?->value,
                        'profile_matched' => $record->profileMatched,
                    ],
                ]);

                $this->wallets->walletFor($user);

                if ($record->balanceCents > 0) {
                    $this->wallets->credit(
                        $user,
                        $record->balanceCents,
                        $this->idempotencyKey($record, $sourceTable),
                        $audit,
                    );
                }

                return $user;
            });
        } catch (Throwable $exception) {
            report($exception);

            return $this->entryFor(
                $record,
                LegacyImportStatus::Failed,
                'falha inesperada ('.class_basename($exception).'); consulte os logs',
            );
        }

        return new LegacyImportEntry(
            legacyKey: $record->legacyKey,
            status: LegacyImportStatus::Imported,
            passwordFormat: $record->passwordFormat,
            email: $record->email,
            name: $record->name,
            balanceCents: $record->balanceCents,
            reason: $this->balanceNote($record),
            userId: (int) $user->getKey(),
            birthDate: $record->birthDate,
            gender: $record->gender,
            profileMatched: $record->profileMatched,
        );
    }

    private function entryFor(LegacyUserRecord $record, LegacyImportStatus $status, ?string $reason): LegacyImportEntry
    {
        return new LegacyImportEntry(
            legacyKey: $record->legacyKey,
            status: $status,
            passwordFormat: $record->passwordFormat,
            email: $record->email,
            name: $record->name,
            balanceCents: $record->balanceCents,
            reason: $reason,
            birthDate: $record->birthDate,
            gender: $record->gender,
            profileMatched: $record->profileMatched,
        );
    }

    /**
     * @return array{0: string, 1: bool} [valor a gravar, hash legado reaproveitado]
     */
    private function passwordFor(LegacyUserRecord $record): array
    {
        if ($record->passwordFormat->isLaravelCompatible() && $this->isVerifiable($record->passwordValue)) {
            return [$record->passwordValue, true];
        }

        return [Hash::make(Str::random(64)), false];
    }

    /**
     * Confirma com o próprio Laravel que o hash pode ser gravado: um hash com
     * custo/configuração diferente da atual levantaria erro no cast `hashed`.
     */
    private function isVerifiable(string $hash): bool
    {
        $info = Hash::info($hash);

        if (($info['algo'] ?? null) === null) {
            return false;
        }

        return Hash::verifyConfiguration($hash);
    }

    /**
     * @return array<string, true>
     */
    private function existingEmails(): array
    {
        /** @var array<string, true> $emails */
        $emails = [];

        foreach (User::query()->pluck('email') as $email) {
            $emails[mb_strtolower((string) $email)] = true;
        }

        return $emails;
    }

    /**
     * Saldos de uma tabela de créditos separada, indexados pela chave legada
     * do usuário. Como a coluna representa um saldo (e não cada movimento), o
     * último registro de cada usuário prevalece.
     *
     * @return array<string, int>
     */
    private function creditBalances(
        LegacyDumpSource $reader,
        LegacyImportPlan $plan,
        LegacyUserRecordMapper $mapper,
    ): array {
        if (
            ! $plan->hasCredits()
            || $plan->userTable === null
            || $plan->creditsTable->is($plan->userTable)
        ) {
            return [];
        }

        $balances = [];

        foreach ($reader->rows($plan->creditsTable) as $raw) {
            $row = LegacyRow::from($raw, $plan->creditsTable);
            $key = $row->text($plan->creditsUserColumn);

            if ($key === '') {
                continue;
            }

            $cents = $mapper->parseCents($row->get($plan->creditsBalanceColumn));

            if ($cents !== null) {
                $balances[$key] = $cents;
            }
        }

        return $balances;
    }

    /**
     * Dados complementares (telefone, nascimento e sexo) indexados pela coluna
     * de vínculo da tabela de perfil.
     *
     * Uma tabela de dados complementares guarda um registro por usuário; se o
     * legado tiver duplicados, o último prevalece — a mesma regra dos saldos.
     *
     * @return array<string, LegacyProfileRecord>
     */
    private function profileData(LegacyDumpSource $reader, LegacyImportPlan $plan): array
    {
        if (! $plan->hasProfile() || $plan->profileTable === null) {
            return [];
        }

        $profiles = [];

        foreach ($reader->rows($plan->profileTable) as $raw) {
            $row = LegacyRow::from($raw, $plan->profileTable);
            $key = mb_strtolower($row->text($plan->profileUserColumn));

            if ($key === '') {
                continue;
            }

            $phone = PhoneNumber::normalize($row->get($plan->profilePhoneColumn));

            $profiles[$key] = new LegacyProfileRecord(
                phone: PhoneNumber::isValid($phone) ? $phone : null,
                birthDate: LegacyDateParser::parse($row->get($plan->profileBirthDateColumn)),
                gender: Gender::fromLegacyLabel($row->get($plan->profileGenderColumn)),
            );
        }

        return $profiles;
    }

    /**
     * Valor usado para procurar os dados complementares do usuário.
     *
     * Quando a tabela de usuários tem a mesma coluna de vínculo usada em
     * `eam_clientes` (por exemplo, `email`), a busca usa esse valor; caso
     * contrário usa a chave legada do usuário (`id`), que é o formato usual do
     * vínculo.
     */
    private function profileKey(LegacyRow $row, LegacyImportPlan $plan): string
    {
        if ($plan->profileUserColumn !== null && $row->get($plan->profileUserColumn) !== null) {
            return mb_strtolower($row->text($plan->profileUserColumn));
        }

        return mb_strtolower($row->text($plan->legacyKeyColumn));
    }

    /**
     * Chaves legadas que não devem virar conta — no banco real, os usuários
     * presentes em `eam_atendentes`.
     *
     * @return array<string, true>
     */
    private function excludedKeys(LegacyDumpSource $reader, LegacyImportPlan $plan): array
    {
        if ($plan->excludeTable === null || $plan->excludeUserColumn === null) {
            return [];
        }

        $keys = [];

        foreach ($reader->rows($plan->excludeTable) as $raw) {
            $row = LegacyRow::from($raw, $plan->excludeTable);
            $key = $row->text($plan->excludeUserColumn);

            if ($key !== '') {
                $keys[mb_strtolower($key)] = true;
            }
        }

        return $keys;
    }

    private function balanceNote(LegacyUserRecord $record): ?string
    {
        return $record->balanceCents < 0 ? 'saldo negativo no legado: nenhum crédito será lançado' : null;
    }
}
