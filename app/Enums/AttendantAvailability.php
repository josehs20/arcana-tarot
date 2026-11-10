<?php

namespace App\Enums;

/**
 * Disponibilidade declarada do atendente.
 *
 * `busy` é persistido: o atendente pode pausar a fila manualmente ("Ocupado")
 * e o aceite de uma consulta também o coloca nesse estado até o encerramento.
 * Perfis ocupados não recebem novas solicitações.
 */
enum AttendantAvailability: string
{
    case Offline = 'offline';
    case Online = 'online';
    case Busy = 'busy';

    /**
     * Rótulo exibido na interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Offline => 'Offline',
            self::Online => 'Online',
            self::Busy => 'Ocupado',
        };
    }
}
