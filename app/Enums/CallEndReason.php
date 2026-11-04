<?php

namespace App\Enums;

/**
 * Motivo do encerramento ou falha da chamada.
 */
enum CallEndReason: string
{
    case Hangup = 'hangup';
    case Rejected = 'rejected';
    case Timeout = 'timeout';
    case MediaUnavailable = 'media_unavailable';
    case ConnectionFailed = 'connection_failed';
    case ConsultationEnded = 'consultation_ended';

    public function label(): string
    {
        return match ($this) {
            self::Hangup => 'Encerrada por um participante',
            self::Rejected => 'Recusada pelo atendente',
            self::Timeout => 'Sem resposta',
            self::MediaUnavailable => 'Microfone ou câmera indisponível',
            self::ConnectionFailed => 'Falha na conexão',
            self::ConsultationEnded => 'Consulta encerrada',
        };
    }

    public function endsConsultation(): bool
    {
        return $this !== self::ConsultationEnded;
    }
}
