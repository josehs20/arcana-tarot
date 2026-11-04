<?php

namespace App\Enums;

/**
 * Motivo de encerramento, recusa, cancelamento ou expiração.
 *
 * O motivo é um rótulo operacional: nunca deve conter conteúdo íntimo ou
 * trechos de conversa. Textos livres são responsabilidade de etapas futuras e
 * não são copiados para a auditoria.
 */
enum ConsultationEndReason: string
{
    case ClientEnded = 'client_ended';
    case AttendantEnded = 'attendant_ended';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case OutOfCredits = 'out_of_credits';
    case Timeout = 'timeout';
    case Disconnected = 'disconnected';
    case Inactivity = 'inactivity';
    case ClientLoggedOut = 'client_logged_out';
    case AttendantLoggedOut = 'attendant_logged_out';
    case CallEnded = 'call_ended';
    case CallFailed = 'call_failed';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ClientEnded => 'Encerrada pelo cliente',
            self::AttendantEnded => 'Encerrada pelo atendente',
            self::Rejected => 'Recusada pelo atendente',
            self::Cancelled => 'Cancelada pelo cliente',
            self::Expired => 'Solicitação expirada',
            self::OutOfCredits => 'Saldo insuficiente',
            self::Timeout => 'Tempo limite',
            self::Disconnected => 'Desconexão',
            self::Inactivity => 'Inatividade',
            self::ClientLoggedOut => 'Cliente saiu da conta',
            self::AttendantLoggedOut => 'Atendente saiu da conta',
            self::CallEnded => 'Chamada encerrada',
            self::CallFailed => 'Chamada não concluída',
            self::Other => 'Outro motivo',
        };
    }
}
