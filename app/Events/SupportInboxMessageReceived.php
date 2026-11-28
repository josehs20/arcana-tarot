<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Mensagem de suporte vista pela equipe.
 *
 * O canal `support.inbox` é privado e só aceita administradores ativos. Este
 * evento separado existe porque a visão do painel inclui o nome de quem
 * respondeu; a visão do visitante (`SupportMessageSent`) esconde esse dado atrás
 * de "Equipe Arcana".
 */
final class SupportInboxMessageReceived implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $conversation
     * @param  array<string, mixed>  $message
     */
    public function __construct(
        public readonly string $conversationUuid,
        public readonly array $conversation,
        public readonly array $message,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('support.inbox')];
    }

    public function broadcastAs(): string
    {
        return 'support.message';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation' => $this->conversation,
            'message' => $this->message,
        ];
    }
}
