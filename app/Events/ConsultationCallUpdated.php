<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Atualizacao de chamada (estado e sinal WebRTC) para os participantes.
 *
 * O canal e o mesmo da consulta: somente cliente e atendente escalados
 * recebem o payload. A interface tambem consulta o estado por HTTP, portanto
 * um evento perdido nao interrompe a chamada.
 */
final class ConsultationCallUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>|null  $call
     * @param  array<string, mixed>|null  $signal
     */
    public function __construct(
        public readonly int $consultationId,
        public readonly ?array $call,
        public readonly ?array $signal = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('consultation.'.$this->consultationId)];
    }

    public function broadcastAs(): string
    {
        return 'consultation.call';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'call' => $this->call,
            'signal' => $this->signal,
        ];
    }
}
