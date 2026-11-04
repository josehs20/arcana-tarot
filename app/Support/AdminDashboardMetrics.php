<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Enums\CommissionEntryType;
use App\Enums\ConsultationStatus;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\AttendantApplication;
use App\Models\AttendantProfile;
use App\Models\CommissionLedgerEntry;
use App\Models\Consultation;
use App\Models\CreditLedgerEntry;
use App\Models\Payment;
use App\Models\User;

/**
 * Indicadores administrativos calculados exclusivamente a partir dos dados
 * oficiais. Nenhum valor é armazenado em cache paralelo ou digitado à mão.
 *
 * Definições (ver docs/ADMIN_REPORTING.md):
 * - receita bruta de consultas = débitos do ledger de créditos ligados a consultas;
 * - comissões acumuladas = accruals + adjustments − reversals;
 * - receita operacional após comissões = receita bruta − comissões líquidas.
 * O último valor não é lucro líquido: impostos, custos e taxas de terceiros
 * ainda não estão modelados no MVP.
 */
final readonly class AdminDashboardMetrics
{
    public function __construct(
        public int $totalClients,
        public int $activeClients,
        public int $totalAttendants,
        public int $activeAttendants,
        public int $onlineAttendants,
        public int $busyAttendants,
        public int $activeConsultations,
        public int $pendingConsultationRequests,
        public int $completedConsultations,
        public int $confirmedRecharges,
        public int $confirmedRechargeCents,
        public int $pendingPayments,
        public int $pendingPaymentCents,
        public int $pendingApplications,
        public int $commissionAccruedCents,
        public int $commissionReversedCents,
        public int $commissionNetCents,
        public int $grossConsultationRevenueCents,
        public int $netOperationalRevenueCents,
    ) {}

    public static function collect(): self
    {
        $commissionNetCents = (int) CommissionLedgerEntry::query()
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type = ? THEN -commission_amount_cents ELSE commission_amount_cents END), 0) AS aggregate',
                [CommissionEntryType::Reversal->value],
            )
            ->value('aggregate');

        $grossConsultationRevenueCents = (int) CreditLedgerEntry::query()
            ->where('type', LedgerEntryType::Debit->value)
            ->where('reference_type', (new Consultation)->getMorphClass())
            ->sum('amount_cents');

        return new self(
            totalClients: User::query()
                ->where('role', UserRole::Client->value)
                ->count(),
            activeClients: User::query()
                ->where('role', UserRole::Client->value)
                ->where('is_active', true)
                ->count(),
            totalAttendants: User::query()
                ->where('role', UserRole::Attendant->value)
                ->count(),
            activeAttendants: User::query()
                ->where('role', UserRole::Attendant->value)
                ->where('is_active', true)
                ->count(),
            onlineAttendants: AttendantProfile::query()
                ->available()
                ->count(),
            busyAttendants: Consultation::query()
                ->where('status', ConsultationStatus::Active->value)
                ->distinct()
                ->count('attendant_id'),
            activeConsultations: Consultation::query()
                ->where('status', ConsultationStatus::Active->value)
                ->count(),
            pendingConsultationRequests: Consultation::query()
                ->where('status', ConsultationStatus::Requested->value)
                ->count(),
            completedConsultations: Consultation::query()
                ->where('status', ConsultationStatus::Ended->value)
                ->count(),
            confirmedRecharges: Payment::query()
                ->where('status', PaymentStatus::Confirmed->value)
                ->count(),
            confirmedRechargeCents: (int) Payment::query()
                ->where('status', PaymentStatus::Confirmed->value)
                ->sum('amount_cents'),
            pendingPayments: Payment::query()
                ->where('status', PaymentStatus::Pending->value)
                ->count(),
            pendingPaymentCents: (int) Payment::query()
                ->where('status', PaymentStatus::Pending->value)
                ->sum('amount_cents'),
            pendingApplications: AttendantApplication::query()
                ->where('status', ApplicationStatus::Pending->value)
                ->count(),
            commissionAccruedCents: (int) CommissionLedgerEntry::query()
                ->where('type', CommissionEntryType::Accrual->value)
                ->sum('commission_amount_cents'),
            commissionReversedCents: (int) CommissionLedgerEntry::query()
                ->where('type', CommissionEntryType::Reversal->value)
                ->sum('commission_amount_cents'),
            commissionNetCents: $commissionNetCents,
            grossConsultationRevenueCents: $grossConsultationRevenueCents,
            netOperationalRevenueCents: $grossConsultationRevenueCents - $commissionNetCents,
        );
    }
}
