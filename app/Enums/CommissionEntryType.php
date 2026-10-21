<?php

namespace App\Enums;

enum CommissionEntryType: string
{
    case Accrual = 'accrual';
    case Reversal = 'reversal';
    case Adjustment = 'adjustment';
}
