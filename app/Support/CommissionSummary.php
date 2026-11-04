<?php

namespace App\Support;

use App\Enums\CommissionEntryType;
use App\Models\CommissionLedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resumo financeiro do atendente derivado exclusivamente do razão de comissões.
 *
 * Não existe saldo de comissão editável: accruals e adjustments somam,
 * reversals subtraem. O valor do estorno é persistido positivo e o tipo define
 * o sinal do total.
 */
final readonly class CommissionSummary
{
    public function __construct(
        public int $totalCents,
        public int $monthCents,
        public int $accruedCents = 0,
        public int $reversedCents = 0,
        public int $adjustmentCents = 0,
        public int $baseCents = 0,
    ) {}

    public static function forAttendant(User $attendant): self
    {
        $query = CommissionLedgerEntry::query()
            ->where('attendant_id', $attendant->getKey());

        $total = self::signedTotal(clone $query);

        $month = self::signedTotal(
            (clone $query)->where('created_at', '>=', now()->startOfMonth()),
        );

        return new self(
            totalCents: $total,
            monthCents: $month,
            accruedCents: (int) (clone $query)
                ->where('type', CommissionEntryType::Accrual->value)
                ->sum('commission_amount_cents'),
            reversedCents: (int) (clone $query)
                ->where('type', CommissionEntryType::Reversal->value)
                ->sum('commission_amount_cents'),
            adjustmentCents: (int) (clone $query)
                ->where('type', CommissionEntryType::Adjustment->value)
                ->sum('commission_amount_cents'),
            baseCents: (int) (clone $query)
                ->where('type', CommissionEntryType::Accrual->value)
                ->sum('base_amount_cents'),
        );
    }

    private static function signedTotal(Builder $query): int
    {
        return (int) $query
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type = ? THEN -commission_amount_cents ELSE commission_amount_cents END), 0) AS aggregate',
                [CommissionEntryType::Reversal->value],
            )
            ->value('aggregate');
    }
}
