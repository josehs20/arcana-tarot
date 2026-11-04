<?php

namespace App\Support;

use App\Enums\CommissionEntryType;
use App\Enums\ConsultationStatus;
use App\Enums\LedgerEntryType;
use App\Models\AuditLog;
use App\Models\CommissionLedgerEntry;
use App\Models\Consultation;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Razão de comissões por consulta.
 *
 * A comissão original é calculada sobre os débitos efetivamente registrados
 * pelo módulo de cobrança, com a taxa snapshot da consulta. A gravação é
 * idempotente e o lançamento é imutável.
 */
final class CommissionManager
{
    public function __construct(private readonly CommissionCalculator $calculator) {}

    /**
     * Gera a comissão original de uma consulta finalizada. Repetir a operação
     * retorna o lançamento existente sem duplicar valores.
     */
    public function accrue(Consultation $consultation): ?CommissionLedgerEntry
    {
        return DB::transaction(function () use ($consultation): ?CommissionLedgerEntry {
            $locked = Consultation::query()
                ->lockForUpdate()
                ->findOrFail($consultation->getKey());

            return $this->accrueLocked($locked);
        });
    }

    /**
     * Estorna integralmente uma comissão original. O original permanece
     * intacto e o estorno é um novo lançamento com motivo e autor.
     */
    public function reverse(CommissionLedgerEntry $entry, User $admin, string $reason): CommissionLedgerEntry
    {
        if (! $admin->is_active || ! $admin->isAdmin()) {
            throw new DomainException('Somente administradores ativos podem estornar comissões.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('O motivo do estorno é obrigatório.');
        }

        return DB::transaction(function () use ($entry, $admin, $reason): CommissionLedgerEntry {
            $locked = CommissionLedgerEntry::query()
                ->lockForUpdate()
                ->findOrFail($entry->getKey());

            if ($locked->type !== CommissionEntryType::Accrual) {
                throw new DomainException('Somente uma comissão original pode ser estornada.');
            }

            if ($locked->consultation_id === null) {
                throw new DomainException('O lançamento não está vinculado a uma consulta.');
            }

            $existing = CommissionLedgerEntry::query()
                ->where('reversed_entry_id', $locked->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $idempotencyKey = 'consultation:'.$locked->consultation_id.':commission:reversal:'.$locked->getKey();

            $existing = CommissionLedgerEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            try {
                $reversal = CommissionLedgerEntry::query()->create([
                    'attendant_id' => $locked->attendant_id,
                    'consultation_id' => $locked->consultation_id,
                    'reversed_entry_id' => $locked->getKey(),
                    'commission_rate_bps' => $locked->commission_rate_bps,
                    'base_amount_cents' => $locked->base_amount_cents,
                    'commission_amount_cents' => $locked->commission_amount_cents,
                    'type' => CommissionEntryType::Reversal->value,
                    'actor_id' => $admin->getKey(),
                    'reason' => $reason,
                    'idempotency_key' => $idempotencyKey,
                ]);
            } catch (QueryException $exception) {
                $reversal = CommissionLedgerEntry::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($reversal === null) {
                    throw $exception;
                }
            }

            AuditLog::query()->create([
                'actor_id' => $admin->getKey(),
                'action' => 'commission.reversed',
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => $locked->getKey(),
                'metadata' => [
                    'attendant_id' => $locked->attendant_id,
                    'consultation_id' => $locked->consultation_id,
                    'reversal_entry_id' => $reversal->getKey(),
                    'base_amount_cents' => $locked->base_amount_cents,
                    'commission_amount_cents' => $locked->commission_amount_cents,
                    'reason' => $reason,
                ],
            ]);

            return $reversal;
        });
    }

    /**
     * Base oficial: débitos da própria consulta no razão de créditos.
     */
    public function baseAmountCents(Consultation $consultation): int
    {
        if ($consultation->getKey() === null) {
            return 0;
        }

        return (int) CreditLedgerEntry::query()
            ->where('reference_type', $consultation->getMorphClass())
            ->where('reference_id', $consultation->getKey())
            ->where('type', LedgerEntryType::Debit->value)
            ->where('idempotency_key', 'like', 'consultation:'.$consultation->getKey().':minute:%')
            ->sum('amount_cents');
    }

    public function accrualFor(Consultation $consultation): ?CommissionLedgerEntry
    {
        if ($consultation->getKey() === null) {
            return null;
        }

        return CommissionLedgerEntry::query()
            ->where('consultation_id', $consultation->getKey())
            ->where('type', CommissionEntryType::Accrual->value)
            ->first();
    }

    private function accrueLocked(Consultation $consultation): ?CommissionLedgerEntry
    {
        if (
            $consultation->started_at === null
            || ! in_array($consultation->status, [ConsultationStatus::Ended, ConsultationStatus::Interrupted], true)
            || $consultation->commission_rate_bps === null
        ) {
            return null;
        }

        $baseAmountCents = $this->baseAmountCents($consultation);

        if ($baseAmountCents <= 0) {
            return null;
        }

        $idempotencyKey = 'consultation:'.$consultation->getKey().':commission:accrual';

        $existing = CommissionLedgerEntry::query()
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return CommissionLedgerEntry::query()->create([
                'attendant_id' => $consultation->attendant_id,
                'consultation_id' => $consultation->getKey(),
                'commission_rate_bps' => $consultation->commission_rate_bps,
                'base_amount_cents' => $baseAmountCents,
                'commission_amount_cents' => $this->calculator->calculate(
                    $baseAmountCents,
                    $consultation->commission_rate_bps,
                ),
                'type' => CommissionEntryType::Accrual->value,
                'actor_id' => null,
                'reason' => 'Comissão da consulta #'.$consultation->getKey(),
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (QueryException $exception) {
            $existing = CommissionLedgerEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }
}
