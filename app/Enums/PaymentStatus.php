<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Expired = 'expired';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
