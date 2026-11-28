<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Mensagem de suporte persistida, na visão do visitante.
 *
 * Vai apenas para o canal privado da conversa. O payload não contém token,
 * e-mail, identificador nem nome do administrador: respostas da equipe chegam
 * como "Equipe Arcana". A caixa de entrada tem um evento próprio
 * (`SupportInboxMessageReceived`) para não expor esses dados ao visitante.
 */
final class SupportMessageSent implements ShouldBroadcast, ShouldDispatchAfterCommit
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
        return [new PrivateChannel('support.conversation.'.$this->conversationUuid)];
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
