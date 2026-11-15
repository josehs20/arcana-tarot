<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

/**
 * Emissão dos Bearer tokens do app móvel.
 *
 * Cada token recebe apenas a ability do papel do usuário. A autorização fina
 * continua nas Policies/Gates; a ability só separa os universos cliente e
 * atendente. O admin não usa o aplicativo e recebe 403.
 */
final class ApiTokenIssuer
{
    private const DEFAULT_DEVICE_NAME = 'mobile';

    public function issue(User $user, ?string $deviceName = null): NewAccessToken
    {
        if (! $user->is_active) {
            throw new AuthorizationException('Conta inativa.');
        }

        $ability = match ($user->role) {
            UserRole::Client => 'client',
            UserRole::Attendant => 'attendant',
            UserRole::Admin => throw new AuthorizationException(
                'O aplicativo móvel é exclusivo para clientes e atendentes.',
            ),
        };

        $name = Str::squish((string) $deviceName);

        return $user->createToken(
            $name !== '' ? Str::limit($name, 100, '') : self::DEFAULT_DEVICE_NAME,
            [$ability],
        );
    }
}
