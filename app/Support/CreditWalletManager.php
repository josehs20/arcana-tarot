<?php

namespace App\Support;

use App\Enums\LedgerEntryType;
use App\Models\AuditLog;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Operações financeiras internas da carteira de créditos.
 *
 * A carteira é uma projeção do livro-razão: toda alteração de saldo nasce de
 * um lançamento imutável. O saldo nunca é recebido do navegador e não pode
 * ficar negativo.
 */
final class CreditWalletManager
{
    /**
     * Retorna a carteira do usuário, criando-a vazia quando necessário.
     */
    public function walletFor(User $user): Wallet
    {
        if ($user->getKey() === null) {
            throw new InvalidArgumentException('A carteira exige um usuário persistido.');
        }

        return Wallet::query()->firstOrCreate(
            ['user_id' => $user->getKey()],
            ['balance_cents' => 0],
        );
    }

    /**
     * Credita um valor em centavos. A chave de idempotência é única e repetir
     * a mesma operação retorna o lançamento já existente.
     */
    public function credit(
        User $user,
        int $amountCents,
        string $idempotencyKey,
        Model $reference,
    ): CreditLedgerEntry {
        return $this->record($user, LedgerEntryType::Credit, $amountCents, $idempotencyKey, $reference);
    }

    /**
     * Debita um valor em centavos, recusando a operação quando o saldo for
     * insuficiente. O mesmo identificador idempotente não debita duas vezes.
     */
    public function debit(
        User $user,
        int $amountCents,
        string $idempotencyKey,
        Model $reference,
        ?string $reason = null,
    ): CreditLedgerEntry {
        return $this->record($user, LedgerEntryType::Debit, $amountCents, $idempotencyKey, $reference, $reason);
    }

