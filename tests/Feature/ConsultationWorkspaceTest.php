<?php

namespace Tests\Feature;

use App\Enums\ConsultationEndReason;
use App\Enums\ConsultationStatus;
use App\Events\ConsultationMessageSent;
use App\Events\ConsultationStateChanged;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Support\CreditWalletManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Tela dedicada de atendimento e avisos em tempo real.
 *
 * Cobre o contrato consumido pelos módulos `consultation-workspace`, `chat` e
 * `user-channel`: números calculados no servidor, fila de solicitações,
 * autorização dos canais privados e envio instantâneo de mensagens.
 */
class ConsultationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('tarot_private');
        config(['broadcasting.default' => 'reverb']);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');

        Setting::query()->create(['key' => 'credit_unit_price_cents', 'value' => '150', 'type' => 'integer']);
        Setting::query()->create(['key' => 'minimum_balance_minutes', 'value' => '1', 'type' => 'integer']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function clientWithBalance(int $balanceCents): User
    {
        $client = User::factory()->create();
        Wallet::factory()->create(['user_id' => $client->id, 'balance_cents' => 0]);

        $reference = AuditLog::query()->create([
            'action' => 'test.ledger',
            'metadata' => ['key' => 'workspace-credit'],
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
        AttendantProfile::factory()
            ->online()
            ->for($attendant)
            ->withModalityPrices(['text' => 300])
            ->create(['commission_rate_bps' => 3000]);

        return $attendant;
    }

    private function activeConsultation(User $client, User $attendant): Consultation
    {
        return Consultation::factory()->active()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            // Snapshots congelados no aceite: preço e comissão nunca são
            // recalculados com a configuração atual do sistema.
            'price_per_minute_cents' => 300,
            'credit_unit_price_cents' => 150,
            'billed_minutes' => 2,
            'commission_rate_bps' => 3000,
        ]);
    }

    private function authorizeChannel(User $user, string $channel): TestResponse
    {
        return $this->actingAs($user)->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ]);
    }

    public function test_workspace_renders_server_calculated_numbers_for_both_participants(): void
    {
        Carbon::setTestNow('2026-02-11 12:00:00');

        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);

        $consultation->forceFill(['started_at' => now()->subSeconds(90)])->save();

        $expectedMetrics = fn (array $metrics): bool => $metrics['consultation_id'] === $consultation->id
            && $metrics['status'] === ConsultationStatus::Active->value
            && $metrics['billed_minutes'] === 2
            && $metrics['price_per_minute_cents'] === 300
            && $metrics['price_per_minute_label'] === 'R$ 3,00'
            && $metrics['charged_cents'] === 600
            && $metrics['charged_label'] === 'R$ 6,00'
            && $metrics['charged_explanation'] === '2 minutos iniciados'
            && $metrics['next_refresh_in_seconds'] === 30;

        $this->actingAs($attendant)
            ->get(route('consultations.workspace', $consultation))
            ->assertOk()
            ->assertSee('data-consultation-workspace', false)
            ->assertSee('data-metrics-url="'.route('consultations.metrics', $consultation).'"', false)
            ->assertSee('data-consultation-timer', false)
            ->assertSee('data-metric="charged_label"', false)
            ->assertSee('R$ 6,00')
            ->assertSee('data-metric="commission_label"', false)
            ->assertSee('30%')
            ->assertViewHas(
                'metrics',
                fn (array $metrics): bool => $expectedMetrics($metrics)
                    && $metrics['commission_cents'] === 0
                    && $metrics['commission_label'] === 'R$ 0,00'
                    && $metrics['commission_rate_label'] === '30%',
            );

        $this->actingAs($client)
            ->get(route('consultations.workspace', $consultation))
            ->assertOk()
            ->assertViewHas(
                'metrics',
                fn (array $metrics): bool => $expectedMetrics($metrics)
                    && $metrics['commission_cents'] === null
                    && $metrics['commission_label'] === null,
            );
    }

    public function test_workspace_sends_users_back_while_the_consultation_is_not_active(): void
    {
        $client = User::factory()->create();
        $attendant = $this->onlineAttendant();

        $requested = Consultation::factory()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
        ]);

        $this->actingAs($client)
            ->get(route('consultations.workspace', $requested))
            ->assertRedirect(route('consultations.show', $requested))
            ->assertSessionHas('status');

        $ended = Consultation::factory()->active()->create([
            'client_id' => User::factory()->create()->id,
            'attendant_id' => $attendant->id,
            'status' => ConsultationStatus::Ended->value,
            'started_at' => now()->subMinutes(5),
            'ended_at' => now(),
            'ended_reason' => ConsultationEndReason::ClientEnded->value,
        ]);

        $this->actingAs($attendant)
            ->get(route('consultations.workspace', $ended))
            ->assertRedirect(route('consultations.show', $ended))
            ->assertSessionHas('status');
    }

    public function test_workspace_is_forbidden_for_users_outside_the_consultation(): void
    {
        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);

        $this->actingAs(User::factory()->create())
            ->get(route('consultations.workspace', $consultation))
            ->assertForbidden();

        // O administrador enxerga a consulta, mas a tela de atendimento é
        // exclusiva de quem está atendendo ou sendo atendido.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('consultations.workspace', $consultation))
            ->assertRedirect(route('consultations.show', $consultation));
    }

    public function test_metrics_endpoint_returns_official_numbers_and_protects_the_commission(): void
    {
        Carbon::setTestNow('2026-02-11 12:00:00');

        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);

        $consultation->forceFill(['started_at' => now()->subSeconds(45)])->save();

        $this->actingAs($attendant)
            ->getJson(route('consultations.metrics', $consultation))
            ->assertOk()
            ->assertJsonPath('billed_minutes', 2)
            ->assertJsonPath('price_per_minute_cents', 300)
            ->assertJsonPath('charged_cents', 600)
            ->assertJsonPath('charged_label', 'R$ 6,00')
            ->assertJsonPath('commission_cents', 0)
            ->assertJsonPath('commission_label', 'R$ 0,00')
            ->assertJsonPath('commission_rate_label', '30%')
            ->assertJsonPath('elapsed_seconds', 45)
            ->assertJsonPath('next_refresh_in_seconds', 15);

        $this->actingAs($client)
            ->getJson(route('consultations.metrics', $consultation))
            ->assertOk()
            ->assertJsonPath('charged_cents', 600)
            ->assertJsonPath('commission_cents', null)
            ->assertJsonPath('commission_rate_label', null);

        $this->actingAs(User::factory()->create())
            ->getJson(route('consultations.metrics', $consultation))
            ->assertForbidden();
    }

    public function test_pending_requests_endpoint_lists_the_queue_in_arrival_order_with_decision_urls(): void
    {
        $attendant = $this->onlineAttendant();

        $first = Consultation::factory()->create([
            'client_id' => User::factory()->create(['name' => 'Cliente Antigo'])->id,
            'attendant_id' => $attendant->id,
            'created_at' => now()->subMinutes(4),
        ]);

        $second = Consultation::factory()->audio()->create([
            'client_id' => User::factory()->create(['name' => 'Cliente Novo'])->id,
            'attendant_id' => $attendant->id,
            'created_at' => now()->subMinute(),
        ]);

        $this->actingAs($attendant)
            ->getJson(route('attendant.requests.pending'))
            ->assertOk()
            ->assertJsonCount(2, 'requests')
            ->assertJsonPath('requests.0.id', $first->id)
            ->assertJsonPath('requests.0.client_name', 'Cliente Antigo')
            ->assertJsonPath('requests.0.status', ConsultationStatus::Requested->value)
            ->assertJsonPath('requests.0.accept_url', route('attendant.consultations.accept', $first))
            ->assertJsonPath('requests.0.reject_url', route('attendant.consultations.reject', $first))
            ->assertJsonPath('requests.0.workspace_url', route('consultations.workspace', $first))
            ->assertJsonPath('requests.1.id', $second->id)
            ->assertJsonPath('requests.1.modality', 'audio');

        // A fila não cresce com consultas já decididas.
        $first->forceFill(['status' => ConsultationStatus::Cancelled->value])->saveQuietly();

        $this->actingAs($attendant)
            ->getJson(route('attendant.requests.pending'))
            ->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.id', $second->id);

        // O mesmo endpoint é exclusivo do painel do atendente.
        $this->actingAs(User::factory()->create())
            ->getJson(route('attendant.requests.pending'))
            ->assertForbidden();
    }

    public function test_user_channel_authorization_is_limited_to_its_owner(): void
    {
        $attendant = $this->onlineAttendant();

        $this->authorizeChannel($attendant, 'private-user.'.$attendant->id)
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->authorizeChannel(User::factory()->create(), 'private-user.'.$attendant->id)
            ->assertForbidden();

        $attendant->update(['is_active' => false]);

        $this->authorizeChannel($attendant, 'private-user.'.$attendant->id)
            ->assertForbidden();
    }

    public function test_accept_returns_the_workspace_redirect_and_warns_both_participants(): void
    {
        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();

        $consultation = Consultation::factory()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
        ]);

        Event::fake([ConsultationStateChanged::class]);

        $this->actingAs($attendant)
            ->patchJson(route('attendant.consultations.accept', $consultation))
            ->assertOk()
            ->assertJsonPath('redirect', route('consultations.workspace', $consultation))
            ->assertJsonPath('consultation.status', ConsultationStatus::Active->value);

        $this->assertSame(ConsultationStatus::Active, $consultation->fresh()->status);

        Event::assertDispatchedTimes(ConsultationStateChanged::class, 1);
        Event::assertDispatched(ConsultationStateChanged::class, function (ConsultationStateChanged $event) use ($client, $attendant, $consultation): bool {
            $channels = array_map(fn ($channel): string => $channel->name, $event->broadcastOn());

            $this->assertSame($consultation->id, $event->consultationId);
            $this->assertSame('consultation.state', $event->broadcastAs());
            $this->assertSame('active', $event->consultation['status']);
            $this->assertContains('private-consultation.'.$consultation->id, $channels);
            $this->assertContains('private-user.'.$client->id, $channels);
            $this->assertContains('private-user.'.$attendant->id, $channels);

            return true;
        });

        // A fila do atendente fica vazia assim que o aceite é confirmado.
        $this->actingAs($attendant)
            ->getJson(route('attendant.requests.pending'))
            ->assertOk()
            ->assertJsonCount(0, 'requests');
    }

    public function test_attendant_cannot_accept_a_second_consultation_while_busy(): void
    {
        $attendant = $this->onlineAttendant();
        $busyClient = $this->clientWithBalance(1000);
        $waitingClient = $this->clientWithBalance(1000);

        $this->activeConsultation($busyClient, $attendant);

        $waiting = Consultation::factory()->create([
            'client_id' => $waitingClient->id,
            'attendant_id' => $attendant->id,
        ]);

        $this->actingAs($attendant)
            ->patchJson(route('attendant.consultations.accept', $waiting))
            ->assertStatus(422)
            ->assertJsonValidationErrors('consultation');

        $this->assertSame(ConsultationStatus::Requested, $waiting->fresh()->status);
        $this->assertSame(1000, (int) Wallet::query()->where('user_id', $waitingClient->id)->value('balance_cents'));
    }

    public function test_instant_message_send_returns_the_persisted_message_and_broadcasts_it(): void
    {
        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);

        Event::fake([ConsultationMessageSent::class]);

        $this->actingAs($client)
            ->postJson(route('consultations.messages.store', $consultation), ['body' => 'Oi, tudo bem?'])
            ->assertCreated()
            ->assertJsonPath('message.body', 'Oi, tudo bem?')
            ->assertJsonPath('message.sender_id', $client->id)
            ->assertJsonPath('consultation.status', ConsultationStatus::Active->value);

        $this->assertDatabaseHas('consultation_messages', [
            'consultation_id' => $consultation->id,
            'sender_id' => $client->id,
            'body' => 'Oi, tudo bem?',
        ]);

        Event::assertDispatched(ConsultationMessageSent::class, function (ConsultationMessageSent $event) use ($consultation): bool {
            $this->assertSame($consultation->id, $event->consultationId);
            $this->assertSame('private-consultation.'.$consultation->id, $event->broadcastOn()[0]->name);
            $this->assertSame('consultation.message', $event->broadcastAs());
            $this->assertSame('Oi, tudo bem?', $event->message['body']);

            return true;
        });

        // Envio sem JavaScript continua funcionando pelo formulário HTML.
        $this->actingAs($attendant)
            ->post(route('consultations.messages.store', $consultation), ['body' => 'Tudo ótimo!'])
            ->assertRedirect(route('consultations.show', $consultation).'#chat');
    }

    public function test_consultation_channel_authorization_is_limited_to_the_two_participants(): void
    {
        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);

        $channel = 'private-consultation.'.$consultation->id;

        $this->authorizeChannel($client, $channel)
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->authorizeChannel($attendant, $channel)
            ->assertOk()
            ->assertJsonStructure(['auth']);

        // Nem outro usuário autenticado nem o administrador ouvem a conversa.
        $this->authorizeChannel(User::factory()->create(), $channel)
            ->assertForbidden();

        $this->authorizeChannel(User::factory()->admin()->create(), $channel)
            ->assertForbidden();

        // Conta desativada perde o acesso mesmo sendo participante.
        $client->update(['is_active' => false]);

        $this->authorizeChannel($client, $channel)
            ->assertForbidden();
    }

    public function test_simultaneous_accepts_keep_a_single_active_consultation_and_charge_once(): void
    {
        $attendant = $this->onlineAttendant();
        $firstClient = $this->clientWithBalance(1000);
        $secondClient = $this->clientWithBalance(1000);

        $first = Consultation::factory()->create([
            'client_id' => $firstClient->id,
            'attendant_id' => $attendant->id,
        ]);

        $second = Consultation::factory()->create([
            'client_id' => $secondClient->id,
            'attendant_id' => $attendant->id,
        ]);

        // Dois aceites quase simultâneos: o primeiro trava o atendente.
        $this->actingAs($attendant)
            ->patchJson(route('attendant.consultations.accept', $first))
            ->assertOk();

        $this->actingAs($attendant)
            ->patchJson(route('attendant.consultations.accept', $second))
            ->assertStatus(422)
            ->assertJsonValidationErrors('consultation');

        $this->assertSame(ConsultationStatus::Active, $first->fresh()->status);
        $this->assertSame(ConsultationStatus::Requested, $second->fresh()->status);

        $this->assertSame(
            1,
            Consultation::query()
                ->where('attendant_id', $attendant->id)
                ->where('status', ConsultationStatus::Active->value)
                ->count(),
        );

        // Só a consulta aceita cobrou o primeiro minuto.
        $this->assertSame(700, (int) Wallet::query()->where('user_id', $firstClient->id)->value('balance_cents'));
        $this->assertSame(1000, (int) Wallet::query()->where('user_id', $secondClient->id)->value('balance_cents'));

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'consultation.accepted')
                ->where('subject_id', $first->id)
                ->count(),
        );
    }

    public function test_workspace_reload_restores_the_conversation_and_clears_the_unread_notice(): void
    {
        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);

        ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $client->id,
            'body' => 'Primeira mensagem',
        ]);

        ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $attendant->id,
            'body' => 'Segunda mensagem',
        ]);

        ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $client->id,
            'body' => 'Terceira mensagem',
        ]);

        $firstLoad = $this->actingAs($client)
            ->get(route('consultations.workspace', $consultation))
            ->assertOk()
            ->assertSee('data-chat-timeline', false)
            ->assertSee('data-feed-url="'.route('consultations.messages.index', $consultation).'"', false);

        $firstLoad->assertSeeInOrder(['Primeira mensagem', 'Segunda mensagem', 'Terceira mensagem']);

        // A mensagem do atendente ainda não lida aparece no aviso da visita.
        $firstLoad->assertSee('1 mensagem nova foi recebida');

        // Recarregar a tela (queda de rede, F5) mantém o histórico e a leitura
        // já registrada, sem repetir o aviso de mensagens novas.
        $this->actingAs($client)
            ->get(route('consultations.workspace', $consultation))
            ->assertOk()
            ->assertSeeInOrder(['Primeira mensagem', 'Segunda mensagem', 'Terceira mensagem'])
            ->assertDontSee('mensagem nova foi recebida');

        $this->assertSame(
            3,
            ConsultationMessage::query()->where('consultation_id', $consultation->id)->count(),
        );
    }

    public function test_attendant_panel_exposes_the_realtime_queue_hooks(): void
    {
        $attendant = $this->onlineAttendant();

        $this->actingAs($attendant)
            ->get(route('attendant.dashboard'))
            ->assertOk()
            ->assertSee('data-user-channel', false)
            ->assertSee('data-user-id="'.$attendant->id.'"', false)
            ->assertSee('data-pending-url="'.route('attendant.requests.pending').'"', false)
            ->assertSee('data-workspace-url="'.route('consultations.workspace', ['consultation' => '__ID__']).'"', false)
            ->assertSee('data-requests-list', false)
            ->assertSee('data-requests-empty', false)
            ->assertSee('data-pending-count', false)
            ->assertSee('data-request-modal', false)
            ->assertSee('data-request-accept', false)
            ->assertSee('data-request-reject', false);
    }

    /**
     * O modal de aceite/recusa (com a sirene) vem do layout do painel, então
     * precisa existir em toda tela do atendente — não só no dashboard.
     */
    public function test_request_modal_follows_the_attendant_across_panel_screens(): void
    {
        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);

        $assertHooks = function (TestResponse $response) use ($attendant): void {
            $response
                ->assertOk()
                ->assertSee('data-user-channel', false)
                ->assertSee('data-user-id="'.$attendant->id.'"', false)
                ->assertSee('data-pending-url="'.route('attendant.requests.pending').'"', false)
                ->assertSee('data-workspace-url="'.route('consultations.workspace', ['consultation' => '__ID__']).'"', false)
                ->assertSee('data-request-modal', false)
                ->assertSee('data-request-accept', false)
                ->assertSee('data-request-reject', false);
        };

        $assertHooks($this->actingAs($attendant)->get(route('attendant.history')));
        $assertHooks($this->actingAs($attendant)->get(route('consultations.show', $consultation)));
        $assertHooks($this->actingAs($attendant)->get(route('consultations.workspace', $consultation)));
        $assertHooks($this->actingAs($attendant)->get(route('attendant.dashboard')));
    }

    /**
     * O cliente também precisa do canal fora do dashboard (é ele que o leva à
     * tela de atendimento quando o aceite acontece), mas nunca do modal de
     * resposta nem da fila de solicitações.
     */
    public function test_client_panel_keeps_the_user_channel_without_the_request_modal(): void
    {
        $client = $this->clientWithBalance(1000);

        $this->actingAs($client)
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('data-user-channel', false)
            ->assertSee('data-user-id="'.$client->id.'"', false)
            ->assertSee('data-workspace-url="'.route('consultations.workspace', ['consultation' => '__ID__']).'"', false)
            ->assertDontSee('data-pending-url', false)
            ->assertDontSee('data-request-modal', false);

        $this->actingAs($client)
            ->get(route('client.history'))
            ->assertOk()
            ->assertSee('data-user-channel', false)
            ->assertDontSee('data-request-modal', false);
    }

    /**
     * A administração fica fora do canal `user.{id}`: a autorização do canal e
     * a política de acesso à conversa não incluem o admin (D-023/Q-06).
     */
    public function test_admin_panel_does_not_subscribe_to_the_user_channel(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('data-user-channel', false)
            ->assertDontSee('data-request-modal', false);
    }
}
