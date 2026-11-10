<?php

namespace Tests\Feature;

use App\Enums\AttendantAvailability;
use App\Enums\ConsultationEndReason;
use App\Enums\ConsultationStatus;
use App\Enums\MessageType;
use App\Events\ConsultationRequested;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use Carbon\Carbon;
use Database\Factories\AttendantProfileFactory;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ConsultationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::query()->create([
            'key' => 'credit_unit_price_cents',
            'value' => '150',
            'type' => 'integer',
        ]);

        Setting::query()->create([
            'key' => 'minimum_balance_minutes',
            'value' => '1',
            'type' => 'integer',
        ]);
    }

    private function client(int $balanceCents = 10_000, array $attributes = []): User
    {
        $client = User::factory()->create($attributes);
        Wallet::factory()->create([
            'user_id' => $client->id,
            'balance_cents' => $balanceCents,
        ]);

        return $client;
    }

    /**
     * @return array{0: User, 1: AttendantProfile}
     */
    private function onlineAttendant(array $attributes = [], array $userAttributes = []): array
    {
        $attendant = User::factory()->attendant()->create($userAttributes);
        $profile = AttendantProfile::factory()->online()->for($attendant)->create($attributes);

        return [$attendant, $profile];
    }

    private function requestConsultation(User $client, User $attendant): Consultation
    {
        $this->actingAs($client)
            ->post(route('client.consultations.store'), ['attendant_id' => $attendant->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        return Consultation::query()->latest('id')->firstOrFail();
    }

    public function test_client_can_request_and_cancel_a_consultation(): void
    {
        $client = $this->client();
        [$attendant] = $this->onlineAttendant();

        $consultation = $this->requestConsultation($client, $attendant);

        $this->assertSame(ConsultationStatus::Requested, $consultation->status);
        $this->assertSame($client->id, $consultation->client_id);
        $this->assertSame($attendant->id, $consultation->attendant_id);
        $this->assertNull($consultation->started_at);
        $this->assertDatabaseHas('consultations', [
            'id' => $consultation->id,
            'status' => ConsultationStatus::Requested->value,
        ]);

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('Solicitada')
            ->assertSee('Cancelar solicitação')
            ->assertDontSee('Aceitar consulta');

        $this->actingAs($client)
            ->patch(route('client.consultations.cancel', $consultation))
            ->assertRedirect(route('consultations.show', $consultation))
            ->assertSessionHas('success');

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Cancelled, $consultation->status);
        $this->assertSame(ConsultationEndReason::Cancelled, $consultation->ended_reason);
        $this->assertNotNull($consultation->ended_at);

        $this->actingAs($client)
            ->patch(route('client.consultations.cancel', $consultation))
            ->assertRedirect(route('consultations.show', $consultation))
            ->assertSessionHas('success', 'Esta solicitação já havia sido cancelada.');

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'consultation.cancelled')
                ->where('subject_id', $consultation->id)
                ->count(),
        );
    }

    public function test_client_cannot_have_two_open_consultations(): void
    {
        $client = $this->client();
        [$attendant] = $this->onlineAttendant();
        [$otherAttendant] = $this->onlineAttendant();

        $this->requestConsultation($client, $attendant);

        $this->actingAs($client)
            ->from(route('client.dashboard'))
            ->post(route('client.consultations.store'), ['attendant_id' => $otherAttendant->id])
            ->assertRedirect(route('client.dashboard'))
            ->assertSessionHasErrors('consultation');

        $this->assertSame(
            1,
            Consultation::query()->where('client_id', $client->id)->count(),
        );
    }

    public function test_unavailable_attendant_cannot_receive_requests(): void
    {
        $client = $this->client();

        $offline = User::factory()->attendant()->create();
        AttendantProfile::factory()->offline()->for($offline)->create();

        $unpublished = User::factory()->attendant()->create();
        AttendantProfile::factory()->online()->for($unpublished)->create(['is_published' => false]);

        $busy = User::factory()->attendant()->create();
        AttendantProfile::factory()->busy()->for($busy)->create();

        $this->actingAs($client)
            ->from(route('attendants.index'))
            ->post(route('client.consultations.store'), ['attendant_id' => $offline->id])
            ->assertRedirect(route('attendants.index'))
            ->assertSessionHasErrors('attendant_id');

        $this->actingAs($client)
            ->from(route('attendants.index'))
            ->post(route('client.consultations.store'), ['attendant_id' => $unpublished->id])
            ->assertRedirect(route('attendants.index'))
            ->assertSessionHasErrors('attendant_id');

        // Ocupado pausa a fila: continua no diretório, mas não recebe pedidos.
        $this->actingAs($client)
            ->from(route('attendants.index'))
            ->post(route('client.consultations.store'), ['attendant_id' => $busy->id])
            ->assertRedirect(route('attendants.index'))
            ->assertSessionHasErrors('attendant_id');

        $this->assertSame(0, Consultation::query()->count());
    }

    public function test_attendant_accept_activates_consultation_and_accept_is_idempotent(): void
    {
        $client = $this->client();
        [$attendant, $profile] = $this->onlineAttendant(['commission_rate_bps' => 2500]);

        $consultation = $this->requestConsultation($client, $attendant);

        $this->actingAs($attendant)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('Aceitar consulta')
            ->assertSee('Recusar solicitação');

        $this->actingAs($attendant)
            ->patch(route('attendant.consultations.accept', $consultation))
            ->assertRedirect(route('consultations.workspace', $consultation))
            ->assertSessionHas('success');

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Active, $consultation->status);
        $this->assertNotNull($consultation->started_at);
        $this->assertSame(2500, $consultation->commission_rate_bps);
        $this->assertSame(AttendantProfileFactory::DEFAULT_PRICE_PER_MINUTE_CENTS, $consultation->price_per_minute_cents);
        $this->assertNull($consultation->rate_credits_per_minute);
        $this->assertSame(150, $consultation->credit_unit_price_cents);
        $this->assertNotNull($profile->fresh()->last_seen_at);

        $this->actingAs($attendant)
            ->patch(route('attendant.consultations.accept', $consultation))
            ->assertRedirect(route('consultations.workspace', $consultation))
            ->assertSessionHas('success', 'Esta consulta já estava em andamento.');

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'consultation.accepted')
                ->where('subject_id', $consultation->id)
                ->count(),
        );
    }

    public function test_attendant_cannot_accept_after_the_client_spends_the_balance(): void
    {
        $client = $this->client();
        [$attendant] = $this->onlineAttendant();

        $consultation = $this->requestConsultation($client, $attendant);

        /*
         * O saldo pode ser gasto entre a solicitação e o aceite — por isso o
         * aceite revalida o mínimo mesmo depois da checagem feita no pedido.
         */
        Wallet::query()->where('user_id', $client->id)->update(['balance_cents' => 100]);

        $this->actingAs($attendant)
            ->from(route('attendant.dashboard'))
            ->patch(route('attendant.consultations.accept', $consultation))
            ->assertRedirect(route('attendant.dashboard'))
            ->assertSessionHasErrors('consultation');

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Requested, $consultation->status);
        $this->assertNull($consultation->started_at);
        $this->assertNull($consultation->price_per_minute_cents);
    }

    public function test_request_is_blocked_without_the_minimum_balance(): void
    {
        // Preço do minuto 200 + 1 minuto mínimo = R$ 2,00 exigidos.
        $client = $this->client(150);
        [$attendant, $profile] = $this->onlineAttendant();

        Event::fake([ConsultationRequested::class]);

        $this->actingAs($client)
            ->from(route('attendants.show', $profile))
            ->post(route('client.consultations.store'), ['attendant_id' => $attendant->id])
            ->assertRedirect(route('attendants.show', $profile))
            ->assertSessionHasErrors('consultation');

        $this->assertStringContainsString(
            'Saldo insuficiente',
            (string) session('errors')->first('consultation'),
        );

        // Nada é criado nem anunciado: a solicitação nunca chega ao atendente.
        $this->assertSame(0, Consultation::query()->count());
        Event::assertNotDispatched(ConsultationRequested::class);
    }

    public function test_request_is_allowed_with_exactly_the_minimum_balance(): void
    {
        $client = $this->client(200);
        [$attendant] = $this->onlineAttendant();

        $consultation = $this->requestConsultation($client, $attendant);

        $this->assertSame(ConsultationStatus::Requested, $consultation->status);
    }

    public function test_attendant_must_be_online_to_accept(): void
    {
        $client = $this->client();
        [$attendant, $profile] = $this->onlineAttendant();
        $consultation = $this->requestConsultation($client, $attendant);

        $profile->update(['availability' => AttendantAvailability::Offline]);

        $this->actingAs($attendant)
            ->from(route('attendant.dashboard'))
            ->patch(route('attendant.consultations.accept', $consultation))
            ->assertRedirect(route('attendant.dashboard'))
            ->assertSessionHasErrors('consultation');

        $this->assertSame(ConsultationStatus::Requested, $consultation->fresh()->status);
    }

    public function test_attendant_can_reject_and_reject_is_idempotent(): void
    {
        $client = $this->client();
        [$attendant] = $this->onlineAttendant();
        $consultation = $this->requestConsultation($client, $attendant);

        $this->actingAs($attendant)
            ->patch(route('attendant.consultations.reject', $consultation))
            ->assertRedirect(route('consultations.show', $consultation))
            ->assertSessionHas('success');

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Rejected, $consultation->status);
        $this->assertSame(ConsultationEndReason::Rejected, $consultation->ended_reason);

        $this->actingAs($attendant)
            ->patch(route('attendant.consultations.reject', $consultation))
            ->assertRedirect(route('consultations.show', $consultation))
            ->assertSessionHas('success', 'Esta solicitação já havia sido recusada.');

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'consultation.rejected')
                ->where('subject_id', $consultation->id)
                ->count(),
        );
    }

    public function test_rejection_ends_the_chat_for_the_client_and_frees_a_new_request(): void
    {
        $client = $this->client();
        [$attendant] = $this->onlineAttendant();
        $consultation = $this->requestConsultation($client, $attendant);

        $this->actingAs($attendant)
            ->patch(route('attendant.consultations.reject', $consultation))
            ->assertRedirect(route('consultations.show', $consultation))
            ->assertSessionHas('success');

        /*
         * A recusa encerra a solicitação: o cliente vê o estado final, perde o
         * botão de cancelar e não fica esperando um aceite que não vem.
         */
        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('Recusada')
            ->assertSee('o chat foi encerrado')
            ->assertDontSee('Cancelar solicitação')
            ->assertDontSee('Aguardando aceite')
            // Gancho que faz o modo polling recarregar quando a consulta fecha.
            ->assertSee('data-consultation-watch="'.$consultation->id.'"', false);

        $retry = $this->requestConsultation($client, $attendant);

        $this->assertNotSame($consultation->id, $retry->id);
        $this->assertSame(ConsultationStatus::Requested, $retry->status);
    }

    public function test_only_participants_and_admins_can_view_a_consultation(): void
    {
        $client = $this->client();
        [$attendant] = $this->onlineAttendant();
        $consultation = Consultation::factory()->active()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
        ]);

        $outsiderClient = $this->client();
        $otherAttendant = User::factory()->attendant()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk();

        $this->actingAs($attendant)
            ->get(route('consultations.show', $consultation))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('consultations.show', $consultation))
            ->assertOk();

        $this->actingAs($outsiderClient)
            ->get(route('consultations.show', $consultation))
            ->assertForbidden();

        $this->actingAs($otherAttendant)
            ->get(route('consultations.show', $consultation))
            ->assertForbidden();
    }

    public function test_transition_endpoints_enforce_role_and_participation(): void
    {
        $client = $this->client();
        [$attendant] = $this->onlineAttendant();
        $consultation = $this->requestConsultation($client, $attendant);

        $otherAttendant = User::factory()->attendant()->create();

        $this->actingAs($client)
            ->patch(route('attendant.consultations.accept', $consultation))
            ->assertForbidden();

        $this->actingAs($otherAttendant)
            ->patch(route('attendant.consultations.accept', $consultation))
            ->assertForbidden();

        $this->actingAs($attendant)
            ->patch(route('client.consultations.cancel', $consultation))
            ->assertForbidden();

        $this->assertSame(ConsultationStatus::Requested, $consultation->fresh()->status);
    }

    public function test_client_can_end_an_active_consultation_and_repeat_is_idempotent(): void
    {
        $client = $this->client();
        [$attendant] = $this->onlineAttendant();
        $consultation = Consultation::factory()->active()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
        ]);

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('Encerrar consulta');

        $this->actingAs($client)
            ->patch(route('consultations.end', $consultation))
            ->assertRedirect(route('consultations.show', $consultation))
            ->assertSessionHas('success');

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Ended, $consultation->status);
        $this->assertSame(ConsultationEndReason::ClientEnded, $consultation->ended_reason);
        $this->assertNotNull($consultation->ended_at);

        $this->actingAs($attendant)
            ->patch(route('consultations.end', $consultation))
            ->assertRedirect(route('consultations.show', $consultation))
            ->assertSessionHas('success', 'Esta consulta já estava encerrada.');

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'consultation.ended')
                ->where('subject_id', $consultation->id)
                ->count(),
        );
    }

    public function test_client_ending_releases_attendant_without_recent_presence(): void
    {
        $client = $this->client();
        [$attendant, $profile] = $this->onlineAttendant();
        $consultation = Consultation::factory()->active()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
        ]);

        $profile->update(['last_seen_at' => now()->subMinutes(30)]);

        $this->actingAs($client)
            ->patch(route('consultations.end', $consultation))
            ->assertRedirect(route('consultations.show', $consultation))
            ->assertSessionHas('success');

        $consultation->refresh();
        $profile->refresh();

        $this->assertSame(ConsultationStatus::Ended, $consultation->status);
        $this->assertSame(ConsultationEndReason::ClientEnded, $consultation->ended_reason);
        $this->assertSame(AttendantAvailability::Offline, $profile->availability);
    }

    public function test_accepting_freezes_availability_as_busy_and_ending_returns_online(): void
    {
        $client = $this->client();
        [$attendant, $profile] = $this->onlineAttendant();
        $consultation = $this->requestConsultation($client, $attendant);

        $this->assertSame(AttendantAvailability::Online, $profile->fresh()->availability);

        $this->actingAs($attendant)
            ->patch(route('attendant.consultations.accept', $consultation))
            ->assertRedirect(route('consultations.workspace', $consultation));

        $this->assertSame(AttendantAvailability::Busy, $profile->fresh()->availability);

        // Durante a consulta o atendente ocupado não recebe novos pedidos.
        $otherClient = $this->client();

        $this->actingAs($otherClient)
            ->from(route('attendants.index'))
            ->post(route('client.consultations.store'), ['attendant_id' => $attendant->id])
            ->assertRedirect(route('attendants.index'))
            ->assertSessionHasErrors('attendant_id');

        $this->actingAs($client)
            ->patch(route('consultations.end', $consultation))
            ->assertRedirect(route('consultations.show', $consultation))
            ->assertSessionHas('success');

        $this->assertSame(ConsultationStatus::Ended, $consultation->fresh()->status);
        $this->assertSame(AttendantAvailability::Online, $profile->fresh()->availability);
    }

    public function test_inactive_client_cannot_request_a_consultation(): void
    {
        $client = $this->client(attributes: ['is_active' => false]);
        [$attendant] = $this->onlineAttendant();

        $this->actingAs($client)
            ->post(route('client.consultations.store'), ['attendant_id' => $attendant->id])
            ->assertForbidden();

        $this->assertSame(0, Consultation::query()->count());
    }

    public function test_expire_command_closes_stale_requests_only(): void
    {
        Carbon::setTestNow('2026-01-10 12:00:00');

        $client = $this->client();
        [$attendant] = $this->onlineAttendant();

        $stale = Consultation::factory()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        $otherClient = $this->client();
        [$otherAttendant] = $this->onlineAttendant();

        $fresh = Consultation::factory()->create([
            'client_id' => $otherClient->id,
            'attendant_id' => $otherAttendant->id,
        ]);

        $this->artisan('consultations:expire-requests')
            ->expectsOutput('Solicitações expiradas: 1.')
            ->assertExitCode(0);

        $this->assertSame(ConsultationStatus::Expired, $stale->fresh()->status);
        $this->assertSame(ConsultationEndReason::Expired, $stale->fresh()->ended_reason);
        $this->assertSame(ConsultationStatus::Requested, $fresh->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_interrupt_command_closes_only_sessions_silent_beyond_tolerance(): void
    {
        Carbon::setTestNow('2026-01-10 12:00:00');

        $client = $this->client();
        [$attendant, $profile] = $this->onlineAttendant();
        $disconnected = Consultation::factory()->active()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
        ]);
        $profile->update(['last_seen_at' => now()->subMinutes(30)]);

        $otherClient = $this->client();
        [$presentAttendant] = $this->onlineAttendant();
        $healthy = Consultation::factory()->active()->create([
            'client_id' => $otherClient->id,
            'attendant_id' => $presentAttendant->id,
        ]);

        $disconnected->forceFill(['last_activity_at' => now()->subMinutes(10)])->save();
        $healthy->forceFill(['last_activity_at' => now()])->save();

        $this->artisan('consultations:interrupt-disconnected')
            ->expectsOutput('Consultas interrompidas: 1.')
            ->assertExitCode(0);

        $this->assertSame(ConsultationStatus::Interrupted, $disconnected->fresh()->status);
        $this->assertSame(ConsultationEndReason::Inactivity, $disconnected->fresh()->ended_reason);
        $this->assertSame(ConsultationStatus::Active, $healthy->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_messages_are_only_allowed_in_active_consultations(): void
    {
        $client = $this->client();
        [$attendant] = $this->onlineAttendant();
        $consultation = Consultation::factory()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
        ]);

        try {
            ConsultationMessage::factory()->create([
                'consultation_id' => $consultation->id,
                'sender_id' => $client->id,
            ]);
            $this->fail('Mensagens deveriam ser bloqueadas fora de consulta ativa.');
        } catch (DomainException $exception) {
            $this->assertSame('Só é possível enviar mensagens em uma consulta ativa.', $exception->getMessage());
        }

        $consultation->update([
            'status' => ConsultationStatus::Active->value,
            'started_at' => now(),
        ]);

        $text = ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $client->id,
            'type' => MessageType::Text->value,
        ]);

        $this->assertSame($client->id, $text->sender_id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Apenas o atendente pode enviar cartas do tarô.');

        ConsultationMessage::query()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $client->id,
            'type' => MessageType::TarotCard->value,
            'body' => null,
        ]);
    }
}
