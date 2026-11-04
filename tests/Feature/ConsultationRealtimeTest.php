<?php

namespace Tests\Feature;

use App\Enums\ConsultationEndReason;
use App\Enums\ConsultationStatus;
use App\Events\ConsultationMessageSent;
use App\Events\ConsultationRequested;
use App\Events\ConsultationStateChanged;
use App\Models\AttendantProfile;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\TarotDeck;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ConsultationRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('tarot_private');
        config(['broadcasting.default' => 'reverb']);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');
    }

    /**
     * @return array{0: User, 1: User, 2: Consultation}
     */
    private function conversation(): array
    {
        $client = User::factory()->create();
        Wallet::factory()->create([
            'user_id' => $client->id,
            'balance_cents' => 10_000,
        ]);
        $attendant = User::factory()->attendant()->create();

        $consultation = Consultation::factory()->active()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
        ]);

        return [$client, $attendant, $consultation];
    }

    /**
     * @return array{0: TarotCard, 1: TarotDeck}
     */
    private function card(): array
    {
        $deck = TarotDeck::factory()->create([
            'name' => 'Baralho Solar',
            'is_active' => true,
        ]);

        $image = $this->imageBytes();
        $path = 'cards/'.Str::random(32).'.png';
        Storage::disk('tarot_private')->put($path, $image);

        $card = TarotCard::factory()->create([
            'deck_id' => $deck->id,
            'name' => 'A Estrela',
            'image_path' => $path,
            'image_mime' => 'image/png',
            'image_size' => strlen($image),
            'is_active' => true,
        ]);

        return [$card, $deck];
    }

    private function imageBytes(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
    }

    private function authorizeChannel(User $user, Consultation $consultation): TestResponse
    {
        return $this->actingAs($user)->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-consultation.'.$consultation->id,
        ]);
    }

    private function authorizeUserChannel(User $user, int $channelUserId): TestResponse
    {
        return $this->actingAs($user)->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-user.'.$channelUserId,
        ]);
    }

    public function test_private_consultation_channel_allows_only_active_participants(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();

        $this->authorizeChannel($client, $consultation)
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->authorizeChannel($attendant, $consultation)
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->authorizeChannel(User::factory()->create(), $consultation)
            ->assertForbidden();

        $this->authorizeChannel(User::factory()->admin()->create(), $consultation)
            ->assertForbidden();

        $client->update(['is_active' => false]);

        $this->authorizeChannel($client->fresh(), $consultation)
            ->assertForbidden();
    }

    public function test_private_user_channel_only_allows_the_owner(): void
    {
        $client = User::factory()->create();
        $attendant = User::factory()->attendant()->create();

        $this->authorizeUserChannel($attendant, $attendant->id)
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->authorizeUserChannel($client, $attendant->id)
            ->assertForbidden();

        $attendant->update(['is_active' => false]);

        $this->authorizeUserChannel($attendant->fresh(), $attendant->id)
            ->assertForbidden();
    }

    /**
     * O aviso de nova solicitação é o que toca a sirene e abre o modal no
     * painel do atendente. Ele vai para o canal privado do usuário (e não para
     * o da consulta) e carrega apenas dados operacionais.
     */
    public function test_consultation_request_is_announced_on_the_attendant_private_channel(): void
    {
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

        $client = User::factory()->create();
        Wallet::factory()->create([
            'user_id' => $client->id,
            'balance_cents' => 10_000,
        ]);

        $attendant = User::factory()->attendant()->create();
        AttendantProfile::factory()->online()->for($attendant)->create();

        Event::fake([ConsultationRequested::class]);

        $this->actingAs($client)
            ->post(route('client.consultations.store'), ['attendant_id' => $attendant->id])
            ->assertRedirect();

        $consultation = Consultation::query()->sole();

        Event::assertDispatchedTimes(ConsultationRequested::class, 1);
        Event::assertDispatched(ConsultationRequested::class, function (ConsultationRequested $event) use ($attendant, $client, $consultation): bool {
            $this->assertSame($attendant->id, $event->attendantId);
            $this->assertSame('private-user.'.$attendant->id, $event->broadcastOn()[0]->name);
            $this->assertSame('consultation.request', $event->broadcastAs());

            $request = $event->broadcastWith()['request'];

            $this->assertSame($consultation->id, $request['id']);
            $this->assertSame(ConsultationStatus::Requested->value, $request['status']);
            $this->assertSame($client->name, $request['client_name']);
            $this->assertSame(route('attendant.consultations.accept', $consultation), $request['accept_url']);
            $this->assertSame(route('attendant.consultations.reject', $consultation), $request['reject_url']);
            $this->assertArrayNotHasKey('balance_cents', $request);
            $this->assertArrayNotHasKey('commission_cents', $request);
            $this->assertArrayNotHasKey('wallet', $request);

            return true;
        });
    }

    public function test_text_message_is_persisted_before_broadcast_with_safe_payload(): void
    {
        [$client, , $consultation] = $this->conversation();

        Event::fake([ConsultationMessageSent::class]);

        $this->actingAs($client)
            ->post(route('consultations.messages.store', $consultation), [
                'body' => 'Mensagem em tempo real',
            ])
            ->assertRedirect(route('consultations.show', $consultation).'#chat');

        Event::assertDispatched(ConsultationMessageSent::class, function (ConsultationMessageSent $event) use ($client, $consultation): bool {
            $message = $event->message;

            $this->assertSame($consultation->id, $event->consultationId);
            $this->assertSame('private-consultation.'.$consultation->id, $event->broadcastOn()[0]->name);
            $this->assertSame('consultation.message', $event->broadcastAs());
            $this->assertSame($message, $event->broadcastWith()['message']);
            $this->assertSame('text', $message['type']);
            $this->assertSame('Mensagem em tempo real', $message['body']);
            $this->assertSame($client->id, $message['sender_id']);
            $this->assertSame($client->name, $message['sender_name']);
            $this->assertSame('client', $message['sender_role']);
            $this->assertNull($message['image_url']);
            $this->assertArrayNotHasKey('image_path', $message);

            $this->assertDatabaseHas('consultation_messages', [
                'id' => $message['id'],
                'consultation_id' => $consultation->id,
                'body' => 'Mensagem em tempo real',
            ]);

            return true;
        });
    }

    public function test_card_event_exposes_only_authorized_image_url_and_no_private_path(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();
        [$card] = $this->card();

        Event::fake([ConsultationMessageSent::class]);

        $this->actingAs($attendant)
            ->post(route('consultations.cards.store', $consultation), [
                'tarot_card_id' => $card->id,
            ])
            ->assertRedirect(route('consultations.show', $consultation).'#chat');

        $cardMessage = ConsultationMessage::query()->sole();

        Event::assertDispatched(ConsultationMessageSent::class, function (ConsultationMessageSent $event) use ($cardMessage, $consultation): bool {
            $message = $event->message;

            $this->assertSame('tarot_card', $message['type']);
            $this->assertSame($cardMessage->id, $message['id']);
            $this->assertSame('A Estrela', $message['card_name']);
            $this->assertSame('Baralho Solar', $message['deck_name']);
            $this->assertSame(
                route('consultations.messages.image', [$consultation, $cardMessage]),
                $message['image_url'],
            );
            $this->assertArrayNotHasKey('image_path', $message);
            $this->assertStringNotContainsString($cardMessage->image_path, json_encode($event->broadcastWith()));

            return true;
        });

        $this->actingAs($client)
            ->get(route('consultations.messages.image', [$consultation, $cardMessage]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAs(User::factory()->create())
            ->get(route('consultations.messages.image', [$consultation, $cardMessage]))
            ->assertForbidden();
    }

    public function test_feed_recovers_messages_after_last_seen_id_and_returns_state(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();

        $first = ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $client->id,
            'body' => 'Primeira',
        ]);

        $second = ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $attendant->id,
            'body' => 'Segunda',
        ]);

        $third = ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $client->id,
            'body' => 'Terceira',
        ]);

        $this->actingAs($client)
            ->getJson(route('consultations.messages.index', $consultation).'?after_id='.$first->id)
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.id', $second->id)
            ->assertJsonPath('messages.1.id', $third->id)
            ->assertJsonPath('last_message_id', $third->id)
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('consultation.status', ConsultationStatus::Active->value)
            ->assertJsonPath('consultation.status_label', 'Em andamento')
            ->assertJsonMissingPath('messages.0.image_path');

        $this->actingAs(User::factory()->create())
            ->getJson(route('consultations.messages.index', $consultation))
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('consultations.messages.index', $consultation))
            ->assertForbidden();

        $consultation->update([
            'status' => ConsultationStatus::Ended->value,
            'ended_at' => now(),
            'ended_reason' => ConsultationEndReason::ClientEnded->value,
        ]);

        $this->actingAs($client)
            ->getJson(route('consultations.messages.index', $consultation).'?after_id='.$third->id)
            ->assertOk()
            ->assertJsonCount(0, 'messages')
            ->assertJsonPath('consultation.status', ConsultationStatus::Ended->value);
    }

    public function test_realtime_read_endpoint_clears_unread_messages(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();

        ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $attendant->id,
            'body' => 'Nova mensagem',
        ]);

        $this->assertSame(1, $consultation->fresh()->unreadMessageCountFor($client));

        $this->actingAs($client)
            ->postJson(route('consultations.messages.read', $consultation))
            ->assertNoContent();

        $this->assertNotNull($consultation->fresh()->client_last_read_at);
        $this->assertSame(0, $consultation->fresh()->unreadMessageCountFor($client));

        $this->actingAs(User::factory()->create())
            ->postJson(route('consultations.messages.read', $consultation))
            ->assertForbidden();
    }

    public function test_state_change_is_broadcast_after_commit_only_when_state_actually_changes(): void
    {
        [$client, , $consultation] = $this->conversation();

        Event::fake([ConsultationStateChanged::class]);

        $this->actingAs($client)
            ->patch(route('consultations.end', $consultation))
            ->assertRedirect(route('consultations.show', $consultation))
            ->assertSessionHas('success');

        Event::assertDispatchedTimes(ConsultationStateChanged::class, 1);
        Event::assertDispatched(ConsultationStateChanged::class, function (ConsultationStateChanged $event) use ($consultation): bool {
            $state = $event->consultation;

            $this->assertSame($consultation->id, $event->consultationId);
            $this->assertSame('private-consultation.'.$consultation->id, $event->broadcastOn()[0]->name);
            $this->assertSame('consultation.state', $event->broadcastAs());
            $this->assertSame(ConsultationStatus::Ended->value, $state['status']);
            $this->assertSame(ConsultationEndReason::ClientEnded->value, $state['ended_reason']);
            $this->assertFalse($state['allows_messaging']);
            $this->assertArrayNotHasKey('messages', $state);

            $this->assertDatabaseHas('consultations', [
                'id' => $consultation->id,
                'status' => ConsultationStatus::Ended->value,
            ]);

            return true;
        });

        $this->actingAs($client)
            ->patch(route('consultations.end', $consultation))
            ->assertRedirect(route('consultations.show', $consultation));

        Event::assertDispatchedTimes(ConsultationStateChanged::class, 1);
    }

    /**
     * O Reverb compara a origem do WebSocket apenas pelo host
     * (`Protocols/Pusher/Server::verifyOrigin()` usa `parse_url(..., PHP_URL_HOST)`).
     * Com a URL completa na lista, o handshake é aceito (101) e a conexão cai
     * logo depois com `4009 Origin not allowed`: nenhum evento chega ao
     * navegador e o chat só atualiza pela busca periódica.
     */
    public function test_reverb_allowed_origins_are_hosts_because_only_the_origin_host_is_compared(): void
    {
        $origins = config('reverb.apps.apps.0.allowed_origins');

        $this->assertIsArray($origins);
        $this->assertNotEmpty($origins);
        $this->assertContains(parse_url((string) config('app.url'), PHP_URL_HOST), $origins);

        foreach ($origins as $origin) {
            $this->assertIsString($origin);
            $this->assertStringNotContainsString('://', $origin);
            $this->assertStringNotContainsString('/', $origin);
            $this->assertStringNotContainsString(':', $origin);
        }
    }

    public function test_reverb_allowed_origins_are_normalized_to_hosts_and_keep_wildcards(): void
    {
        $this->assertSame(
            ['localhost', 'arcana.test', '*.arcana.test', '127.0.0.1', '*'],
            $this->reverbAllowedOriginsFor('http://localhost:8000, https://arcana.test/, *.arcana.test, 127.0.0.1:8080, *'),
        );
    }

    /**
     * Executa `config/reverb.php` com um valor específico de
     * `REVERB_ALLOWED_ORIGINS`, sem depender do `.env` do ambiente.
     *
     * @return array<int, string>
     */
    private function reverbAllowedOriginsFor(string $value): array
    {
        $previous = [
            'env' => $_ENV['REVERB_ALLOWED_ORIGINS'] ?? null,
            'server' => $_SERVER['REVERB_ALLOWED_ORIGINS'] ?? null,
            'putenv' => getenv('REVERB_ALLOWED_ORIGINS'),
        ];

        $restore = static function () use ($previous): void {
            if ($previous['env'] === null) {
                unset($_ENV['REVERB_ALLOWED_ORIGINS']);
            } else {
                $_ENV['REVERB_ALLOWED_ORIGINS'] = $previous['env'];
            }

            if ($previous['server'] === null) {
                unset($_SERVER['REVERB_ALLOWED_ORIGINS']);
            } else {
                $_SERVER['REVERB_ALLOWED_ORIGINS'] = $previous['server'];
            }

            if ($previous['putenv'] === false) {
                putenv('REVERB_ALLOWED_ORIGINS');
            } else {
                putenv('REVERB_ALLOWED_ORIGINS='.$previous['putenv']);
            }
        };

        $_ENV['REVERB_ALLOWED_ORIGINS'] = $value;
        $_SERVER['REVERB_ALLOWED_ORIGINS'] = $value;
        putenv('REVERB_ALLOWED_ORIGINS='.$value);

        try {
            $config = require config_path('reverb.php');
        } finally {
            $restore();
        }

        return $config['apps']['apps'][0]['allowed_origins'];
    }
}
