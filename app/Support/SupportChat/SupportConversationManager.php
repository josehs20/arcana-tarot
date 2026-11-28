<?php

namespace App\Support\SupportChat;

use App\Enums\SupportConversationStatus;
use App\Enums\SupportSenderType;
use App\Events\SupportConversationUpdated;
use App\Events\SupportInboxMessageReceived;
use App\Events\SupportMessageSent;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Regras de negócio do suporte.
 *
 * Toda mensagem é gravada antes do broadcast: o Reverb é um acelerador, nunca
 * a fonte da verdade. A conversa é bloqueada durante a escrita para preservar
 * a ordem das mensagens quando visitante e administrador enviam ao mesmo tempo.
 */
class SupportConversationManager
{
    public function __construct(private readonly SupportVisitorIdentity $identity) {}

    /**
     * @param  array{visitor_name: string, visitor_email: string|null, body: string}  $data
     * @return array{conversation: SupportConversation, token: string, message: SupportMessage}
     */
    public function start(array $data): array
    {
        $token = $this->identity->newToken();

        return DB::transaction(function () use ($data, $token): array {
            $conversation = SupportConversation::query()->create([
                'visitor_name' => $data['visitor_name'],
                'visitor_email' => $data['visitor_email'] ?? null,
                'visitor_token_hash' => $this->identity->hash($token),
                'status' => SupportConversationStatus::Waiting,
                'last_message_at' => now(),
            ]);

            $message = $conversation->messages()->create([
                'sender_type' => SupportSenderType::Visitor,
                'sender_id' => null,
                'body' => $data['body'],
            ]);

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            $this->broadcastMessage($conversation, $message);

            return [
                'conversation' => $conversation,
                'token' => $token,
                'message' => $message,
            ];
        });
    }

    public function postVisitorMessage(SupportConversation $conversation, string $body): SupportMessage
    {
        return DB::transaction(function () use ($conversation, $body): SupportMessage {
            $locked = $this->lockOpenConversation($conversation);

            $message = $locked->messages()->create([
                'sender_type' => SupportSenderType::Visitor,
                'sender_id' => null,
                'body' => $body,
            ]);

            $locked->forceFill(['last_message_at' => $message->created_at])->save();

            $this->broadcastMessage($locked, $message);

            return $message->fresh();
        });
    }

    public function postOperatorMessage(SupportConversation $conversation, User $operator, string $body): SupportMessage
    {
        return DB::transaction(function () use ($conversation, $operator, $body): SupportMessage {
            $locked = $this->lockOpenConversation($conversation);

            $wasWaiting = $locked->status === SupportConversationStatus::Waiting;

            $locked->forceFill([
                'assigned_user_id' => $locked->assigned_user_id ?? $operator->getKey(),
                'status' => $wasWaiting ? SupportConversationStatus::InProgress : $locked->status,
            ])->save();

            $message = $locked->messages()->create([
                'sender_type' => SupportSenderType::Operator,
                'sender_id' => $operator->getKey(),
                'body' => $body,
            ]);

            $locked->forceFill(['last_message_at' => $message->created_at])->save();

            $this->broadcastMessage($locked, $message);

            if ($wasWaiting) {
                $this->broadcastConversation($locked);
            }

            return $message->fresh();
        });
    }

    public function close(SupportConversation $conversation): SupportConversation
    {
        return DB::transaction(function () use ($conversation): SupportConversation {
            $locked = SupportConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());

            if (! $locked->isOpen()) {
                return $locked;
            }

            $locked->forceFill([
                'status' => SupportConversationStatus::Closed,
                'closed_at' => now(),
            ])->save();

            $this->broadcastConversation($locked);

            return $locked;
        });
    }

    public function markMessagesReadForOperator(SupportConversation $conversation): void
    {
        $conversation->markMessagesReadForOperator();
    }

    public function markMessagesReadForVisitor(SupportConversation $conversation): void
    {
        $conversation->markMessagesReadForVisitor();
    }

    private function lockOpenConversation(SupportConversation $conversation): SupportConversation
    {
        $locked = SupportConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());

        if (! $locked->isOpen()) {
            throw ValidationException::withMessages([
                'body' => 'Esta conversa foi encerrada. Inicie uma nova conversa para continuar.',
            ]);
        }

        return $locked;
    }

    private function broadcastMessage(SupportConversation $conversation, SupportMessage $message): void
    {
        $message->loadMissing(['sender', 'conversation']);

        SupportMessageSent::dispatch(
            $conversation->uuid,
            $this->conversationPayload($conversation),
            SupportMessagePresenter::forVisitor($message),
        );

        SupportInboxMessageReceived::dispatch(
            $conversation->uuid,
            $this->conversationPayload($conversation),
            SupportMessagePresenter::forOperator($message),
        );
    }

    private function broadcastConversation(SupportConversation $conversation): void
    {
        SupportConversationUpdated::dispatch(
            $conversation->uuid,
            $this->conversationPayload($conversation),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationPayload(SupportConversation $conversation): array
    {
        return [
            'id' => (int) $conversation->getKey(),
            'uuid' => $conversation->uuid,
            'visitor_name' => $conversation->visitor_name,
            'status' => $conversation->status->value,
            'status_label' => $conversation->status->label(),
            'is_open' => $conversation->isOpen(),
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
        ];
    }
}
