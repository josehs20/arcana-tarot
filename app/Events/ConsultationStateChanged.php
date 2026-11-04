<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Mudança de estado da consulta anunciada aos participantes.
 *
 * A interface usa o evento para atualizar os controles sem recarregar a
 * página. O estado persistido continua sendo a fonte de verdade depois de uma
 * reconexão ou de um evento perdido.
 *
 * O evento sai pelo canal da consulta (quem já está com a conversa aberta) e
 * também pelo canal privado de cada participante. Assim cliente e atendente
 * são avisados mesmo estando em outra tela do painel — é o que permite abrir a
 * tela de atendimento automaticamente assim que o aceite acontece.
 */
final class ConsultationStateChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $consultation
     * @param  array<int, int>  $participantIds
     */
    public function __construct(
        public readonly int $consultationId,
        public readonly array $consultation,
        public readonly array $participantIds = [],
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('consultation.'.$this->consultationId)];

        foreach (array_unique(array_filter($this->participantIds)) as $userId) {
            $channels[] = new PrivateChannel('user.'.$userId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'consultation.state';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['consultation' => $this->consultation];
    }
}
