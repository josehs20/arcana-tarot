<?php

namespace App\Enums;

/**
 * Estado da chamada de voz/vídeo de uma consulta.
 *
 * ringing  -> chamada solicitada pelo cliente, aguardando o atendente
 * accepted -> atendente aceitou; navegadores trocam oferta/resposta/ICE
 * connected-> peer connection estabelecida nos dois lados
 * ended    -> chamada encerrada por um dos participantes
 * failed   -> chamada recusada, sem permissão de mídia ou com falha técnica
 *
 * Os estados `ended` e `failed` são finais: repetir o encerramento é no-op.
 */
enum CallStatus: string
{
    case Ringing = 'ringing';
    case Accepted = 'accepted';
    case Connected = 'connected';
    case Ended = 'ended';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Ringing => 'Chamando',
            self::Accepted => 'Chamada aceita',
            self::Connected => 'Em andamento',
            self::Ended => 'Encerrada',
            self::Failed => 'Falhou',
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Ended || $this === self::Failed;
    }

    public function isOpen(): bool
    {
        return ! $this->isFinal();
    }
}
