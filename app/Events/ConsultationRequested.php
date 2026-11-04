<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Nova solicitação de atendimento endereçada a um atendente.
 *
 * Diferente de `ConsultationStateChanged`, que usa o canal da consulta, este
 * evento é entregue no canal privado do atendente. É assim que o painel
 * recebe o pedido, toca o alerta sonoro e abre o modal de aceite sem
 * recarregar a página — mesmo quando o atendente está em outra tela do painel.
 *
 * O payload contém apenas dados operacionais já visíveis ao atendente
 * (cliente, modalidade, preço de tabela e URLs autorizadas). Nenhum dado de
 * pagamento, saldo ou comissão trafega por aqui.
 */
final class ConsultationRequested implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $request
     */
    public function __construct(
        public readonly int $attendantId,
        public readonly array $request,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->attendantId)];
    }

    public function broadcastAs(): string
    {
        return 'consultation.request';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['request' => $this->request];
    }
}
