<?php

namespace App\Enums;

/**
 * Situação de uma ocorrência de dado pessoal identificada no chat.
 *
 * `Blocked` é o estado inicial: a mensagem não foi persistida. `Reviewed`
 * indica que um administrador analisou o caso e registrou a revisão na
 * auditoria.
 */
enum PrivacyIncidentStatus: string
{
    case Blocked = 'blocked';
    case Reviewed = 'reviewed';

    public function label(): string
    {
        return match ($this) {
            self::Blocked => 'Bloqueada',
            self::Reviewed => 'Revisada',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Blocked;
    }
}
