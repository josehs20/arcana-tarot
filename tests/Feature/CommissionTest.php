<?php

namespace Tests\Feature;

use App\Enums\CommissionEntryType;
use App\Enums\ConsultationEndReason;
use App\Enums\ConsultationStatus;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\CommissionLedgerEntry;
use App\Models\Consultation;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Support\CommissionManager;
use App\Support\CommissionSummary;
use App\Support\ConsultationBillingManager;
use App\Support\ConsultationLifecycleManager;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::query()->create([
            'key' => 'rate_credits_per_minute',
            'value' => '1',
            'type' => 'integer',
        ]);

        Setting::query()->create([
            'key' => 'credit_unit_price_cents',
            'value' => '100',
            'type' => 'integer',
        ]);

        Setting::query()->create([
            'key' => 'minimum_balance_minutes',
            'value' => '1',
            'type' => 'integer',
        ]);
    }

    private function client(int $balanceCents = 10000): User
    {
        $client = User::factory()->create();
        Wallet::factory()->create([
            'user_id' => $client->id,
            'balance_cents' => $balanceCents,
        ]);

        return $client;
    }

    /**
     * @return array{0: User, 1: AttendantProfile}
     */
    private function attendant(int $rateBps = 2500): array
    {
        $attendant = User::factory()->attendant()->create();
        $profile = AttendantProfile::factory()->online()->for($attendant)->create([
            'commission_rate_bps' => $rateBps,
        ]);

        return [$attendant, $profile];
    }

    /**
     * Consulta ativa com cobrança já registrada no razão de créditos.
     */
    private function activeConsultation(User $client, User $attendant, int $rateBps, int $secondsAgo = 80): Consultation
    {
        $consultation = Consultation::factory()->active()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            'commission_rate_bps' => $rateBps,
            'rate_credits_per_minute' => 1,
            'credit_unit_price_cents' => 100,
            'started_at' => now()->subSeconds($secondsAgo),
        ]);

        app(ConsultationBillingManager::class)->billDue($consultation);

        return $consultation->fresh();
    }

    private function end(Consultation $consultation): Consultation
    {
        return app(ConsultationLifecycleManager::class)->end($consultation->client, $consultation);
    }

    private function manager(): CommissionManager
    {
        return app(CommissionManager::class);
    }

    public static function invalidCommissionRates(): array
    {
        return [
            'negativa' => ['-1'],
            'acima de cem' => ['100.01'],
            'tres casas decimais' => ['25.999'],
            'texto' => ['abc'],
            'vazia' => [null],
        ];
    }

    public static function validCommissionRateBoundaries(): array
    {
        return [
            'zero' => ['0', 0],
            'inteiro' => [25, 2500],
            'duas casas' => ['25.50', 2550],
            'cem por cento' => ['100', 10000],
        ];
    }

    #[DataProvider('validCommissionRateBoundaries')]
    public function test_accepts_commission_rate_boundaries(string|int $rate, int $expectedBps): void
    {
        $admin = User::factory()->admin()->create();
        [$attendant, $profile] = $this->attendant(3000);

        $this->actingAs($admin)
            ->patch(route('admin.commissions.rate.update', $attendant), ['commission_rate' => $rate])
            ->assertRedirect(route('admin.commissions.attendant', $attendant))
            ->assertSessionHasNoErrors();

        $this->assertSame($expectedBps, $profile->fresh()->commission_rate_bps);
    }

    #[DataProvider('invalidCommissionRates')]
    public function test_rejects_invalid_commission_rate_from_request(mixed $rate): void
    {
        $admin = User::factory()->admin()->create();
        [$attendant, $profile] = $this->attendant(3000);

        $this->actingAs($admin)
            ->from(route('admin.commissions.attendant', $attendant))
            ->patch(route('admin.commissions.rate.update', $attendant), ['commission_rate' => $rate])
            ->assertRedirect(route('admin.commissions.attendant', $attendant))
            ->assertSessionHasErrors('commission_rate');

        $this->assertSame(3000, $profile->fresh()->commission_rate_bps);
        $this->assertSame(0, AuditLog::query()->where('action', 'attendant.commission_rate_updated')->count());
    }

    public function test_admin_updates_rate_with_two_decimals_and_audits_previous_and_new_values(): void
    {
        $admin = User::factory()->admin()->create();
        [$attendant, $profile] = $this->attendant(3000);

        $this->actingAs($admin)
            ->patch(route('admin.commissions.rate.update', $attendant), [
                'commission_rate' => '25,50',
                'commission_rate_bps' => 10000,
            ])
            ->assertRedirect(route('admin.commissions.attendant', $attendant))
            ->assertSessionHas('success');

        $this->assertSame(2550, $profile->fresh()->commission_rate_bps);

        $audit = AuditLog::query()
            ->where('action', 'attendant.commission_rate_updated')
            ->firstOrFail();

        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame(3000, $audit->metadata['before']);
        $this->assertSame(2550, $audit->metadata['after']);
        $this->assertSame('30%', $audit->metadata['before_percent']);
        $this->assertSame('25,5%', $audit->metadata['after_percent']);

        $this->actingAs($admin)
            ->get(route('admin.commissions.attendant', $attendant))
            ->assertOk()
            ->assertSee('25,5%')
            ->assertSee('Configuração da taxa');
    }

    public function test_non_admin_cannot_change_commission_rate(): void
    {
        $client = $this->client();
        [$attendant, $profile] = $this->attendant(3000);

        $this->actingAs($client)
            ->patch(route('admin.commissions.rate.update', $attendant), ['commission_rate' => '10'])
            ->assertForbidden();

        $this->actingAs($attendant)
            ->patch(route('admin.commissions.rate.update', $attendant), ['commission_rate' => '10'])
            ->assertForbidden();

        $this->assertSame(3000, $profile->fresh()->commission_rate_bps);
    }

    public function test_rate_change_does_not_alter_started_or_finalized_consultation_commission(): void
    {
        $this->travelTo('2026-01-10 12:00:00');

        $admin = User::factory()->admin()->create();
        $client = $this->client();
        [$attendant, $profile] = $this->attendant(2500);
        $lifecycle = app(ConsultationLifecycleManager::class);

        $first = $lifecycle->request($client, $attendant);
        $first = $lifecycle->accept($attendant, $first);

        $this->travelTo('2026-01-10 12:01:20');
        $first = $this->end($first->fresh());

        $this->actingAs($admin)
            ->patch(route('admin.commissions.rate.update', $attendant), ['commission_rate' => '50'])
            ->assertRedirect(route('admin.commissions.attendant', $attendant));

        $second = $lifecycle->request($client, $attendant);
        $second = $lifecycle->accept($attendant, $second);

        $this->travelTo('2026-01-10 12:02:40');
        $second = $this->end($second->fresh());

        $firstEntry = CommissionLedgerEntry::query()
            ->where('consultation_id', $first->id)
            ->where('type', CommissionEntryType::Accrual->value)
            ->firstOrFail();

        $secondEntry = CommissionLedgerEntry::query()
            ->where('consultation_id', $second->id)
            ->where('type', CommissionEntryType::Accrual->value)
            ->firstOrFail();

        $this->assertSame(2500, $first->fresh()->commission_rate_bps);
        $this->assertSame(2500, $firstEntry->commission_rate_bps);
        $this->assertSame(200, $firstEntry->base_amount_cents);
        $this->assertSame(50, $firstEntry->commission_amount_cents);

        $this->assertSame(5000, $second->fresh()->commission_rate_bps);
        $this->assertSame(5000, $secondEntry->commission_rate_bps);
        $this->assertSame(200, $secondEntry->base_amount_cents);
        $this->assertSame(100, $secondEntry->commission_amount_cents);
        $this->assertSame(5000, $profile->fresh()->commission_rate_bps);
    }

    public function test_cancelled_request_without_start_does_not_generate_commission(): void
    {
        $client = $this->client();
        [$attendant] = $this->attendant(2500);
        $lifecycle = app(ConsultationLifecycleManager::class);

        $consultation = $lifecycle->request($client, $attendant);
        $cancelled = $lifecycle->cancel($client, $consultation);

        $this->assertSame(ConsultationStatus::Cancelled, $cancelled->status);
        $this->assertSame(0, CommissionLedgerEntry::query()->count());
        $this->assertNull($this->manager()->accrue($cancelled));
        $this->assertSame(0, CommissionSummary::forAttendant($attendant)->totalCents);
    }

    public function test_started_consultation_without_positive_charge_does_not_generate_commission(): void
    {
        $consultation = Consultation::factory()->active()->create([
            'commission_rate_bps' => 2500,
            'rate_credits_per_minute' => null,
            'credit_unit_price_cents' => null,
            'started_at' => now(),
        ]);

        $ended = $this->end($consultation);

        $this->assertSame(ConsultationStatus::Ended, $ended->status);
        $this->assertSame(0, CommissionLedgerEntry::query()->count());
    }

    public function test_ended_consultation_creates_one_commission_from_actual_charged_amount(): void
    {
        $this->travelTo('2026-01-10 12:00:00');

        $client = $this->client();
        [$attendant] = $this->attendant(2500);
        $consultation = $this->activeConsultation($client, $attendant, 2500);

        $ended = $this->end($consultation);

        $entry = CommissionLedgerEntry::query()->sole();

        $this->assertSame(ConsultationStatus::Ended, $ended->status);
        $this->assertSame($attendant->id, $entry->attendant_id);
        $this->assertSame($consultation->id, $entry->consultation_id);
        $this->assertSame(CommissionEntryType::Accrual, $entry->type);
        $this->assertSame(2500, $entry->commission_rate_bps);
        $this->assertSame(200, $entry->base_amount_cents);
        $this->assertSame(50, $entry->commission_amount_cents);
        $this->assertNull($entry->actor_id);
        $this->assertSame('consultation:'.$consultation->id.':commission:accrual', $entry->idempotency_key);
        $this->assertSame(50, CommissionSummary::forAttendant($attendant)->totalCents);
    }

    public function test_interrupted_consultation_creates_commission_for_charged_minutes(): void
    {
        $this->travelTo('2026-01-10 12:00:00');

        $client = $this->client();
        [$attendant] = $this->attendant(2500);
        $consultation = $this->activeConsultation($client, $attendant, 2500);

        $interrupted = app(ConsultationLifecycleManager::class)->interrupt(
            $consultation,
            ConsultationEndReason::Disconnected,
        );

        $entry = CommissionLedgerEntry::query()->sole();

        $this->assertSame(ConsultationStatus::Interrupted, $interrupted->status);
        $this->assertSame(200, $entry->base_amount_cents);
        $this->assertSame(50, $entry->commission_amount_cents);
        $this->assertSame(2500, $entry->commission_rate_bps);
    }

    public function test_repeated_end_and_accrual_keep_a_single_original_commission(): void
    {
        $this->travelTo('2026-01-10 12:00:00');

        $client = $this->client();
        [$attendant] = $this->attendant(2500);
        $consultation = $this->activeConsultation($client, $attendant, 2500);

        $this->end($consultation);
        $this->end($consultation->fresh());

        $this->manager()->accrue($consultation->fresh());
        $this->manager()->accrue($consultation->fresh());

        $this->assertSame(1, CommissionLedgerEntry::query()->count());
        $this->assertSame(50, (int) CommissionLedgerEntry::query()->sum('commission_amount_cents'));
    }

    public function test_database_unique_key_blocks_duplicate_accrual_from_another_worker(): void
    {
        $this->travelTo('2026-01-10 12:00:00');

        $client = $this->client();
        [$attendant] = $this->attendant(2500);
        $consultation = $this->activeConsultation($client, $attendant, 2500);
        $this->end($consultation);

        $entry = CommissionLedgerEntry::query()->sole();

        $this->expectException(QueryException::class);

        CommissionLedgerEntry::query()->create([
            'attendant_id' => $entry->attendant_id,
            'consultation_id' => $entry->consultation_id,
            'commission_rate_bps' => $entry->commission_rate_bps,
            'base_amount_cents' => $entry->base_amount_cents,
            'commission_amount_cents' => $entry->commission_amount_cents,
            'type' => CommissionEntryType::Accrual->value,
            'idempotency_key' => $entry->idempotency_key,
        ]);
    }

    public function test_client_does_not_see_commission_rate_or_amount_in_consultation_details(): void
    {
        $this->travelTo('2026-01-10 12:00:00');

        $client = $this->client();
        [$attendant] = $this->attendant(2500);
        $consultation = $this->activeConsultation($client, $attendant, 2500);
        $this->end($consultation);

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertDontSee('Comissão aplicada')
            ->assertDontSee('25%')
            ->assertDontSee('R$ 0,50');
    }

    public function test_admin_reversal_creates_separate_audited_entry_and_keeps_original(): void
    {
        $this->travelTo('2026-01-10 12:00:00');

        $admin = User::factory()->admin()->create();
        $client = $this->client();
        [$attendant] = $this->attendant(2500);
        $consultation = $this->activeConsultation($client, $attendant, 2500);

        $this->end($consultation);

        $accrual = CommissionLedgerEntry::query()
            ->where('type', CommissionEntryType::Accrual->value)
            ->sole();

        $this->actingAs($admin)
            ->post(route('admin.commissions.reverse', $accrual), [
                'reason' => 'Consulta contestada pelo cliente',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $reversal = CommissionLedgerEntry::query()
            ->where('type', CommissionEntryType::Reversal->value)
            ->sole();

        $this->assertSame(2, CommissionLedgerEntry::query()->count());
        $this->assertSame($accrual->id, $reversal->reversed_entry_id);
        $this->assertSame($accrual->commission_amount_cents, $reversal->commission_amount_cents);
        $this->assertSame($accrual->base_amount_cents, $reversal->base_amount_cents);
        $this->assertSame($accrual->commission_rate_bps, $reversal->commission_rate_bps);
        $this->assertSame($admin->id, $reversal->actor_id);
        $this->assertSame('Consulta contestada pelo cliente', $reversal->reason);
        $this->assertSame(50, $accrual->fresh()->commission_amount_cents);
        $this->assertSame(0, CommissionSummary::forAttendant($attendant)->totalCents);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'commission.reversed',
            'subject_id' => $accrual->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.commissions.reverse', $accrual), [
                'reason' => 'Reenvio do mesmo estorno',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(2, CommissionLedgerEntry::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'commission.reversed')->count());

        $this->actingAs($admin)
            ->get(route('admin.commissions.index'))
            ->assertOk()
            ->assertViewHas('totals', fn (array $totals): bool => $totals['gross_cents'] === 200
                && $totals['commission_cents'] === 0
                && $totals['net_cents'] === 200
                && $totals['reversed_cents'] === 50);

        $this->actingAs($attendant)
            ->get(route('attendant.dashboard'))
            ->assertOk()
            ->assertViewHas('commissionSummary', fn (CommissionSummary $summary): bool => $summary->totalCents === 0);
    }

    public function test_attendant_only_sees_own_commission_and_client_cannot_access_admin_report(): void
    {
        $clientA = $this->client();
        $clientB = $this->client();
        [$attendantA] = $this->attendant(2500);
        [$attendantB] = $this->attendant(3000);

        $consultationA = Consultation::factory()->create([
            'client_id' => $clientA->id,
            'attendant_id' => $attendantA->id,
        ]);
        $consultationB = Consultation::factory()->create([
            'client_id' => $clientB->id,
            'attendant_id' => $attendantB->id,
        ]);

        CommissionLedgerEntry::factory()->create([
            'attendant_id' => $attendantA->id,
            'consultation_id' => $consultationA->id,
            'base_amount_cents' => 1000,
            'commission_amount_cents' => 250,
            'commission_rate_bps' => 2500,
            'idempotency_key' => 'test:commission:a',
        ]);

        CommissionLedgerEntry::factory()->create([
            'attendant_id' => $attendantB->id,
            'consultation_id' => $consultationB->id,
            'base_amount_cents' => 33330,
            'commission_amount_cents' => 9999,
            'commission_rate_bps' => 3000,
            'idempotency_key' => 'test:commission:b',
        ]);

        $this->actingAs($attendantA)
            ->get(route('attendant.dashboard'))
            ->assertOk()
            ->assertSee('R$ 2,50')
            ->assertDontSee('R$ 99,99');

        $this->actingAs($attendantA)
            ->get(route('admin.commissions.index'))
            ->assertForbidden();

        $this->actingAs($attendantA)
            ->get(route('admin.commissions.attendant', $attendantB))
            ->assertForbidden();

        $this->actingAs($clientA)
            ->get(route('admin.commissions.attendant', $attendantA))
            ->assertForbidden();
    }

    public function test_admin_report_filters_by_attendant_and_consultation(): void
    {
        $admin = User::factory()->admin()->create();
        $clientA = $this->client();
        $clientB = $this->client();
        [$attendantA] = $this->attendant(2500);
        [$attendantB] = $this->attendant(3000);

        $consultationA = Consultation::factory()->create([
            'client_id' => $clientA->id,
            'attendant_id' => $attendantA->id,
        ]);
        $consultationB = Consultation::factory()->create([
            'client_id' => $clientB->id,
            'attendant_id' => $attendantB->id,
        ]);

        CommissionLedgerEntry::factory()->create([
            'attendant_id' => $attendantA->id,
            'consultation_id' => $consultationA->id,
            'base_amount_cents' => 10000,
            'commission_amount_cents' => 2500,
            'commission_rate_bps' => 2500,
            'idempotency_key' => 'filter:commission:a',
        ]);

        CommissionLedgerEntry::factory()->create([
            'attendant_id' => $attendantB->id,
            'consultation_id' => $consultationB->id,
            'base_amount_cents' => 32100,
            'commission_amount_cents' => 7777,
            'commission_rate_bps' => 3000,
            'idempotency_key' => 'filter:commission:b',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.commissions.index', ['attendant_id' => $attendantA->id]))
            ->assertOk()
            ->assertSee('R$ 25,00')
            ->assertDontSee('R$ 77,77')
            ->assertViewHas('totals', fn (array $totals): bool => $totals['gross_cents'] === 10000
                && $totals['commission_cents'] === 2500
                && $totals['net_cents'] === 7500);

        $this->actingAs($admin)
            ->get(route('admin.commissions.index', ['consultation_id' => $consultationB->id]))
            ->assertOk()
            ->assertSee('R$ 77,77')
            ->assertDontSee('R$ 25,00')
            ->assertViewHas('totals', fn (array $totals): bool => $totals['gross_cents'] === 32100
                && $totals['commission_cents'] === 7777);
    }

    public function test_admin_report_filters_by_period(): void
    {
        $admin = User::factory()->admin()->create();
        [$attendant] = $this->attendant(2500);

        $this->travelTo('2026-01-10 12:00:00');

        CommissionLedgerEntry::factory()->create([
            'attendant_id' => $attendant->id,
            'base_amount_cents' => 10000,
            'commission_amount_cents' => 2500,
            'commission_rate_bps' => 2500,
            'idempotency_key' => 'period:january',
        ]);

        $this->travelTo('2026-02-10 12:00:00');

        CommissionLedgerEntry::factory()->create([
            'attendant_id' => $attendant->id,
            'base_amount_cents' => 20000,
            'commission_amount_cents' => 5000,
            'commission_rate_bps' => 2500,
            'idempotency_key' => 'period:february',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.commissions.index', [
                'date_from' => '2026-02-01',
                'date_to' => '2026-02-28',
            ]))
            ->assertOk()
            ->assertSee('R$ 50,00')
            ->assertDontSee('R$ 25,00')
            ->assertViewHas('totals', fn (array $totals): bool => $totals['gross_cents'] === 20000
                && $totals['commission_cents'] === 5000);
    }

    public function test_commission_ledger_entry_cannot_be_updated(): void
    {
        $entry = CommissionLedgerEntry::factory()->create();

        $this->expectException(LogicException::class);

        $entry->update(['commission_amount_cents' => 1]);
    }

    public function test_commission_ledger_entry_cannot_be_deleted(): void
    {
        $entry = CommissionLedgerEntry::factory()->create();

        $this->expectException(LogicException::class);

        $entry->delete();
    }

    public function test_started_consultation_commission_snapshot_cannot_be_changed(): void
    {
        $consultation = Consultation::factory()->active()->create([
            'commission_rate_bps' => 2500,
            'started_at' => now(),
        ]);

        $this->expectException(DomainException::class);

        $consultation->update(['commission_rate_bps' => 5000]);
    }
}
