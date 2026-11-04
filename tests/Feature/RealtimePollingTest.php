<?php

namespace Tests\Feature;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\ConsultationPresenceSession;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Support\Realtime\RealtimeMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Atendimento em modo polling (hospedagem sem processo persistente).
 *
 * Cobre as três garantias do modo: cada usuário recebe apenas o que é seu, o
 * cursor de mensagens devolve somente o que é novo e nenhuma dessas leituras
 * periódicas altera atividade, presença, leitura ou cobrança.
 */
class RealtimePollingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: User, 2: Consultation}
     */
    private function conversation(): array
    {
        $client = User::factory()->create();
        $attendant = User::factory()->attendant()->create();

        $consultation = Consultation::factory()->active()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
        ]);

        return [$client, $attendant, $consultation];
    }

    public function test_panel_feed_requires_authentication(): void
    {
        $this->get(route('panel.events'))->assertRedirect(route('login'));
    }

    public function test_admin_has_no_panel_feed(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson(route('panel.events'))->assertForbidden();
    }

    public function test_attendant_feed_lists_only_own_pending_requests(): void
    {
        $attendant = User::factory()->attendant()->create();
        $otherAttendant = User::factory()->attendant()->create();

        $own = Consultation::factory()->create([
            'client_id' => User::factory()->create()->id,
            'attendant_id' => $attendant->id,
            'status' => ConsultationStatus::Requested->value,
        ]);

        // Pedido de outro atendente: nunca pode aparecer na fila deste.
        Consultation::factory()->create([
            'client_id' => User::factory()->create()->id,
            'attendant_id' => $otherAttendant->id,
            'status' => ConsultationStatus::Requested->value,
        ]);

        $response = $this->actingAs($attendant)->getJson(route('panel.events'))->assertOk();

        $response->assertJsonCount(1, 'requests');
        $response->assertJsonPath('requests.0.id', $own->id);
        $response->assertJsonPath('realtime_mode', RealtimeMode::Polling);

        // `consultation` traz apenas o próprio pedido em aberto; a fila tem
        // exatamente um item, então o pedido do outro atendente não vaza.
        $response->assertJsonPath('consultation.id', $own->id);
        $response->assertJsonPath('consultation.status', ConsultationStatus::Requested->value);
    }

    public function test_client_feed_never_exposes_attendant_requests(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();

        Consultation::factory()->create([
            'client_id' => User::factory()->create()->id,
            'attendant_id' => $attendant->id,
            'status' => ConsultationStatus::Requested->value,
        ]);

        $response = $this->actingAs($client)->getJson(route('panel.events'))->assertOk();

        $response->assertJsonCount(0, 'requests');
        $response->assertJsonPath('consultation.id', $consultation->id);
        $response->assertJsonPath('consultation.status', ConsultationStatus::Active->value);
    }

    public function test_message_feed_returns_only_messages_after_the_cursor(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();

        $first = ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $client->id,
            'body' => 'Primeira mensagem',
        ]);

        $second = ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $attendant->id,
            'body' => 'Segunda mensagem',
        ]);

        $response = $this->actingAs($client)->getJson(route('consultations.messages.index', [
            'consultation' => $consultation,
            'after_id' => $first->id,
        ]))->assertOk();

        $response->assertJsonCount(1, 'messages');
        $response->assertJsonPath('messages.0.id', $second->id);
        $response->assertJsonPath('last_message_id', $second->id);
        $response->assertJsonPath('consultation.status', ConsultationStatus::Active->value);
    }

    public function test_polling_never_counts_as_activity_presence_or_billing(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();

        $lastActivity = now()->subMinutes(30)->startOfSecond();
        $consultation->forceFill([
            'last_activity_at' => $lastActivity,
            'client_last_activity_at' => $lastActivity,
            'attendant_last_activity_at' => $lastActivity,
            'billed_minutes' => 3,
        ])->save();

        ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $attendant->id,
        ]);

        $this->actingAs($client)->getJson(route('panel.events'))->assertOk();
        $this->actingAs($client)
            ->getJson(route('consultations.messages.index', $consultation))
            ->assertOk();
        $this->actingAs($attendant)->getJson(route('panel.events'))->assertOk();
        $this->actingAs($attendant)
            ->getJson(route('consultations.messages.index', $consultation))
            ->assertOk();

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Active, $consultation->status);
        $this->assertSame(3, (int) $consultation->billed_minutes);
        $this->assertTrue($lastActivity->equalTo($consultation->last_activity_at));
        $this->assertTrue($lastActivity->equalTo($consultation->client_last_activity_at));
        $this->assertTrue($lastActivity->equalTo($consultation->attendant_last_activity_at));
        $this->assertSame(0, ConsultationPresenceSession::query()->count());
        $this->assertSame(0, CreditLedgerEntry::query()->count());
    }

    public function test_panel_feed_is_rate_limited(): void
    {
        [$client] = $this->conversation();

        config(['consultation.realtime.panel_feed_rate_per_minute' => 2]);

        $this->actingAs($client)->getJson(route('panel.events'))->assertOk();
        $this->actingAs($client)->getJson(route('panel.events'))->assertOk();
        $this->actingAs($client)->getJson(route('panel.events'))->assertTooManyRequests();
    }

    public function test_realtime_mode_follows_the_server_configuration(): void
    {
        config([
            'consultation.realtime.websocket_enabled' => true,
            'broadcasting.default' => 'reverb',
        ]);

        $this->assertSame(RealtimeMode::Socket, RealtimeMode::current());

        // Sem broadcaster WebSocket (driver nulo, log ou ausente) o modo é poll.
        foreach ([null, 'null', 'log'] as $driver) {
            config(['broadcasting.default' => $driver]);

            $this->assertSame(RealtimeMode::Polling, RealtimeMode::current());
        }

        config([
            'broadcasting.default' => 'reverb',
            'consultation.realtime.websocket_enabled' => false,
        ]);

        $this->assertSame(RealtimeMode::Polling, RealtimeMode::current());
    }

    public function test_pages_publish_the_polling_configuration(): void
    {
        [$client, , $consultation] = $this->conversation();

        $response = $this->actingAs($client)->get(route('consultations.show', $consultation));
        $response->assertOk();

        $response->assertSee('data-realtime-mode="'.RealtimeMode::Polling.'"', false);

        $response->assertSee('data-chat', false);

        $response->assertSee('data-poll-interval-ms="'.(int) config('consultation.realtime.poll.chat_interval_ms').'"', false);
        $response->assertSee('data-poll-hidden-interval-ms="'.(int) config('consultation.realtime.poll.chat_hidden_interval_ms').'"', false);
        $response->assertSee('data-poll-max-backoff-ms="'.(int) config('consultation.realtime.poll.max_backoff_ms').'"', false);
        $response->assertSee('data-events-url="'.route('panel.events').'"', false);
    }
}