    /**
     * Ajuste administrativo: operação separada de crédito/débito comuns para
     * exigir autor, motivo e auditoria. O `operationId` do formulário compõe a
     * chave idempotente, impedindo duplicação em duplo clique/reenvio.
     */
    public function adjust(
        User $target,
        User $actor,
        LedgerEntryType $type,
        int $amountCents,
        string $reason,
        string $idempotencyKey,
    ): CreditLedgerEntry {
        if (! $actor->is_active || ! $actor->isAdmin()) {
            throw new DomainException('Somente administradores ativos podem ajustar carteiras.');
        }

        if (! $target->isClient()) {
            throw new DomainException('A carteira de créditos é exclusiva de clientes.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('O motivo do ajuste é obrigatório.');
        }

        $this->validateAmount($amountCents);
        $this->validateKey($idempotencyKey);

        return DB::transaction(function () use ($target, $actor, $type, $amountCents, $reason, $idempotencyKey): CreditLedgerEntry {
            $wallet = $this->lockWallet($target);

            $existing = $this->existingEntry($wallet, $type, $amountCents, $idempotencyKey);

            if ($existing !== null) {
                return $existing;
            }

            [$balanceBefore, $balanceAfter] = $this->applyBalanceChange($wallet, $type, $amountCents);

            $audit = AuditLog::query()->create([
                'actor_id' => $actor->getKey(),
                'action' => 'wallet.adjustment',
                'subject_type' => $wallet->getMorphClass(),
                'subject_id' => $wallet->getKey(),
                'metadata' => [
                    'target_user_id' => $target->getKey(),
                    'type' => $type->value,
                    'amount_cents' => $amountCents,
                    'balance_before_cents' => $balanceBefore,
                    'balance_after_cents' => $balanceAfter,
                    'reason' => $reason,
                    'idempotency_key' => $idempotencyKey,
                ],
            ]);

            return $this->persistEntry(
                $wallet,
                $type,
                $amountCents,
                $balanceAfter,
                $idempotencyKey,
                $audit,
                $reason,
                $actor,
            );
        });
    }

    /**
     * Saldo derivado exclusivamente do livro-razão. Útil para conferência e
     * para demonstrar que `wallets.balance_cents` é apenas uma projeção.
     */
    public function ledgerBalance(Wallet $wallet): int
    {
        $credits = (int) CreditLedgerEntry::query()
            ->where('wallet_id', $wallet->getKey())
            ->where('type', LedgerEntryType::Credit->value)
            ->sum('amount_cents');

        $debits = (int) CreditLedgerEntry::query()
            ->where('wallet_id', $wallet->getKey())
            ->where('type', LedgerEntryType::Debit->value)
            ->sum('amount_cents');

        return $credits - $debits;
    }

    private function record(
        User $user,
        LedgerEntryType $type,
        int $amountCents,
        string $idempotencyKey,
        Model $reference,
        ?string $reason = null,
    ): CreditLedgerEntry {
        $this->validateAmount($amountCents);
        $this->validateKey($idempotencyKey);

        if (! $reference->exists) {
            throw new InvalidArgumentException('A referência do lançamento precisa estar persistida.');
        }

        return DB::transaction(function () use ($user, $type, $amountCents, $idempotencyKey, $reference, $reason): CreditLedgerEntry {
            $wallet = $this->lockWallet($user);

            $existing = $this->existingEntry($wallet, $type, $amountCents, $idempotencyKey);

            if ($existing !== null) {
                return $existing;
            }

            [, $balanceAfter] = $this->applyBalanceChange($wallet, $type, $amountCents);

            return $this->persistEntry(
                $wallet,
                $type,
                $amountCents,
                $balanceAfter,
                $idempotencyKey,
                $reference,
                $reason,
            );
        });
    }

    /**
     * Bloqueia a linha da carteira antes de ler o saldo. O UPDATE condicional
     * de débito é uma segunda barreira: mesmo em bancos onde o lock é no-op
     * (SQLite em testes), o saldo não fica negativo.
     *
     * @return array{0: int, 1: int} [saldo anterior, saldo posterior]
     */
    private function applyBalanceChange(Wallet $wallet, LedgerEntryType $type, int $amountCents): array
    {
        if ($type === LedgerEntryType::Credit) {
            Wallet::query()->whereKey($wallet->getKey())->increment('balance_cents', $amountCents);
            $wallet->refresh();

            return [$wallet->balance_cents - $amountCents, $wallet->balance_cents];
        }

        $affected = Wallet::query()
            ->whereKey($wallet->getKey())
            ->where('balance_cents', '>=', $amountCents)
            ->decrement('balance_cents', $amountCents);

        if ($affected === 0) {
            $wallet->refresh();

            throw new InsufficientCreditBalanceException($amountCents, $wallet->balance_cents);
        }

        $wallet->refresh();

        return [$wallet->balance_cents + $amountCents, $wallet->balance_cents];
    }

    private function lockWallet(User $user): Wallet
    {
        $wallet = $this->walletFor($user);

        return Wallet::query()->lockForUpdate()->findOrFail($wallet->getKey());
    }

    private function existingEntry(
        Wallet $wallet,
        LedgerEntryType $type,
        int $amountCents,
        string $idempotencyKey,
    ): ?CreditLedgerEntry {
        $entry = CreditLedgerEntry::query()
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($entry === null) {
            return null;
        }

        if (
            $entry->wallet_id !== $wallet->getKey()
            || $entry->type !== $type
            || $entry->amount_cents !== $amountCents
        ) {
            throw new DomainException(
                'Esta chave de idempotência já foi usada com outra carteira, tipo ou valor.',
            );
        }

        return $entry;
    }

    private function persistEntry(
        Wallet $wallet,
        LedgerEntryType $type,
        int $amountCents,
        int $balanceAfterCents,
        string $idempotencyKey,
        Model $reference,
        ?string $reason = null,
        ?User $actor = null,
    ): CreditLedgerEntry {
        return CreditLedgerEntry::query()->create([
            'wallet_id' => $wallet->getKey(),
            'type' => $type->value,
            'amount_cents' => $amountCents,
            'balance_after_cents' => $balanceAfterCents,
            'reference_type' => $reference->getMorphClass(),
            'reference_id' => $reference->getKey(),
            'reason' => $reason,
            'created_by' => $actor?->getKey(),
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    private function validateAmount(int $amountCents): void
    {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('O valor do lançamento deve ser positivo e em centavos.');
        }
    }

    private function validateKey(string $idempotencyKey): void
    {
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('A chave de idempotência é obrigatória.');
        }
    }
}
