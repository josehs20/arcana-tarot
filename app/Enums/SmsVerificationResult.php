<?php

namespace App\Enums;

/**
 * Retorno da conferência de código junto ao provedor de SMS.
 *
 * `TooManyAttempts` vem do provedor (bloqueio por excesso de tentativas) ou é
 * aplicado localmente quando o limite de tentativas da verificação é atingido.
 */
enum SmsVerificationResult: string
{
    case Approved = 'approved';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case TooManyAttempts = 'too_many_attempts';
}
