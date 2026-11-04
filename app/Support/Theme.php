<?php

namespace App\Support;

use App\Enums\ThemePreference;

/**
 * Resolve o tema ativo do request atual.
 *
 * A coluna `users.theme_preference` é a fonte da verdade para usuários
 * autenticados. Visitantes usam o padrão claro no servidor e têm a escolha
 * aplicada no navegador (localStorage) antes da primeira pintura.
 */
final class Theme
{
    public static function current(): ThemePreference
    {
        $preference = auth()->user()?->theme_preference;

        return $preference instanceof ThemePreference ? $preference : ThemePreference::Light;
    }

    public static function isDark(): bool
    {
        return self::current()->isDark();
    }

    public static function isPersisted(): bool
    {
        return auth()->check();
    }
}
