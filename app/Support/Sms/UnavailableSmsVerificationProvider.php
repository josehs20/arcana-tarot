<?php

namespace App\Support\Sms;

use App\Enums\SmsVerificationResult;
use App\Support\Sms\Contracts\SmsVerificationProvider;

/**
 * Provedor neutro usado quando o SMS está ativado mas sem credenciais.
 *
 * Mantém o binding do container resolvível e devolve uma mensagem clara em vez
 * de derrubar o cadastro com erro de configuração.
 */
final class UnavailableSmsVerificationProvider implements SmsVerificationProvider
{
    public function send(string $e164Phone): void
    {
        throw new SmsVerificationException(
            'A confirmação por SMS está ativada, mas as credenciais do provedor não foram configuradas. Avise a administração.',
        );
    }

    public function check(string $e164Phone, string $code): SmsVerificationResult
    {
        throw new SmsVerificationException(
            'A confirmação por SMS está ativada, mas as credenciais do provedor não foram configuradas. Avise a administração.',
        );
    }
}
