<?php

namespace App\Enums;

/**
 * Situação de um destinatário dentro de um disparo de e-mail.
 *
 * O envio é feito por job na fila: cada destinatário nasce `Pending`, vira
 * `Sent` quando o Mail o entrega ao transporte e `Failed` apenas depois de
 * esgotar as tentativas — é esse estado que a tela usa para reenviar falhas.
 */
enum EmailRecipientStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Na fila',
            self::Sent => 'Enviado',
            self::Failed => 'Falhou',
        };
    }

    /**
     * Chave usada pelo componente x-ui.status-badge (rótulo vem do próprio badge).
     */
    public function badgeStatus(): string
    {
        return $this === self::Sent ? 'sent' : $this->value;
    }
}
