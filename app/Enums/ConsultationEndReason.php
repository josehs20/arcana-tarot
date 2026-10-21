<?php

namespace App\Enums;

enum ConsultationEndReason: string
{
    case ClientEnded = 'client_ended';
    case AttendantEnded = 'attendant_ended';
    case OutOfCredits = 'out_of_credits';
    case Timeout = 'timeout';
    case Cancelled = 'cancelled';
    case Other = 'other';
}
