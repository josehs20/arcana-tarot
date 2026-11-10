<?php

namespace App\Support;

/**
 * Normalização e validação de telefones brasileiros.
 *
 * O número é sempre persistido apenas com DDD + número (10 ou 11 dígitos),
 * sem máscara nem código do país, para permitir comparação entre cadastros e o
 * envio do número ao checkout em E.164.
 */
class PhoneNumber
{
    /**
     * DDI padrão (Brasil) aplicado quando o chamador não informa outro.
     */
    private const DEFAULT_COUNTRY_CODE = '55';

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
     * pelos provedores externos, por exemplo `+5511999999999`.
     *
     * O DDI só é removido quando o valor recebido realmente o contém, isto é,
     * quando sobram 10 ou 11 dígitos nacionais depois dele. Sem essa conferência
     * de tamanho, um número com DDD 55 — `(55) 99999-9999` — perderia os dois
     * primeiros dígitos e chegaria no provedor como `+55999999999`.
     */
    public static function toE164(string $phone, ?string $countryCode = null): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $countryCode = preg_replace('/\D+/', '', $countryCode ?? self::DEFAULT_COUNTRY_CODE) ?? '';

        if ($countryCode === '') {
            $countryCode = '55';
        }

        $national = substr($digits, strlen($countryCode));

        if (str_starts_with($digits, $countryCode) && in_array(strlen($national), [10, 11], true)) {
            $digits = $national;
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
