<?php

namespace App\Enums;

enum ThemePreference: string
{
    case Light = 'light';
    case Dark = 'dark';

    public function label(): string
    {
        return match ($this) {
            self::Light => 'Claro',
            self::Dark => 'Escuro',
        };
    }

    public function isDark(): bool
    {
        return $this === self::Dark;
    }
}
