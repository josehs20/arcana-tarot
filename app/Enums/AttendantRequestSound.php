<?php

namespace App\Enums;

/**
 * Toque executado no painel do atendente quando chega uma nova solicitação.
 *
 * A maioria dos toques é sintetizada no navegador (Web Audio API), sem arquivo
 * de áudio no bundle. A exceção é `OlhaOGas`, servido pelo MP3 versionado em
 * `public/olha-o-gas.mp3`. A preferência é individual do atendente e pode ser
 * alterada pela administração em "Atendentes".
 */
enum AttendantRequestSound: string
{
    case Siren = 'siren';
    case Bell = 'bell';
    case Chime = 'chime';
    case Pulse = 'pulse';
    case OlhaOGas = 'olha-o-gas';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Siren => 'Sirene (padrão)',
            self::Bell => 'Campainha',
            self::Chime => 'Sino',
            self::Pulse => 'Bip suave',
            self::OlhaOGas => 'Olha o gás (MP3)',
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
            self::OlhaOGas => 'Toque gravado em MP3 da própria plataforma, padrão dos atendentes de demonstração.',
            self::Custom => 'Arquivo MP3 enviado pela administração, com até 5 segundos.',
        };
    }

    /**
     * Toques predefinidos: sintetizados no navegador ou servidos por um MP3 da
     * plataforma. O MP3 enviado pela administração (`Custom`) é sempre avulso.
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

    /**
     * Arquivo versionado em `public/` usado pelo toque.
     *
     * `null` nos toques sintetizados no navegador — nesse caso nenhum download
     * é necessário e o `AudioContext` gera o som localmente.
     */
    public function file(): ?string
    {
        return match ($this) {
            self::OlhaOGas => 'olha-o-gas.mp3',
            default => null,
        };
    }

    /**
     * URL pública do arquivo versionado, quando o toque vem de um MP3.
     *
     * Usa `asset()` para respeitar o caminho base da instalação (subdiretório
     * ou CDN configurado em `ASSET_URL`).
     */
    public function url(): ?string
    {
        $file = $this->file();

        return $file !== null ? asset($file) : null;
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
