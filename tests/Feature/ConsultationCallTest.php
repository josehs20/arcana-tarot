<?php

namespace Tests\Feature;

use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Enums\ConsultationEndReason;
use App\Enums\ConsultationModality;
use App\Enums\ConsultationStatus;
use App\Enums\LedgerEntryType;
use App\Models\AttendantProfile;
use App\Models\CommissionLedgerEntry;
use App\Models\Consultation;
use App\Models\ConsultationCall;
use App\Models\ConsultationCallSignal;
use App\Models\CreditLedgerEntry;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Support\ConsultationLifecycleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chamadas reais de audio e video (WebRTC).
 *
 * A sinalizacao e transportada pelo servidor e a midia e negociada entre os
 * navegadores. Os testes cobrem solicitacao, aceite, troca de sinais,
 * conexao, recusa, desligamento, expiracao e permissoes.
 */
class ConsultationCallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::query()->create(['key' => 'credit_unit_price_cents', 'value' => '100', 'type' => 'integer']);
        Setting::query()->create(['key' => 'rate_credits_per_minute', 'value' => '1', 'type' => 'integer']);
        Setting::query()->create(['key' => 'rate_credits_per_minute_text', 'value' => '1', 'type' => 'integer']);
        Setting::query()->create(['key' => 'rate_credits_per_minute_audio', 'value' => '2', 'type' => 'integer']);
        Setting::query()->create(['key' => 'rate_credits_per_minute_video', 'value' => '4', 'type' => 'integer']);
        Setting::query()->create(['key' => 'minimum_balance_minutes', 'value' => '1', 'type' => 'integer']);
    }

    private function client(): User
    {
        $client = User::factory()->create();
        Wallet::factory()->create([
            'user_id' => $client->getKey(),
            'balance_cents' => 10_000,
        ]);

        return $client;
    }

    /**
     * @return array{0: User, 1: AttendantProfile}
     */
    private function attendant(): array
    {
        $user = User::factory()->attendant()->create();
        $profile = AttendantProfile::factory()
            ->online()
            ->offeringAllModalities()
            ->for($user)
            ->create(['commission_rate_bps' => 3000]);

        return [$user, $profile];
    }

    private function activeConsultation(User $client, User $attendant, string $modality = 'audio'): Consultation
    {
        $lifecycle = app(ConsultationLifecycleManager::class);

        $consultation = $lifecycle->request($client, $attendant, ConsultationModality::from($modality));

        return $lifecycle->accept($attendant, $consultation);
    }

    public function test_client_requests_audio_call_and_attendant_accepts_then_signals_connect(): void
    {
        $client = $this->client();
        [$attendant] = $this->attendant();
        $consultation = $this->activeConsultation($client, $attendant, 'audio');

        $this->actingAs($client)
            ->getJson(route('consultations.call.state', $consultation))
            ->assertOk()
            ->assertJsonPath('call', null)
            ->assertJsonPath('role', 'client')
            ->assertJsonPath('consultation.modality', 'audio')
            ->assertJsonPath('ice_servers.0.urls', (string) config('consultation.web_rtc.ice_servers.0.urls'));

        $response = $this->actingAs($client)
            ->postJson(route('consultations.call.store', $consultation))
            ->assertOk()
            ->assertJsonPath('call.status', 'ringing')
            ->assertJsonPath('call.modality', 'audio');

        $callId = $response->json('call.id');

        $this->actingAs($client)
            ->postJson(route('consultations.call.store', $consultation))
            ->assertOk()
            ->assertJsonPath('call.id', $callId);

        $this->assertSame(1, ConsultationCall::query()->count());

        $this->actingAs($attendant)
            ->patchJson(route('consultations.call.accept', $consultation))
            ->assertOk()
            ->assertJsonPath('call.status', 'accepted');

        $offer = $this->actingAs($client)
            ->postJson(route('consultations.call.signal', $consultation), [
                'type' => 'offer',
                'payload' => ['type' => 'offer', 'sdp' => 'v=0\r\no=- 1 1 IN IP4 0.0.0.0\r\n'],
            ])
            ->assertCreated()
            ->assertJsonPath('signal.type', 'offer');

        $offerId = $offer->json('signal.id');

        $this->actingAs($attendant)
            ->getJson(route('consultations.call.state', $consultation).'?after=0')
            ->assertOk()
            ->assertJsonPath('signals.0.id', $offerId)
            ->assertJsonPath('signals.0.type', 'offer');

        $this->actingAs($attendant)
            ->postJson(route('consultations.call.signal', $consultation), [
                'type' => 'answer',
                'payload' => ['type' => 'answer', 'sdp' => 'v=0\r\no=- 2 2 IN IP4 0.0.0.0\r\n'],
            ])
            ->assertCreated();

        $this->actingAs($client)
            ->getJson(route('consultations.call.state', $consultation).'?after='.$offerId)
            ->assertOk()
            ->assertJsonPath('signals.0.type', 'answer');

        $this->actingAs($client)
            ->patchJson(route('consultations.call.connected', $consultation))
            ->assertOk()
            ->assertJsonPath('call.status', 'connected');

        $this->actingAs($client)
            ->patchJson(route('consultations.call.end', $consultation))
            ->assertOk()
            ->assertJsonPath('call.status', 'ended')
            ->assertJsonPath('call.ended_reason', 'hangup');

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Ended, $consultation->status);
        $this->assertSame(ConsultationEndReason::ClientEnded, $consultation->ended_reason);
    }

    public function test_video_consultation_exposes_camera_control_and_video_flag(): void
    {
        $client = $this->client();
        [$attendant] = $this->attendant();
        $consultation = $this->activeConsultation($client, $attendant, 'video');

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('Chamada de vídeo')
            ->assertSee('data-requires-video="1"', false)
            ->assertSee('data-call-camera', false)
            ->assertSee('Desligar câmera')
            ->assertSee('data-call-local', false);
    }

    public function test_text_consultation_cannot_start_a_call(): void
    {
        $client = $this->client();
        [$attendant] = $this->attendant();
        $consultation = $this->activeConsultation($client, $attendant, 'text');

        $this->actingAs($client)
            ->postJson(route('consultations.call.store', $consultation))
            ->assertForbidden();

        $this->assertSame(0, ConsultationCall::query()->count());
    }

    public function test_video_call_is_blocked_when_modality_is_disabled(): void
    {
        config(['consultation.modalities.video.enabled' => false]);

        $client = $this->client();
        [$attendant] = $this->attendant();

        $consultation = Consultation::factory()->active()->video()->create([
            'client_id' => $client->getKey(),
            'attendant_id' => $attendant->getKey(),
            'rate_credits_per_minute' => 4,
            'credit_unit_price_cents' => 100,
            'price_per_minute_cents' => 400,
            'billed_minutes' => 1,
        ]);

        $this->actingAs($client)
            ->postJson(route('consultations.call.store', $consultation))
            ->assertStatus(422)
            ->assertJsonValidationErrors('call');
    }

    public function test_non_participants_cannot_read_or_signal_a_call(): void
    {
        $client = $this->client();
        [$attendant] = $this->attendant();
        $outsider = User::factory()->create();
        $consultation = $this->activeConsultation($client, $attendant, 'audio');

        $this->actingAs($client)
            ->postJson(route('consultations.call.store', $consultation))
            ->assertOk();

        $this->actingAs($outsider)
            ->getJson(route('consultations.call.state', $consultation))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->postJson(route('consultations.call.signal', $consultation), [
                'type' => 'offer',
                'payload' => ['sdp' => 'v=0'],
            ])
            ->assertForbidden();

        $this->actingAs($outsider)
            ->patchJson(route('consultations.call.accept', $consultation))
            ->assertForbidden();
    }

    public function test_client_cannot_accept_or_reject_its_own_call(): void
    {
        $client = $this->client();
        [$attendant] = $this->attendant();
        $consultation = $this->activeConsultation($client, $attendant, 'audio');

        $this->actingAs($client)
            ->postJson(route('consultations.call.store', $consultation))
            ->assertOk();

        $this->actingAs($client)
            ->patchJson(route('consultations.call.accept', $consultation))
            ->assertForbidden();

        $this->actingAs($client)
            ->patchJson(route('consultations.call.reject', $consultation))
            ->assertForbidden();
    }

    public function test_signals_are_rejected_before_attendant_accepts_the_call(): void
    {
        $client = $this->client();
        [$attendant] = $this->attendant();
        $consultation = $this->activeConsultation($client, $attendant, 'audio');

        $this->actingAs($client)
            ->postJson(route('consultations.call.store', $consultation))
            ->assertOk();

        $this->actingAs($client)
            ->postJson(route('consultations.call.signal', $consultation), [
                'type' => 'offer',
                'payload' => ['sdp' => 'v=0'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('call');

        $this->assertSame(0, ConsultationCallSignal::query()->count());
    }

    public function test_attendant_rejecting_a_call_ends_consultation_without_duplicate_charge(): void
    {
        $client = $this->client();
        [$attendant] = $this->attendant();
        $consultation = $this->activeConsultation($client, $attendant, 'audio');

        $this->actingAs($client)
            ->postJson(route('consultations.call.store', $consultation))
            ->assertOk();

        $this->actingAs($attendant)
            ->patchJson(route('consultations.call.reject', $consultation))
            ->assertOk()
            ->assertJsonPath('call.status', 'failed')
            ->assertJsonPath('call.ended_reason', 'rejected');

        $this->actingAs($attendant)
            ->patchJson(route('consultations.call.reject', $consultation))
            ->assertOk();

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Ended, $consultation->status);
        $this->assertSame(ConsultationEndReason::CallFailed, $consultation->ended_reason);
        $this->assertSame(
            1,
            CreditLedgerEntry::query()
                ->where('type', LedgerEntryType::Debit->value)
                ->where('reference_id', $consultation->getKey())
                ->count(),
        );
        $this->assertSame(
            1,
            CommissionLedgerEntry::query()->where('consultation_id', $consultation->getKey())->count(),
        );
    }

    public function test_call_state_remains_readable_after_consultation_ends_and_new_calls_are_blocked(): void
    {
        $client = $this->client();
        [$attendant] = $this->attendant();
        $consultation = $this->activeConsultation($client, $attendant, 'audio');

        $this->actingAs($client)
            ->postJson(route('consultations.call.store', $consultation))
            ->assertOk();

        $this->actingAs($attendant)
            ->patchJson(route('consultations.call.accept', $consultation))
            ->assertOk();

        $this->actingAs($attendant)
            ->patchJson(route('consultations.call.end', $consultation))
            ->assertOk()
            ->assertJsonPath('call.ended_reason', 'hangup');

        $this->actingAs($client)
            ->getJson(route('consultations.call.state', $consultation))
            ->assertOk()
            ->assertJsonPath('call.status', 'ended')
            ->assertJsonPath('consultation.status', 'ended');

        $this->actingAs($client)
            ->postJson(route('consultations.call.store', $consultation))
            ->assertStatus(422)
            ->assertJsonValidationErrors('call');

        $this->assertSame(1, ConsultationCall::query()->count());
    }

    public function test_accepted_call_that_never_connects_is_closed_after_timeout(): void
    {
        $client = $this->client();
        [$attendant] = $this->attendant();
        $consultation = $this->activeConsultation($client, $attendant, 'audio');

        $call = ConsultationCall::query()->create([
            'consultation_id' => $consultation->getKey(),
            'requested_by_id' => $client->getKey(),
            'modality' => 'audio',
            'status' => CallStatus::Accepted->value,
            'accepted_at' => now()->subMinutes(5),
        ]);

        $this->artisan('consultations:close-stale-calls')->assertExitCode(0);

        $call->refresh();
        $consultation->refresh();

        $this->assertSame(CallStatus::Failed, $call->status);
        $this->assertSame(CallEndReason::Timeout, $call->ended_reason);
        $this->assertSame(ConsultationStatus::Ended, $consultation->status);
    }

    public function test_stale_ringing_calls_are_failed_by_scheduled_command(): void
    {
        $client = $this->client();
        [$attendant] = $this->attendant();
        $consultation = $this->activeConsultation($client, $attendant, 'video');

        $call = ConsultationCall::query()->create([
            'consultation_id' => $consultation->getKey(),
            'requested_by_id' => $client->getKey(),
            'modality' => 'video',
            'status' => CallStatus::Ringing->value,
        ]);

        $call->forceFill(['created_at' => now()->subMinutes(5)])->saveQuietly();

        $this->artisan('consultations:close-stale-calls')->assertExitCode(0);

        $call->refresh();
        $consultation->refresh();

        $this->assertSame(CallStatus::Failed, $call->status);
        $this->assertSame(CallEndReason::Timeout, $call->ended_reason);
        $this->assertSame(ConsultationStatus::Ended, $consultation->status);
        $this->assertSame(ConsultationEndReason::CallFailed, $consultation->ended_reason);
    }
}
