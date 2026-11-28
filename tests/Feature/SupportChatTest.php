<?php

namespace Tests\Feature;

use App\Enums\SupportConversationStatus;
use App\Enums\SupportSenderType;
use App\Events\SupportInboxMessageReceived;
use App\Events\SupportMessageSent;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Suporte em tempo real: identidade anônima do visitante, conversa persistida
 * e caixa de entrada administrativa. A entrega em si é coberta pelos endpoints
 * de polling; o Reverb usa exatamente o mesmo estado do banco.
 */
class SupportChatTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: SupportConversation, 1: string}
     */
    private function conversationWithToken(array $attributes = []): array
    {
        $token = Str::random(48);

        $conversation = SupportConversation::factory()->create([
            ...$attributes,
            'visitor_token_hash' => hash('sha256', $token),
        ]);

        return [$conversation, $token];
    }

    private function asVisitor(SupportConversation $conversation, string $token): static
    {
        return $this->withCredentials()->withCookie(
            (string) config('support.cookie', 'arcana_support'),
            (string) json_encode(['uuid' => $conversation->uuid, 'token' => $token]),
        );
    }

    private function authorizeSupportChannel(SupportConversation $conversation, string $token, ?string $channelUuid = null): TestResponse
    {
        return $this->asVisitor($conversation, $token)->post(route('support.broadcast.auth'), [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-support.conversation.'.($channelUuid ?? $conversation->uuid),
        ]);
    }

    public function test_logged_in_visitor_sees_widget_identity_prefilled_from_the_account(): void
    {
        $user = User::factory()->create([
            'name' => 'Cliente Logado',
            'email' => 'cliente.logado@example.com',
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('id="support-visitor-name"', false)
            ->assertSee('value="'.e($user->name).'"', false)
            ->assertSee('id="support-visitor-email"', false)
            ->assertSee('value="'.e($user->email).'"', false);
    }

    public function test_visitor_starts_conversation_and_receives_identity_cookie(): void
    {
        $response = $this->postJson(route('support.conversation.store'), [
            'visitor_name' => '  Ana  ',
            'visitor_email' => 'ana@example.com',
            'body' => '  Preciso de ajuda com uma consulta.  ',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('conversation.visitor_name', 'Ana')
            ->assertJsonPath('conversation.status', SupportConversationStatus::Waiting->value)
            ->assertJsonPath('conversation.is_open', true)
            ->assertJsonPath('messages.0.body', 'Preciso de ajuda com uma consulta.')
            ->assertJsonPath('messages.0.mine', true)
            ->assertJsonPath('messages.0.sender_name', 'Você')
            ->assertCookie((string) config('support.cookie', 'arcana_support'))
            ->assertJsonMissingPath('conversation.visitor_email')
            ->assertJsonMissingPath('conversation.visitor_token_hash');

        $conversation = SupportConversation::query()->sole();

        $this->assertSame('Ana', $conversation->visitor_name);
        $this->assertSame('ana@example.com', $conversation->visitor_email);
        $this->assertSame(SupportConversationStatus::Waiting, $conversation->status);
        $this->assertSame(1, $conversation->messages()->count());
        $this->assertSame('Preciso de ajuda com uma consulta.', $conversation->messages()->sole()->body);
        $this->assertSame(64, strlen($conversation->visitor_token_hash));
        $this->assertNotSame('', $conversation->uuid);
    }

    public function test_visitor_reads_and_sends_messages_using_only_the_cookie_identity(): void
    {
        [$conversation, $token] = $this->conversationWithToken(['visitor_name' => 'Bruno']);

        $first = $conversation->messages()->create([
            'sender_type' => SupportSenderType::Visitor,
            'sender_id' => null,
            'body' => 'Olá, equipe.',
        ]);

        $this->asVisitor($conversation, $token)
            ->getJson(route('support.conversation.show'))
            ->assertOk()
            ->assertJsonPath('conversation.visitor_name', 'Bruno')
            ->assertJsonPath('messages.0.body', 'Olá, equipe.')
            ->assertJsonPath('messages.0.mine', true)
            ->assertJsonMissingPath('messages.0.sender_id');

        $sent = $this->asVisitor($conversation, $token)
            ->postJson(route('support.messages.store'), ['body' => '  Mensagem nova.  '])
            ->assertCreated()
            ->assertJsonPath('message.body', 'Mensagem nova.')
            ->assertJsonPath('message.mine', true)
            ->assertJsonPath('message.sender_name', 'Você');

        $messageId = (int) $sent->json('message.id');

        $this->asVisitor($conversation, $token)
            ->getJson(route('support.conversation.show', ['after_id' => $first->id]))
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.id', $messageId)
            ->assertJsonPath('messages.0.body', 'Mensagem nova.');

        $operatorMessage = $conversation->messages()->create([
            'sender_type' => SupportSenderType::Operator,
            'sender_id' => User::factory()->admin()->create()->id,
            'body' => 'Como posso ajudar?',
        ]);

        $this->asVisitor($conversation, $token)
            ->getJson(route('support.conversation.show'))
            ->assertOk()
            ->assertJsonPath('messages.2.sender_name', 'Equipe Arcana')
            ->assertJsonPath('messages.2.mine', false)
            ->assertJsonPath('conversation.unread_count', 1);

        $this->asVisitor($conversation, $token)
            ->postJson(route('support.messages.read'))
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertNotNull($operatorMessage->fresh()->read_at);
    }

    public function test_visitor_without_the_identity_cookie_cannot_read_or_write(): void
    {
        $this->getJson(route('support.conversation.show'))
            ->assertOk()
            ->assertJsonPath('conversation', null)
            ->assertJsonPath('messages', []);

        $this->postJson(route('support.messages.store'), ['body' => 'Sem cookie.'])
            ->assertForbidden();
    }

    public function test_closed_conversation_rejects_new_visitor_messages(): void
    {
        [$conversation, $token] = $this->conversationWithToken();

        $conversation->forceFill(['status' => SupportConversationStatus::Closed, 'closed_at' => now()])->save();

        $this->asVisitor($conversation, $token)
            ->postJson(route('support.messages.store'), ['body' => 'Ainda estou aqui.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_starting_again_returns_the_existing_open_conversation(): void
    {
        [$conversation, $token] = $this->conversationWithToken(['visitor_name' => 'Carla']);

        $conversation->messages()->create([
            'sender_type' => SupportSenderType::Visitor,
            'sender_id' => null,
            'body' => 'Primeira mensagem.',
        ]);

        $this->asVisitor($conversation, $token)
            ->postJson(route('support.conversation.store'), [
                'visitor_name' => 'Outra pessoa',
                'body' => 'Mensagem duplicada.',
            ])
            ->assertOk()
            ->assertJsonPath('conversation.visitor_name', 'Carla')
            ->assertJsonPath('messages.0.body', 'Primeira mensagem.');

        $this->assertSame(1, SupportConversation::query()->count());
        $this->assertSame(1, SupportMessage::query()->count());
    }

    public function test_admin_lists_threads_replies_reads_and_closes_a_conversation(): void
    {
        $admin = User::factory()->admin()->create();
        [$conversation, $token] = $this->conversationWithToken(['visitor_name' => 'Diego']);

        $visitorMessage = $conversation->messages()->create([
            'sender_type' => SupportSenderType::Visitor,
            'sender_id' => null,
            'body' => 'Quero remarcar minha consulta.',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.support.conversations.index'))
            ->assertOk()
            ->assertJsonPath('filter', 'open')
            ->assertJsonPath('summary.open', 1)
            ->assertJsonPath('summary.unread_total', 1)
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.visitor_name', 'Diego')
            ->assertJsonPath('conversations.0.visitor_email', $conversation->visitor_email)
            ->assertJsonPath('conversations.0.unread_count', 1)
            ->assertJsonPath('conversations.0.preview', 'Quero remarcar minha consulta.');

        $this->actingAs($admin)
            ->getJson(route('admin.support.conversations.show', $conversation))
            ->assertOk()
            ->assertJsonPath('conversation.id', $conversation->id)
            ->assertJsonPath('messages.0.body', 'Quero remarcar minha consulta.');

        $this->actingAs($admin)
            ->postJson(route('admin.support.messages.store', $conversation), ['body' => 'Claro, vou verificar.'])
            ->assertCreated()
            ->assertJsonPath('message.body', 'Claro, vou verificar.')
            ->assertJsonPath('message.sender_type', SupportSenderType::Operator->value)
            ->assertJsonPath('conversation.is_open', true);

        $conversation->refresh();

        $this->assertSame(SupportConversationStatus::InProgress, $conversation->status);
        $this->assertSame($admin->id, $conversation->assigned_user_id);

        $this->actingAs($admin)
            ->postJson(route('admin.support.messages.read', $conversation))
            ->assertOk()
            ->assertJsonPath('unread_total', 0);

        $this->assertNotNull($visitorMessage->fresh()->read_at);

        $this->actingAs($admin)
            ->postJson(route('admin.support.conversations.close', $conversation))
            ->assertOk()
            ->assertJsonPath('conversation.is_open', false)
            ->assertJsonPath('conversation.status', SupportConversationStatus::Closed->value)
            ->assertJsonPath('summary.open', 0);

        $conversation->refresh();

        $this->assertSame(SupportConversationStatus::Closed, $conversation->status);
        $this->assertNotNull($conversation->closed_at);

        $this->asVisitor($conversation, $token)
            ->getJson(route('support.conversation.show'))
            ->assertOk()
            ->assertJsonPath('conversation.is_open', false)
            ->assertJsonPath('conversation.status', SupportConversationStatus::Closed->value);
    }

    public function test_closed_filter_lists_recent_closed_conversations_only(): void
    {
        $admin = User::factory()->admin()->create();
        [$closed] = $this->conversationWithToken(['visitor_name' => 'Encerrada']);
        [$open] = $this->conversationWithToken(['visitor_name' => 'Aberta']);

        $closed->forceFill(['status' => SupportConversationStatus::Closed, 'closed_at' => now()])->save();

        $this->actingAs($admin)
            ->getJson(route('admin.support.conversations.index', ['status' => 'closed']))
            ->assertOk()
            ->assertJsonPath('filter', 'closed')
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.visitor_name', 'Encerrada');

        $this->actingAs($admin)
            ->getJson(route('admin.support.conversations.index', ['status' => 'open']))
            ->assertOk()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.visitor_name', 'Aberta');
    }

    public function test_support_admin_routes_are_restricted_to_admins(): void
    {
        $this->getJson(route('admin.support.conversations.index'))
            ->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->getJson(route('admin.support.conversations.index'))
            ->assertForbidden();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson(route('admin.support.presence'))
            ->assertOk()
            ->assertJsonPath('online', true)
            ->assertJsonStructure(['heartbeat_seconds']);
    }

    public function test_admin_broadcast_auth_uses_the_authenticated_session(): void
    {
        config(['broadcasting.default' => 'reverb']);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');

        [$conversation] = $this->conversationWithToken();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-support.inbox',
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs($admin)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-support.conversation.'.$conversation->uuid,
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs(User::factory()->create())
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-support.inbox',
            ])
            ->assertForbidden();
    }

    public function test_visitor_broadcast_auth_only_authorizes_the_own_open_conversation(): void
    {
        config(['broadcasting.default' => 'reverb']);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');

        [$conversation, $token] = $this->conversationWithToken();
        [$other] = $this->conversationWithToken();

        $this->authorizeSupportChannel($conversation, $token)
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->authorizeSupportChannel($conversation, $token, $other->uuid)
            ->assertForbidden();

        $conversation->forceFill(['status' => SupportConversationStatus::Closed, 'closed_at' => now()])->save();

        $this->authorizeSupportChannel($conversation, $token)
            ->assertForbidden();
    }

    public function test_visitor_broadcast_payload_never_includes_the_operator_name(): void
    {
        Event::fake([SupportMessageSent::class, SupportInboxMessageReceived::class]);

        $admin = User::factory()->admin()->create(['name' => 'Operadora Interna']);
        [$conversation] = $this->conversationWithToken();

        $this->actingAs($admin)
            ->postJson(route('admin.support.messages.store', $conversation), ['body' => 'Resposta da equipe.'])
            ->assertCreated();

        Event::assertDispatched(
            SupportMessageSent::class,
            function (SupportMessageSent $event) use ($conversation): bool {
                return $event->conversationUuid === $conversation->uuid
                    && $event->message['sender_name'] === 'Equipe Arcana'
                    && ! array_key_exists('operator_message', $event->broadcastWith())
                    && collect($event->broadcastOn())->every(
                        fn ($channel): bool => $channel->name === 'private-support.conversation.'.$conversation->uuid,
                    );
            },
        );

        Event::assertDispatched(
            SupportInboxMessageReceived::class,
            function (SupportInboxMessageReceived $event): bool {
                return $event->message['sender_name'] === 'Operadora Interna'
                    && $event->broadcastOn()[0]->name === 'private-support.inbox';
            },
        );
    }
}
