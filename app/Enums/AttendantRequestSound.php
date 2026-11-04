<?php

namespace App\Enums;

/**
 * Toque executado no painel do atendente quando chega uma nova solicitação.
 *
 * Os sons são sintetizados no navegador (Web Audio API), sem arquivo de áudio
 * no bundle. A preferência é individual do atendente e pode ser alterada pela
 * administração em "Atendentes".
 */
enum AttendantRequestSound: string
{
    case Siren = 'siren';
    case Bell = 'bell';
    case Chime = 'chime';
    case Pulse = 'pulse';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Siren => 'Sirene (padrão)',
            self::Bell => 'Campainha',
            self::Chime => 'Sino',
            self::Pulse => 'Bip suave',
            self::Custom => 'Personalizado (MP3)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Siren => 'Dois tons agudos alternados, repetidos até o pedido ser respondido.',
            self::Bell => 'Duas notas curtas e discretas, semelhantes a uma campainha.',
            self::Chime => 'Três notas ascendentes com som de sino.',
            self::Pulse => 'Dois bips suaves e breves para ambientes silenciosos.',
            self::Custom => 'Arquivo MP3 enviado pela administração, com até 5 segundos.',
        };
    }

    /**
     * Sons sintetizados que não dependem de arquivo enviado.
     *
     * @return array<int, self>
     */
    public static function presets(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $sound): bool => $sound !== self::Custom,
        ));
    }

    public function isCustom(): bool
    {
        return $this === self::Custom;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $sound): string => $sound->value, self::cases());
    }

    public static function default(): self
    {
        return self::Siren;
    }
}
