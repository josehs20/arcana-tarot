<?php

namespace App\Enums;

/**
 * Formas aceitas de destino de um link editável.
 *
 * `route` aponta para uma rota nomeada da allowlist em `config/site.php`,
 * `anchor` para uma seção da home e `url` apenas para endereços https.
 */
enum SiteLinkType: string
{
    case Route = 'route';
    case Anchor = 'anchor';
    case Url = 'url';

    public function label(): string
    {
        return match ($this) {
            self::Route => 'Página interna',
            self::Anchor => 'Âncora na home',
            self::Url => 'Endereço externo',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
