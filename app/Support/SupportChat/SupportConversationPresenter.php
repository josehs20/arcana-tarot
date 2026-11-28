<?php

namespace App\Support\SupportChat;

use App\Enums\SupportConversationStatus;
use App\Enums\SupportSenderType;
use App\Models\SupportConversation;
use Illuminate\Support\Str;

/**
 * Resumo de uma conversa de suporte.
 *
 * O resumo do visitante não carrega token, e-mail nem administrador. O resumo
 * do painel carrega o suficiente para priorizar a fila: nome, contato
 * opcional, status, responsável e prévia da última mensagem.
 */
final class SupportConversationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function forVisitor(SupportConversation $conversation): array
    {
        return [
            'id' => (int) $conversation->getKey(),
            'uuid' => $conversation->uuid,
            'visitor_name' => $conversation->visitor_name,
            'status' => $conversation->status->value,
            'status_label' => $conversation->status->label(),
            'is_open' => $conversation->isOpen(),
            'unread_count' => $conversation->messages()
                ->where('sender_type', SupportSenderType::Operator->value)
                ->whereNull('read_at')
                ->count(),
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'last_message_at_label' => $conversation->last_message_at?->format('d/m/Y H:i'),
            'closed_at' => $conversation->closed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forOperator(SupportConversation $conversation): array
    {
        $conversation->loadMissing(['latestMessage', 'assignedUser']);

        $unreadCount = $conversation->getAttribute('unread_count');
        $unreadCount = is_numeric($unreadCount)
            ? (int) $unreadCount
            : $conversation->unreadCountForOperator();

        $preview = $conversation->latestMessage?->body;

        return [
            'id' => (int) $conversation->getKey(),
            'visitor_name' => $conversation->visitor_name,
            'visitor_email' => $conversation->visitor_email,
            'status' => $conversation->status->value,
            'status_label' => $conversation->status->label(),
            'is_open' => $conversation->isOpen(),
            'assigned_user_id' => $conversation->assigned_user_id,
            'assigned_user_name' => $conversation->assignedUser?->name,
            'unread_count' => $unreadCount,
            'preview' => $preview === null ? null : Str::limit($preview, 90),
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'last_message_at_label' => $conversation->last_message_at?->format('d/m/Y H:i'),
            'closed_at' => $conversation->closed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function statusOptions(): array
    {
        return collect(SupportConversationStatus::cases())
            ->mapWithKeys(fn (SupportConversationStatus $status): array => [$status->value => $status->label()])
            ->all();
    }
}
