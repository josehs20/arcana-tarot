<?php

namespace App\Support;

/**
 * Disponibilidade dos métodos alternativos de autenticação.
 *
 * Centraliza a checagem usada por controllers, rotas e views, para que o botão
 * do Google e o passo de SMS nunca apareçam ligados sem credenciais válidas.
 */
final class AuthenticationSettings
{
    public static function googleEnabled(): bool
    {
        return (bool) config('authentication.google.enabled')
            && self::isFilled(config('services.google.client_id'))
            && self::isFilled(config('services.google.client_secret'));
    }

    public static function smsEnabled(): bool
    {
        return (bool) config('authentication.sms.enabled');
    }

    public static function smsCodeLength(): int
    {
        $length = (int) config('authentication.sms.code_length', 6);

        return $length >= 4 && $length <= 8 ? $length : 6;
    }

    private static function isFilled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
