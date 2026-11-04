<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\CommissionEntryType;
use App\Enums\ConsultationStatus;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentStatus;
use App\Models\AttendantApplication;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\CommissionLedgerEntry;
use App\Models\Consultation;
use App\Models\CreditLedgerEntry;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Support\AdminDashboardMetrics;
use App\Support\CommissionSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoryAndAdminReportingTest extends TestCase
{
    use RefreshDatabase;

    private function onlineAttendant(int $rateBps = 2500): User
    {
        $attendant = User::factory()->attendant()->create();
        AttendantProfile::factory()->online()->for($attendant)->create([
            'commission_rate_bps' => $rateBps,
        ]);

        return $attendant;
    }

    public function test_client_history_shows_own_consultations_payments_and_ledger_only(): void
    {
        $client = User::factory()->create(['name' => 'Cliente do Histórico']);
        $wallet = Wallet::factory()->create([
            'user_id' => $client->id,
            'balance_cents' => 4700,
        ]);

        $attendant = $this->onlineAttendant();

        $consultation = Consultation::factory()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            'status' => ConsultationStatus::Ended->value,
            'started_at' => now()->subMinutes(3),
            'ended_at' => now(),
            'billed_minutes' => 3,
            'commission_rate_bps' => 2500,
            'rate_credits_per_minute' => 1,
            'credit_unit_price_cents' => 100,
        ]);

        CreditLedgerEntry::factory()->create([
            'wallet_id' => $wallet->id,
            'type' => LedgerEntryType::Debit->value,
            'amount_cents' => 300,
            'balance_after_cents' => 4700,
            'reference_type' => Consultation::class,
            'reference_id' => $consultation->id,
            'reason' => 'Consulta #'.$consultation->id.' — minutos',
            'idempotency_key' => 'history:debit:'.$consultation->id,
        ]);

        Payment::factory()->confirmed()->create([
            'user_id' => $client->id,
            'amount_cents' => 1000,
            'credits' => 10,
            'credit_unit_price_cents' => 100,
            'idempotency_key' => 'history:payment:'.$client->id,
        ]);

        $otherClient = User::factory()->create([
            'name' => 'Cliente Externo Histórico',
            'email' => 'externo.historico@example.com',
        ]);
        $otherAttendant = $this->onlineAttendant();

        Consultation::factory()->create([
            'client_id' => $otherClient->id,
            'attendant_id' => $otherAttendant->id,
            'status' => ConsultationStatus::Ended->value,
            'started_at' => now()->subMinute(),
            'ended_at' => now(),
        ]);

        Payment::factory()->confirmed()->create([
            'user_id' => $otherClient->id,
            'amount_cents' => 9000,
            'credits' => 90,
            'idempotency_key' => 'history:other-payment:'.$otherClient->id,
        ]);

        $this->actingAs($client)
            ->get(route('client.history'))
            ->assertOk()
            ->assertSee('Meu histórico')
            ->assertSee('R$ 3,00')
            ->assertSee('3 minuto(s) cobrado(s)')
            ->assertSee('R$ 10,00')
            ->assertSee('Consulta #'.$consultation->id.' — minutos')
            ->assertDontSee($otherClient->name)
            ->assertDontSee($otherClient->email)
            ->assertDontSee('R$ 90,00')
            ->assertViewHas('consultations', fn ($consultations): bool => $consultations->total() === 1)
            ->assertViewHas(
                'consumedByConsultation',
                fn ($map): bool => (int) ($map[$consultation->id] ?? 0) === 300,
            );

        $this->actingAs($client)
            ->get(route('client.history', ['status' => ConsultationStatus::Cancelled->value]))
            ->assertOk()
            ->assertViewHas('consultations', fn ($consultations): bool => $consultations->total() === 0);

        $this->actingAs($attendant)
            ->get(route('client.history'))
            ->assertForbidden();
    }

    public function test_attendant_history_shows_frozen_rate_base_and_net_commission_without_cross_access(): void
    {
        $attendant = User::factory()->attendant()->create();
        AttendantProfile::factory()->online()->for($attendant)->create([
            'commission_rate_bps' => 2500,
        ]);

        $client = User::factory()->create(['name' => 'Cliente do Atendente']);
        $admin = User::factory()->admin()->create();

        $consultation = Consultation::factory()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            'status' => ConsultationStatus::Ended->value,
            'started_at' => now()->subMinutes(2),
            'ended_at' => now(),
            'commission_rate_bps' => 2500,
            'rate_credits_per_minute' => 1,
            'credit_unit_price_cents' => 100,
            'billed_minutes' => 2,
        ]);

        $accrual = CommissionLedgerEntry::factory()->create([
            'attendant_id' => $attendant->id,
            'consultation_id' => $consultation->id,
            'commission_rate_bps' => 2500,
            'base_amount_cents' => 1000,
            'commission_amount_cents' => 250,
            'type' => CommissionEntryType::Accrual->value,
            'idempotency_key' => 'history:accrual:'.$consultation->id,
        ]);

        CommissionLedgerEntry::factory()->create([
            'attendant_id' => $attendant->id,
            'consultation_id' => $consultation->id,
            'reversed_entry_id' => $accrual->id,
            'commission_rate_bps' => 2500,
            'base_amount_cents' => 1000,
            'commission_amount_cents' => 250,
            'type' => CommissionEntryType::Reversal->value,
            'actor_id' => $admin->id,
            'reason' => 'Estorno de teste auditado',
            'idempotency_key' => 'history:reversal:'.$accrual->id,
        ]);

        $otherAttendant = $this->onlineAttendant();
        $otherClient = User::factory()->create(['name' => 'Cliente de Outro Atendente']);
        $otherConsultation = Consultation::factory()->create([
            'client_id' => $otherClient->id,
            'attendant_id' => $otherAttendant->id,
            'status' => ConsultationStatus::Ended->value,
            'started_at' => now()->subMinute(),
            'ended_at' => now(),
        ]);

        CommissionLedgerEntry::factory()->create([
            'attendant_id' => $otherAttendant->id,
            'consultation_id' => $otherConsultation->id,
            'base_amount_cents' => 9999,
            'commission_amount_cents' => 8888,
            'idempotency_key' => 'history:other-accrual:'.$otherConsultation->id,
        ]);

        $this->actingAs($attendant)
            ->get(route('attendant.history'))
            ->assertOk()
            ->assertSee('Meu histórico')
            ->assertSee('Cliente do Atendente')
            ->assertSee('25%')
            ->assertSee('R$ 10,00')
            ->assertSee('R$ 2,50')
            ->assertSee('Estorno de teste auditado')
            ->assertDontSee($otherClient->name)
            ->assertDontSee('R$ 88,88')
            ->assertViewHas('summary', fn (CommissionSummary $summary): bool => $summary->accruedCents === 250
                && $summary->reversedCents === 250
                && $summary->totalCents === 0
                && $summary->baseCents === 1000);

        $this->actingAs($client)
            ->get(route('attendant.history'))
            ->assertForbidden();
    }

    public function test_admin_dashboard_uses_real_operational_metrics(): void
    {
        $admin = User::factory()->admin()->create();

        $activeClient = User::factory()->create();
        $endedClient = User::factory()->create();
        Wallet::factory()->create([
            'user_id' => $endedClient->id,
            'balance_cents' => 0,
        ]);

        $busyAttendant = User::factory()->attendant()->create();
        AttendantProfile::factory()->online()->for($busyAttendant)->create();

        $freeAttendant = User::factory()->attendant()->create();
        AttendantProfile::factory()->online()->for($freeAttendant)->create();

        Consultation::factory()->active()->create([
            'client_id' => $activeClient->id,
            'attendant_id' => $busyAttendant->id,
        ]);

        $endedConsultation = Consultation::factory()->create([
            'client_id' => $endedClient->id,
            'attendant_id' => $freeAttendant->id,
            'status' => ConsultationStatus::Ended->value,
            'started_at' => now()->subMinute(),
            'ended_at' => now(),
            'commission_rate_bps' => 2000,
            'billed_minutes' => 1,
            'rate_credits_per_minute' => 1,
            'credit_unit_price_cents' => 100,
        ]);

        Payment::factory()->confirmed()->create([
            'user_id' => $activeClient->id,
            'amount_cents' => 10000,
            'credits' => 100,
            'idempotency_key' => 'metrics:payment:'.$activeClient->id,
        ]);

        Payment::factory()->create([
            'user_id' => $endedClient->id,
            'amount_cents' => 5000,
            'credits' => 50,
            'idempotency_key' => 'metrics:pending-payment:'.$endedClient->id,
        ]);

        AttendantApplication::factory()->create([
            'status' => ApplicationStatus::Pending->value,
        ]);

        CommissionLedgerEntry::factory()->create([
            'attendant_id' => $freeAttendant->id,
            'consultation_id' => $endedConsultation->id,
            'commission_rate_bps' => 2000,
            'base_amount_cents' => 10000,
            'commission_amount_cents' => 2000,
            'idempotency_key' => 'metrics:commission:'.$endedConsultation->id,
        ]);

        CreditLedgerEntry::factory()->create([
            'wallet_id' => $endedClient->wallet->id,
            'type' => LedgerEntryType::Debit->value,
            'amount_cents' => 10000,
            'balance_after_cents' => 0,
            'reference_type' => Consultation::class,
            'reference_id' => $endedConsultation->id,
            'idempotency_key' => 'metrics:debit:'.$endedConsultation->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Receita bruta de consultas')
            ->assertSee('Comissões líquidas')
            ->assertViewHas('metrics', fn (AdminDashboardMetrics $metrics): bool => $metrics->totalClients === 2
                && $metrics->activeClients === 2
                && $metrics->onlineAttendants === 2
                && $metrics->busyAttendants === 1
                && $metrics->activeConsultations === 1
                && $metrics->pendingConsultationRequests === 0
                && $metrics->completedConsultations === 1
                && $metrics->confirmedRecharges === 1
                && $metrics->confirmedRechargeCents === 10000
                && $metrics->pendingApplications === 1
                && $metrics->commissionAccruedCents === 2000
                && $metrics->commissionReversedCents === 0
                && $metrics->commissionNetCents === 2000
                && $metrics->grossConsultationRevenueCents === 10000
                && $metrics->netOperationalRevenueCents === 8000);

        $this->actingAs($activeClient)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_admin_payment_list_filters_by_status_and_rejects_non_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $confirmedClient = User::factory()->create([
            'name' => 'Cliente Confirmado',
            'email' => 'confirmado.pagamento@example.com',
        ]);
        $pendingClient = User::factory()->create([
            'name' => 'Cliente Pendente',
            'email' => 'pendente.pagamento@example.com',
        ]);

        Payment::factory()->confirmed()->create([
            'user_id' => $confirmedClient->id,
            'amount_cents' => 5000,
            'credits' => 50,
            'idempotency_key' => 'payments:confirmed',
        ]);

        Payment::factory()->create([
            'user_id' => $pendingClient->id,
            'amount_cents' => 7000,
            'credits' => 70,
            'idempotency_key' => 'payments:pending',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.payments.index', ['status' => PaymentStatus::Confirmed->value]))
            ->assertOk()
            ->assertSee($confirmedClient->name)
            ->assertSee($confirmedClient->email)
            ->assertDontSee($pendingClient->name)
            ->assertDontSee($pendingClient->email)
            ->assertViewHas('confirmedCount', 1)
            ->assertViewHas('confirmedCents', 5000);

        $this->actingAs($confirmedClient)
            ->get(route('admin.payments.index'))
            ->assertForbidden();

        $this->actingAs($this->onlineAttendant())
            ->get(route('admin.payments.index'))
            ->assertForbidden();
    }

    public function test_audit_log_is_read_only_and_admin_only_with_escaped_metadata(): void
    {
        $admin = User::factory()->admin()->create();
        $actor = User::factory()->admin()->create();

        AuditLog::factory()->create([
            'actor_id' => $actor->id,
            'action' => 'wallet.adjustment',
            'metadata' => [
                'reason' => '<script>alert(1)</script>',
                'amount_cents' => 1000,
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['action' => 'wallet.adjustment']))
            ->assertOk()
            ->assertSee('Trilha de auditoria')
            ->assertSee('wallet.adjustment')
            ->assertSee($actor->name)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.audit.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->attendant()->create())
            ->get(route('admin.audit.index'))
            ->assertForbidden();
    }
}
