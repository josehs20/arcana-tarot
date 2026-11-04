<?php

namespace App\Enums;

/**
 * Grupos de links editáveis do site.
 *
 * O conteúdo institucional e legal continua estático no rodapé porque é
 * composto por páginas fixas do produto.
 */
enum SiteNavigationGroup: string
{
    case Primary = 'primary';
    case Footer = 'footer';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Navegação principal',
            self::Footer => 'Rodapé — navegação',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $group): string => $group->value, self::cases());
    }
}
