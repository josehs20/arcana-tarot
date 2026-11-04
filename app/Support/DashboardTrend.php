<?php

namespace App\Support;

use App\Enums\LedgerEntryType;
use App\Enums\PaymentStatus;
use App\Models\CommissionLedgerEntry;
use App\Models\Consultation;
use App\Models\CreditLedgerEntry;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Séries diárias usadas nos gráficos dos painéis.
 *
 * Os valores são calculados a partir das tabelas oficiais e agrupados em PHP
 * para manter o mesmo comportamento em SQLite (testes) e MySQL (produção).
 * Todos os totais monetários são expressos em centavos.
 */
final class DashboardTrend
{
    /**
     * @return array{labels: list<string>, values: list<int>, total: int, period: string}
     */
    public static function consultations(int $days = 14, ?int $attendantId = null, ?int $clientId = null): array
    {
        $query = Consultation::query();

        if ($attendantId !== null) {
            $query->where('attendant_id', $attendantId);
        }

        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }

        return self::fromTimestamps(
            $query->get(['created_at'])->pluck('created_at'),
            $days,
        );
    }

    /**
     * Recargas confirmadas por dia, usando a data efetiva do pagamento.
     *
     * @return array{labels: list<string>, values: list<int>, total: int, period: string}
     */
    public static function confirmedRecharges(int $days = 14): array
    {
        $payments = Payment::query()
            ->where('status', PaymentStatus::Confirmed->value)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', self::start($days))
            ->get(['paid_at', 'amount_cents']);

        return self::fromAmounts(
            $payments->map(fn (Payment $payment): array => [
                'occurred_at' => $payment->paid_at,
                'amount_cents' => $payment->amount_cents,
            ]),
            $days,
        );
    }

    /**
     * Débitos de consultas do cliente por dia.
     *
     * @return array{labels: list<string>, values: list<int>, total: int, period: string}
     */
    public static function consultationSpend(Wallet $wallet, int $days = 14): array
    {
        $entries = CreditLedgerEntry::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', LedgerEntryType::Debit->value)
            ->where('reference_type', (new Consultation)->getMorphClass())
            ->where('created_at', '>=', self::start($days))
            ->get(['created_at', 'amount_cents']);

        return self::fromAmounts(
            $entries->map(fn (CreditLedgerEntry $entry): array => [
                'occurred_at' => $entry->created_at,
                'amount_cents' => $entry->amount_cents,
            ]),
            $days,
        );
    }

    /**
     * Comissões creditadas por dia para o atendente. Estornos são subtraídos
     * na data em que ocorreram, mantendo o valor líquido do período.
     *
     * @return array{labels: list<string>, values: list<int>, total: int, period: string}
     */
    public static function commissions(User $attendant, int $days = 14): array
    {
        $entries = CommissionLedgerEntry::query()
            ->where('attendant_id', $attendant->id)
            ->where('created_at', '>=', self::start($days))
            ->get(['created_at', 'commission_amount_cents', 'type']);

        return self::fromAmounts(
            $entries->map(fn (CommissionLedgerEntry $entry): array => [
                'occurred_at' => $entry->created_at,
                'amount_cents' => $entry->signedAmountCents(),
            ]),
            $days,
        );
    }

    /**
     * @param  Collection<int, mixed>  $timestamps
     * @return array{labels: list<string>, values: list<int>, total: int, period: string}
     */
    private static function fromTimestamps(Collection $timestamps, int $days): array
    {
        return self::fromAmounts(
            $timestamps->map(fn (mixed $timestamp): array => [
                'occurred_at' => $timestamp,
                'amount_cents' => 1,
            ]),
            $days,
        );
    }

    /**
     * @param  Collection<int, array{occurred_at: mixed, amount_cents: int}>  $entries
     * @return array{labels: list<string>, values: list<int>, total: int, period: string}
     */
    private static function fromAmounts(Collection $entries, int $days): array
    {
        $start = self::start($days);
        $totals = [];

        foreach ($entries as $entry) {
            if ($entry['occurred_at'] === null) {
                continue;
            }

            /** @var CarbonImmutable $occurredAt */
            $occurredAt = CarbonImmutable::parse($entry['occurred_at']);
            $key = $occurredAt->toDateString();
            $totals[$key] = ($totals[$key] ?? 0) + (int) $entry['amount_cents'];
        }

        $labels = [];
        $values = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->addDays($offset);
            $labels[] = $date->format('d/m');
            $values[] = (int) ($totals[$date->toDateString()] ?? 0);
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'total' => array_sum($values),
            'period' => $start->format('d/m').' a '.now()->format('d/m'),
        ];
    }

    private static function start(int $days): CarbonImmutable
    {
        return CarbonImmutable::now()
            ->startOfDay()
            ->subDays(max(1, $days) - 1);
    }
}
