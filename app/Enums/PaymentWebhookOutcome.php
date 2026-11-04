<?php

namespace App\Enums;

enum PaymentWebhookOutcome: string
{
    case Processed = 'processed';
    case Duplicate = 'duplicate';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
