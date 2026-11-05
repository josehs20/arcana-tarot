<?php

namespace App\Support;

/**
 * Normalização e validação de telefones brasileiros.
 *
 * O número é sempre persistido apenas com DDD + número (10 ou 11 dígitos),
 * sem máscara nem código do país, para permitir comparação e, futuramente,
 * o envio de códigos de confirmação por SMS.
 */
class PhoneNumber
{
    /**
     * Remove máscara e o código do país (+55), devolvendo apenas dígitos.
     */
    public static function normalize(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if (in_array(strlen($digits), [12, 13], true) && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        return $digits;
    }

    /**
     * Valida DDD + número, aceitando fixo (10 dígitos) e celular (11 dígitos).
     */
    public static function isValid(string $phone): bool
    {
        return preg_match('/^[1-9]{2}(?:9\d{8}|[2-9]\d{7})$/', $phone) === 1;
    }

    /**
     * Converte o número persistido (DDD + número) para o formato E.164 usado
     * pelos provedores de SMS, por exemplo `+5511999999999`.
     */
    public static function toE164(string $phone, ?string $countryCode = null): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $countryCode = preg_replace('/\D+/', '', $countryCode ?? (string) config('authentication.sms.country_code', '55')) ?? '';

        if ($countryCode === '') {
            $countryCode = '55';
        }

        if (str_starts_with($digits, $countryCode) && strlen($digits) > 10) {
            $digits = substr($digits, strlen($countryCode));
        }

        return '+'.$countryCode.$digits;
    }

    /**
     * Formata para exibição: (11) 99999-9999 ou (11) 9999-9999.
     */
    public static function format(string $phone): string
    {
        if (strlen($phone) === 11) {
            return sprintf('(%s) %s-%s', substr($phone, 0, 2), substr($phone, 2, 5), substr($phone, 7));
        }

        if (strlen($phone) === 10) {
            return sprintf('(%s) %s-%s', substr($phone, 0, 2), substr($phone, 2, 4), substr($phone, 6));
        }

        return $phone;
    }
}
