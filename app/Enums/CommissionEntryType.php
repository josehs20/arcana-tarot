<?php

namespace App\Enums;

enum CommissionEntryType: string
{
    case Accrual = 'accrual';
    case Reversal = 'reversal';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Accrual => 'Comissão',
            self::Reversal => 'Estorno',
            self::Adjustment => 'Ajuste',
        };
    }

    /**
     * Estornos subtraem do total; accruals e ajustes somam.
     */
    public function addsToTotal(): bool
    {
        return $this !== self::Reversal;
    }
}
