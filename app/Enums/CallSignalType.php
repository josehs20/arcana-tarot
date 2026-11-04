<?php

namespace App\Enums;

/**
 * Tipos de sinal trocados entre os navegadores de uma chamada.
 *
 * O servidor apenas transporta o payload opaco (SDP ou candidato ICE) entre
 * os dois participantes autenticados. Nenhum conteúdo de mídia passa pelo
 * Laravel: o áudio/vídeo trafega direto entre os pares via WebRTC.
 */
enum CallSignalType: string
{
    case Offer = 'offer';
    case Answer = 'answer';
    case IceCandidate = 'ice';

    public function label(): string
    {
        return match ($this) {
            self::Offer => 'Oferta',
            self::Answer => 'Resposta',
            self::IceCandidate => 'Candidato ICE',
        };
    }
}
