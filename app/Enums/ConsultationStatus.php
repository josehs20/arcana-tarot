<?php

namespace App\Enums;

enum ConsultationStatus: string
{
    case Requested = 'requested';
    case Active = 'active';
    case Ended = 'ended';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
