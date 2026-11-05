<?php

namespace App\Support\Sms;

use RuntimeException;

/**
 * Falha de infraestrutura ao enviar ou conferir o código por SMS.
 *
 * A mensagem é escrita para o cliente final; detalhes técnicos do provedor vão
 * para o log através da exceção anterior.
 */
class SmsVerificationException extends RuntimeException
{
    public static function unavailable(): self
    {
        return new self('A confirmação por SMS não está disponível neste ambiente.');
    }

    public static function sendFailed(): self
    {
        return new self('Não foi possível enviar o código por SMS agora. Tente novamente em instantes.');
    }

    public static function checkFailed(): self
    {
        return new self('Não foi possível conferir o código agora. Tente novamente em instantes.');
    }
}
