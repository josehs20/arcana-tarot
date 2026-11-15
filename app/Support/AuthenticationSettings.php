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

    /**
     * O fluxo mobile não usa o client secret: a API só precisa validar a
     * audiência do ID token emitido pelo SDK. O client ID web entra na lista
     * porque o Android costuma usá-lo como `serverClientId`; os IDs nativos
     * (Android/iOS) são configurados em `GOOGLE_MOBILE_CLIENT_IDS`.
     *
     * @return list<string>
     */
    public static function googleClientIds(): array
    {
        $clientIds = array_merge(
            [config('services.google.client_id')],
            (array) config('services.google.mobile_client_ids', []),
        );

        return array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $value): string => is_string($value) ? trim($value) : '',
                $clientIds,
            ),
            static fn (string $value): bool => $value !== '',
        )));
    }

    public static function googleMobileEnabled(): bool
    {
        return (bool) config('authentication.google.enabled')
            && self::googleClientIds() !== [];
    }

    private static function isFilled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
