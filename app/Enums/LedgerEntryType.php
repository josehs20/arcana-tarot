<?php

namespace App\Enums;

enum LedgerEntryType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
}
