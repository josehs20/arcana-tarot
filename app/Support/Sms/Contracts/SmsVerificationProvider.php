<?php

namespace App\Support\Sms\Contracts;

use App\Enums\SmsVerificationResult;
use App\Support\Sms\SmsVerificationException;

/**
 * Contrato do provedor de códigos por SMS.
 *
 * A aplicação não gera códigos: ela delega o envio e a conferência ao provedor
 * e guarda apenas o estado local (tentativas, validade e dados do cadastro).
 */
interface SmsVerificationProvider
{
    /**
     * Envia um novo código para o número em E.164.
     *
     * @throws SmsVerificationException quando o provedor está indisponível
     */
    public function send(string $e164Phone): void;

    /**
     * Confere o código digitado pelo cliente.
     *
     * @throws SmsVerificationException quando o provedor está indisponível
     */
    public function check(string $e164Phone, string $code): SmsVerificationResult;
}
