<?php

namespace App\Enums;

enum PaymentEventStatus: string
{
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Processed => 'Processado',
            self::Ignored => 'Ignorado',
            self::Failed => 'Falhou',
        };
    }
}
