<?php

namespace App\Support;

use App\Enums\CommissionEntryType;
use App\Enums\ConsultationModality;
use App\Enums\ConsultationStatus;
use App\Enums\LedgerEntryType;
use App\Models\CommissionLedgerEntry;
use App\Models\Consultation;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Quantidade de atendimentos e valores por modalidade.
 *
 * A receita vem dos debitos oficiais do livro-razao ligados as consultas e a
 * comissao do razao de comissoes (accruals menos estornos). Nenhum valor e
 * recalculado a partir de precos atuais.
 */
final class ModalityUsageReport
{
    /**
     * @return array<string, array{modality: string, label: string, consultations: int, completed: int, revenue_cents: int, commission_cents: int}>
     */
    public static function summary(?User $client = null, ?User $attendant = null): array
    {
        $counts = self::baseConsultations($client, $attendant)
            ->selectRaw('modality, COUNT(*) as total')
            ->groupBy('modality')
            ->pluck('total', 'modality');

        $completed = self::baseConsultations($client, $attendant)
            ->where('status', ConsultationStatus::Ended->value)
            ->selectRaw('modality, COUNT(*) as total')
            ->groupBy('modality')
            ->pluck('total', 'modality');

        $revenue = self::revenueByModality($client, $attendant);
        $commission = self::commissionByModality($client, $attendant);

        $rows = [];

        foreach (ConsultationModality::cases() as $modality) {
            $value = $modality->value;

            $rows[$value] = [
                'modality' => $value,
                'label' => $modality->label(),
                'consultations' => (int) ($counts[$value] ?? 0),
                'completed' => (int) ($completed[$value] ?? 0),
                'revenue_cents' => (int) ($revenue[$value] ?? 0),
                'commission_cents' => (int) ($commission[$value] ?? 0),
            ];
        }

        return $rows;
    }

    private static function baseConsultations(?User $client, ?User $attendant): Builder
    {
        return Consultation::query()
            ->when($client !== null, fn (Builder $query) => $query->where('client_id', $client->getKey()))
            ->when($attendant !== null, fn (Builder $query) => $query->where('attendant_id', $attendant->getKey()));
    }

    /**
     * @return Collection<string, int|string>
     */
    private static function revenueByModality(?User $client, ?User $attendant): Collection
    {
        return CreditLedgerEntry::query()
            ->join('consultations', function ($join): void {
                $join->on('consultations.id', '=', 'credit_ledger_entries.reference_id')
                    ->where('credit_ledger_entries.reference_type', '=', (new Consultation)->getMorphClass());
            })
            ->when($client !== null, fn ($query) => $query->where('consultations.client_id', $client->getKey()))
            ->when($attendant !== null, fn ($query) => $query->where('consultations.attendant_id', $attendant->getKey()))
            ->where('credit_ledger_entries.type', LedgerEntryType::Debit->value)
            ->selectRaw('consultations.modality as modality, COALESCE(SUM(credit_ledger_entries.amount_cents), 0) as total')
            ->groupBy('consultations.modality')
            ->pluck('total', 'modality');
    }

    /**
     * @return Collection<string, int|string>
     */
    private static function commissionByModality(?User $client, ?User $attendant): Collection
    {
        return CommissionLedgerEntry::query()
            ->join('consultations', 'consultations.id', '=', 'commission_ledger_entries.consultation_id')
            ->when($client !== null, fn ($query) => $query->where('consultations.client_id', $client->getKey()))
            ->when($attendant !== null, fn ($query) => $query->where('consultations.attendant_id', $attendant->getKey()))
            ->selectRaw(
                'consultations.modality as modality, COALESCE(SUM(CASE WHEN commission_ledger_entries.type = ? THEN -commission_ledger_entries.commission_amount_cents ELSE commission_ledger_entries.commission_amount_cents END), 0) as total',
                [CommissionEntryType::Reversal->value],
            )
            ->groupBy('consultations.modality')
            ->pluck('total', 'modality');
    }
}
