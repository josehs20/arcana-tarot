<?php

namespace App\Enums;

/**
 * Estados do ciclo de vida de uma consulta.
 *
 * Máquina de estados (ver docs/CONSULTATION_STATE_MACHINE.md):
 * requested -> active -> ended
 * requested -> rejected
 * requested -> cancelled
 * requested -> expired
 * active    -> interrupted
 *
 * Estados finais não voltam a ficar abertos. Repetir o aceite de uma consulta
 * já ativa ou o encerramento de uma consulta final é uma operação idempotente.
 */
enum ConsultationStatus: string
{
    case Requested = 'requested';
    case Active = 'active';
    case Ended = 'ended';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Interrupted = 'interrupted';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Solicitada',
            self::Active => 'Em andamento',
            self::Ended => 'Concluída',
            self::Cancelled => 'Cancelada',
            self::Rejected => 'Recusada',
            self::Expired => 'Expirada',
            self::Interrupted => 'Interrompida',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Requested || $this === self::Active;
    }

    public function isFinal(): bool
    {
        return ! $this->isOpen();
    }

    /**
     * Apenas uma consulta ativa permite trocar mensagens e cartas.
     */
    public function allowsMessaging(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return [self::Requested->value, self::Active->value];
    }

    /**
     * @return array<int, string>
     */
    public static function finalValues(): array
    {
        return [self::Ended->value, self::Cancelled->value, self::Rejected->value, self::Expired->value, self::Interrupted->value];
    }
}
