<?php

namespace Tests\Feature;

use App\Enums\ConsultationEndReason;
use App\Enums\ConsultationStatus;
use App\Enums\LedgerEntryType;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\CreditLedgerEntry;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Support\ConsultationBillingManager;
use App\Support\ConsultationLifecycleManager;
use App\Support\CreditWalletManager;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsultationBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::query()->create(['key' => 'rate_credits_per_minute', 'value' => '2', 'type' => 'integer']);
        Setting::query()->create(['key' => 'credit_unit_price_cents', 'value' => '150', 'type' => 'integer']);
        Setting::query()->create(['key' => 'minimum_balance_minutes', 'value' => '1', 'type' => 'integer']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function billing(): ConsultationBillingManager
    {
        return app(ConsultationBillingManager::class);
    }

    private function clientWithBalance(int $balanceCents): User
    {
        $client = User::factory()->create();
        Wallet::factory()->create(['user_id' => $client->id, 'balance_cents' => 0]);

        $reference = AuditLog::query()->create([
            'action' => 'test.ledger',
            'metadata' => ['key' => 'billing-credit'],
        ]);

        app(CreditWalletManager::class)->credit(
            $client,
            $balanceCents,
            'test:credit:'.Str::uuid(),
            $reference,
        );

        return $client;
    }

    private function onlineAttendant(): User
    {
        $attendant = User::factory()->attendant()->create();
        AttendantProfile::factory()->online()->for($attendant)->create(['commission_rate_bps' => 3000]);

        return $attendant;
    }

    private function activeConsultation(
        User $client,
        User $attendant,
        Carbon $startedAt,
        int $rate = 2,
        int $unitPriceCents = 150,
    ): Consultation {
        return Consultation::factory()->active()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            'started_at' => $startedAt,
            'rate_credits_per_minute' => $rate,
            'credit_unit_price_cents' => $unitPriceCents,
            'billed_minutes' => 0,
        ]);
    }

    private function walletBalance(User $client): int
    {
        return (int) Wallet::query()->where('user_id', $client->id)->value('balance_cents');
    }

    private function debits(): Builder
    {
        return CreditLedgerEntry::query()->where('type', LedgerEntryType::Debit->value);
    }

    private function debitCount(): int
    {
        return $this->debits()->count();
    }

    public function test_accept_charges_first_minute_and_timer_is_informational(): void
    {
        Carbon::setTestNow('2026-01-10 12:00:00');

        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();

        $this->actingAs($client)->post(route('client.consultations.store'), [
            'attendant_id' => $attendant->id,
        ])->assertRedirect();

        $consultation = Consultation::query()->latest('id')->firstOrFail();

        $this->actingAs($attendant)
            ->patch(route('attendant.consultations.accept', $consultation))
            ->assertRedirect(route('consultations.workspace', $consultation))
            ->assertSessionHas('success');

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Active, $consultation->status);
        $this->assertSame(1, $consultation->billed_minutes);
        $this->assertSame(700, $this->walletBalance($client));

        $entry = $this->debits()->sole();

        $this->assertSame(300, $entry->amount_cents);
        $this->assertSame('consultation:'.$consultation->id.':minute:1', $entry->idempotency_key);
        $this->assertSame(Consultation::class, $entry->reference_type);
        $this->assertSame($consultation->id, $entry->reference_id);

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('data-consultation-timer', false)
            ->assertSee('Total cobrado')
            ->assertSee('R$ 3,00');

        $this->artisan('consultations:charge-minutes')->assertExitCode(0);

        $this->assertSame(1, $this->debitCount());
        $this->assertSame(700, $this->walletBalance($client));
    }

    public function test_billing_advances_by_started_minute_without_duplicates(): void
    {
        Carbon::setTestNow('2026-01-10 12:00:00');

        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant, now());

        $first = $this->billing()->billDue($consultation);

        $this->assertSame(1, $first->dueMinutes);
        $this->assertSame(1, $first->billedMinutes);
        $this->assertSame(1, $first->chargedMinutes);
        $this->assertTrue($first->isFullyBilled());

        $repeat = $this->billing()->billDue($consultation->fresh());

        $this->assertSame(0, $repeat->chargedMinutes);
        $this->assertSame(1, $repeat->billedMinutes);

        Carbon::setTestNow('2026-01-10 12:02:10');

        $third = $this->billing()->billDue($consultation->fresh());

        $this->assertSame(3, $third->dueMinutes);
        $this->assertSame(3, $third->billedMinutes);
        $this->assertSame(2, $third->chargedMinutes);
        $this->assertSame(600, $third->chargedCents);
        $this->assertSame(100, $third->balanceAfterCents);

        $this->artisan('consultations:charge-minutes')->assertExitCode(0);

        $this->assertSame(3, $this->debitCount());
        $this->assertSame(100, $this->walletBalance($client));
        $this->assertSame(
            ['consultation:'.$consultation->id.':minute:1', 'consultation:'.$consultation->id.':minute:2', 'consultation:'.$consultation->id.':minute:3'],
            $this->debits()->orderBy('id')->pluck('idempotency_key')->all(),
        );

    }

    public function test_minute_boundary_only_charges_after_the_next_minute_starts(): void
    {
        Carbon::setTestNow('2026-01-10 12:00:00');

        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant, now());

        $this->billing()->billDue($consultation);

        Carbon::setTestNow('2026-01-10 12:01:00');

        $atBoundary = $this->billing()->billDue($consultation->fresh());

        $this->assertSame(1, $atBoundary->dueMinutes);
        $this->assertSame(0, $atBoundary->chargedMinutes);

        Carbon::setTestNow('2026-01-10 12:01:01');

        $afterBoundary = $this->billing()->billDue($consultation->fresh());

        $this->assertSame(2, $afterBoundary->dueMinutes);
        $this->assertSame(1, $afterBoundary->chargedMinutes);
        $this->assertSame(2, $this->debitCount());
    }

    public function test_command_closes_session_without_balance_and_never_goes_negative(): void
    {
        Carbon::setTestNow('2026-01-10 12:00:00');

        $client = $this->clientWithBalance(300);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant, now());

        $this->artisan('consultations:charge-minutes')->assertExitCode(0);

        $this->assertSame(0, $this->walletBalance($client));
        $this->assertSame(1, $consultation->fresh()->billed_minutes);

        Carbon::setTestNow('2026-01-10 12:01:01');

        $this->artisan('consultations:charge-minutes')->assertExitCode(0);

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Ended, $consultation->status);
        $this->assertSame(ConsultationEndReason::OutOfCredits, $consultation->ended_reason);
        $this->assertSame(1, $consultation->billed_minutes);
        $this->assertSame(0, $this->walletBalance($client));
        $this->assertSame(1, $this->debitCount());

        $this->artisan('consultations:charge-minutes')->assertExitCode(0);

        $this->assertSame(1, $this->debitCount());
        $this->assertSame(1, $consultation->fresh()->billed_minutes);
    }

    public function test_manual_end_settles_last_minute_and_stops_future_charging(): void
    {
        Carbon::setTestNow('2026-01-10 12:00:00');

        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant, now());

        $this->billing()->billDue($consultation);

        Carbon::setTestNow('2026-01-10 12:02:30');

        $ended = app(ConsultationLifecycleManager::class)->end($client, $consultation->fresh());

        $this->assertSame(ConsultationStatus::Ended, $ended->status);
        $this->assertSame(ConsultationEndReason::ClientEnded, $ended->ended_reason);
        $this->assertSame(3, $ended->billed_minutes);
        $this->assertSame(100, $this->walletBalance($client));
        $this->assertSame(3, $this->debitCount());

        Carbon::setTestNow('2026-01-10 12:10:00');

        $this->artisan('consultations:charge-minutes')->assertExitCode(0);

        $this->assertSame(3, $this->debitCount());
        $this->assertSame(100, $this->walletBalance($client));
        $this->assertSame(3, $ended->fresh()->billed_minutes);
    }

    public function test_manual_end_without_balance_ends_as_out_of_credits_without_partial_debit(): void
    {
        Carbon::setTestNow('2026-01-10 12:00:00');

        $client = $this->clientWithBalance(300);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant, now());

        $this->billing()->billDue($consultation);

        Carbon::setTestNow('2026-01-10 12:02:30');

        $ended = app(ConsultationLifecycleManager::class)->end($client, $consultation->fresh());

        $this->assertSame(ConsultationStatus::Ended, $ended->status);
        $this->assertSame(ConsultationEndReason::OutOfCredits, $ended->ended_reason);
        $this->assertSame(1, $ended->billed_minutes);
        $this->assertSame(0, $this->walletBalance($client));
        $this->assertSame(1, $this->debitCount());
    }

    public function test_disconnect_settles_open_minutes_and_keeps_disconnect_reason(): void
    {
        Carbon::setTestNow('2026-01-10 12:00:00');

        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant, now());

        $this->billing()->billDue($consultation);

        Carbon::setTestNow('2026-01-10 12:02:30');

        $interrupted = app(ConsultationLifecycleManager::class)->interrupt(
            $consultation->fresh(),
            ConsultationEndReason::Disconnected,
        );

        $this->assertSame(ConsultationStatus::Interrupted, $interrupted->status);
        $this->assertSame(ConsultationEndReason::Disconnected, $interrupted->ended_reason);
        $this->assertSame(3, $interrupted->billed_minutes);
        $this->assertSame(100, $this->walletBalance($client));

        Carbon::setTestNow('2026-01-10 12:20:00');

        $this->artisan('consultations:charge-minutes')->assertExitCode(0);

        $this->assertSame(3, $this->debitCount());
    }

    public function test_rate_snapshot_is_used_instead_of_current_settings(): void
    {
        Carbon::setTestNow('2026-01-10 12:00:00');

        $client = $this->clientWithBalance(2000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant, now(), rate: 2, unitPriceCents: 150);

        Setting::query()->where('key', 'rate_credits_per_minute')->update(['value' => '5']);
        Setting::query()->where('key', 'credit_unit_price_cents')->update(['value' => '200']);

        $result = $this->billing()->billDue($consultation);

        $this->assertSame(1, $result->chargedMinutes);
        $this->assertSame(300, $result->chargedCents);
        $this->assertSame(1700, $this->walletBalance($client));
        $this->assertSame(300, $this->debits()->sole()->amount_cents);
    }
}
