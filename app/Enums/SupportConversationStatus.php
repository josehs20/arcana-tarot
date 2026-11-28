<?php

namespace App\Enums;

enum SupportConversationStatus: string
{
    case Waiting = 'waiting';
    case InProgress = 'in_progress';
    case Closed = 'closed';

    public function isOpen(): bool
    {
        return $this !== self::Closed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Aguardando atendimento',
            self::InProgress => 'Em atendimento',
            self::Closed => 'Encerrado',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return [self::Waiting->value, self::InProgress->value];
    }
}
