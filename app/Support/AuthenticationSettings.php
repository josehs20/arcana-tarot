<?php

namespace App\Support;

/**
 * Disponibilidade dos métodos alternativos de autenticação.
 *
 * Centraliza a checagem usada por controllers, rotas e views, para que o botão
 * do Google nunca apareça ligado sem credenciais válidas. O cadastro por
 * e-mail é o caminho principal e não depende desta classe.
 */
final class AuthenticationSettings
{
    public static function googleEnabled(): bool
    {
        return (bool) config('authentication.google.enabled')
            && self::isFilled(config('services.google.client_id'))
            && self::isFilled(config('services.google.client_secret'));
    }

    private static function isFilled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
