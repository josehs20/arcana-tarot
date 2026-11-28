<?php

namespace App\Enums;

enum SupportSenderType: string
{
    case Visitor = 'visitor';
    case Operator = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::Visitor => 'Visitante',
            self::Operator => 'Equipe Arcana',
        };
    }
}
