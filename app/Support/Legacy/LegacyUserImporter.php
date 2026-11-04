<?php

namespace App\Support\Legacy;

use App\Enums\LegacyImportStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\CreditWalletManager;
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
 * - o dump nunca é executado como SQL, apenas interpretado pelo
 *   `LegacySqlDumpReader`;
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

    /**
     * @param  array<string, string|null>  $mapping
     */
    public function plan(LegacySqlDumpReader $reader, array $mapping = []): LegacyImportPlan
    {
        return (new LegacySchemaInspector)->inspect($reader->tables(), $mapping);
    }

    /**
     * Percorre o dump gerando o resultado de cada registro. Sem `$execute`
     * nada é gravado: apenas consultas de conflito são feitas.
     *
     * @return Generator<int, LegacyImportEntry>
     */
    public function import(
        LegacySqlDumpReader $reader,
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
            $mapped = $mapper->map($row, $balances[$legacyKey] ?? 0);

            if (is_string($mapped)) {
                yield new LegacyImportEntry(
                    legacyKey: $legacyKey,
                    status: LegacyImportStatus::Skipped,
                    reason: $mapped,
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
        LegacySqlDumpReader $reader,
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

    private function balanceNote(LegacyUserRecord $record): ?string
    {
        return $record->balanceCents < 0 ? 'saldo negativo no legado: nenhum crédito será lançado' : null;
    }
}
