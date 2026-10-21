<?php

namespace App\Enums;

/**
 * Disponibilidade declarada do atendente.
 *
 * `busy` não é persistido: é derivado de uma consulta ativa (Prompt 08).
 */
enum AttendantAvailability: string
{
    case Offline = 'offline';
    case Online = 'online';
}
