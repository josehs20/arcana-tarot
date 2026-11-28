<?php

namespace App\Support\SupportChat;

use App\Enums\SupportSenderType;
use App\Models\SupportMessage;

/**
 * Representação pública de uma mensagem de suporte.
 *
 * A mesma linha do banco é apresentada de dois pontos de vista. O visitante
 * nunca recebe e-mail, identificador de administrador ou qualquer metadado
 * interno; o painel recebe apenas o necessário para atender.
 */
final class SupportMessagePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function forVisitor(SupportMessage $message): array
    {
        $isOperator = $message->sender_type === SupportSenderType::Operator;

        return [
            'id' => (int) $message->getKey(),
            'conversation_id' => (int) $message->conversation_id,
            'sender_type' => $message->sender_type->value,
            'sender_name' => $isOperator ? 'Equipe Arcana' : 'Você',
            'body' => $message->body,
            'created_at' => $message->created_at?->toIso8601String(),
            'created_at_label' => $message->created_at?->format('d/m/Y H:i') ?? '—',
            'mine' => ! $isOperator,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forOperator(SupportMessage $message): array
    {
        $message->loadMissing(['sender', 'conversation']);

        $isOperator = $message->sender_type === SupportSenderType::Operator;

        return [
            'id' => (int) $message->getKey(),
            'conversation_id' => (int) $message->conversation_id,
            'sender_type' => $message->sender_type->value,
            'sender_name' => $isOperator
                ? ($message->sender?->name ?? 'Equipe Arcana')
                : ($message->conversation?->visitor_name ?? 'Visitante'),
            'body' => $message->body,
            'created_at' => $message->created_at?->toIso8601String(),
            'created_at_label' => $message->created_at?->format('d/m/Y H:i') ?? '—',
            'mine' => $isOperator,
            'read_at' => $message->read_at?->toIso8601String(),
        ];
    }
}
