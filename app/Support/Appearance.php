<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Aparência configurável do site (cores e fontes).
 *
 * Lê o setting JSON `appearance` (com fallback para `config/appearance.php`),
 * valida tudo antes de usar e monta o bloco de CSS que sobrescreve os tokens
 * `--arcana-*`. Como o design system inteiro é baseado em custom properties, o
 * `<style>` injetado no `<head>` (depois do bundle) vale para os temas claro e
 * escuro sem recompilar nada.
 *
 * Só os tokens alterados pelo administrador geram override: quando nada foi
 * personalizado, o CSS original de `_theme.scss` continua valendo.
 */
final class Appearance
{
    private const HEX_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /** @var array<string, mixed>|null */
    private ?array $resolvedCache = null;

    /**
     * Configuração resolvida (cores por tema + fontes), já com defaults.
     *
     * @return array{
     *     colors: array<string, array<string, string>>,
     *     fonts: array{display: string, body: string}
     * }
     */
    public function resolved(): array
    {
        if ($this->resolvedCache !== null) {
            return $this->resolvedCache;
        }

        $stored = $this->stored();
        /** @var array<string, array<string, string>> $defaults */
        $defaults = (array) config('appearance.defaults', []);

        $colors = [];

        foreach (['light', 'dark'] as $theme) {
            $colors[$theme] = [];

            foreach (array_keys((array) ($defaults[$theme] ?? [])) as $key) {
                $candidate = $stored['colors'][$theme][$key] ?? null;
                $colors[$theme][(string) $key] = $this->validHex($candidate)
                    ?? (string) ($defaults[$theme][$key] ?? '#000000');
            }
        }

        $fonts = [
            'display' => $this->validFont('display', $stored['fonts']['display'] ?? null),
            'body' => $this->validFont('body', $stored['fonts']['body'] ?? null),
        ];

        return $this->resolvedCache = [
            'colors' => $colors,
            'fonts' => $fonts,
        ];
    }

    /**
     * Indica se há alguma personalização que gere override de CSS.
     */
    public function isCustomized(): bool
    {
        return $this->css() !== '';
    }

    /**
     * Bloco CSS com os overrides validados. Vazio quando nada foi alterado.
     */
    public function css(): string
    {
        $rules = [];

        foreach (['light', 'dark'] as $theme) {
            $declarations = $this->themeDeclarations($theme);

            if ($declarations !== []) {
                $rules[] = 'html[data-theme="'.$theme.'"]{'.implode(';', $declarations).'}';
            }
        }

        $fontDeclarations = $this->fontDeclarations();

        if ($fontDeclarations !== []) {
            // `html:root` (0,1,1) vence o `:root` (0,1,0) de `_base.scss`,
            // inclusive contra o CSS injetado pelo Vite em modo dev.
            $rules[] = 'html:root{'.implode(';', $fontDeclarations).'}';
        }

        return implode("\n", $rules);
    }

    /**
     * Rótulos das cores para o formulário administrativo.
     *
     * @return array<string, string>
     */
    public function colorLabels(): array
    {
        /** @var array<string, string> $labels */
        $labels = (array) config('appearance.color_labels', []);

        return $labels;
    }

    /**
     * Opções de fonte (valor => rótulo) para um tipo (`display` ou `body`).
     *
     * @return array<string, string>
     */
    public function fontOptions(string $kind): array
    {
        $options = [];

        foreach ((array) config("appearance.fonts.{$kind}", []) as $key => $meta) {
            $options[(string) $key] = (string) ($meta['label'] ?? $key);
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private function themeDeclarations(string $theme): array
    {
        $resolved = $this->resolved()['colors'][$theme];
        /** @var array<string, string> $defaults */
        $defaults = (array) config("appearance.defaults.{$theme}", []);

        $declarations = [];

        $gold = $resolved['gold'] ?? ($defaults['gold'] ?? null);
        if ($gold !== ($defaults['gold'] ?? null)) {
            $declarations[] = '--arcana-gold:'.$this->hex($gold);
            $declarations[] = '--arcana-gold-rgb:'.$this->rgbChannels($gold);
            $declarations[] = '--arcana-gold-light:'.$this->mix($gold, $theme === 'light' ? '#000000' : '#ffffff', $theme === 'light' ? 0.18 : 0.22);
            $declarations[] = '--arcana-gold-dark:'.$this->mix($gold, '#000000', 0.3);
        }

        $purple = $resolved['purple'] ?? ($defaults['purple'] ?? null);
        if ($purple !== ($defaults['purple'] ?? null)) {
            $declarations[] = '--arcana-purple:'.$this->hex($purple);
            $declarations[] = '--arcana-purple-rgb:'.$this->rgbChannels($purple);
            $declarations[] = '--arcana-purple-light:'.$this->mix($purple, '#ffffff', 0.18);
            $declarations[] = '--arcana-purple-soft:'.$this->mix($purple, '#ffffff', 0.32);
        }

        $bg = $resolved['bg'] ?? ($defaults['bg'] ?? null);
        if ($bg !== ($defaults['bg'] ?? null)) {
            $declarations[] = '--arcana-bg:'.$this->hex($bg);
            $declarations[] = '--arcana-bg-soft:'.$this->mix($bg, $theme === 'light' ? '#000000' : '#ffffff', 0.06);
        }

        $surface = $resolved['surface'] ?? ($defaults['surface'] ?? null);
        if ($surface !== ($defaults['surface'] ?? null)) {
            $surface2 = $this->mix($surface, $theme === 'light' ? '#000000' : '#ffffff', 0.05);
            $declarations[] = '--arcana-surface:'.$this->hex($surface);
            $declarations[] = '--arcana-surface-2:'.$surface2;
            $declarations[] = '--arcana-surface-2-rgb:'.$this->rgbChannels($surface2);
        }

        return $declarations;
    }

    /**
     * @return list<string>
     */
    private function fontDeclarations(): array
    {
        $fonts = $this->resolved()['fonts'];
        $declarations = [];

        foreach (['display', 'body'] as $kind) {
            $key = $fonts[$kind];
            $default = (string) config("appearance.default_fonts.{$kind}");

            if ($key === $default) {
                continue;
            }

            $stack = config("appearance.fonts.{$kind}.{$key}.stack");

            if (is_string($stack) && $stack !== '') {
                $declarations[] = '--arcana-font-'.$kind.':'.$stack;
            }
        }

        return $declarations;
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        $raw = Setting::query()
            ->where('key', (string) config('appearance.settings_key', 'appearance'))
            ->value('value');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function validHex(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::HEX_PATTERN, $value) === 1
            ? strtolower($value)
            : null;
    }

    private function validFont(string $kind, mixed $key): string
    {
        $default = (string) config("appearance.default_fonts.{$kind}");

        if (is_string($key) && array_key_exists($key, (array) config("appearance.fonts.{$kind}", []))) {
            return $key;
        }

        return $default;
    }

    private function hex(string $hex): string
    {
        return $this->validHex($hex) ?? '#000000';
    }

    private function rgbChannels(string $hex): string
    {
        [$r, $g, $b] = $this->hexToRgb($hex);

        return $r.', '.$g.', '.$b;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($this->hex($hex), '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Mistura duas cores hex. `$ratio` 0 = $base, 1 = $toward.
     */
    private function mix(string $base, string $toward, float $ratio): string
    {
        [$br, $bg, $bb] = $this->hexToRgb($base);
        [$tr, $tg, $tb] = $this->hexToRgb($toward);

        $blend = static fn (int $from, int $to): string => str_pad(
            (string) dechex((int) round($from + ($to - $from) * $ratio)),
            2,
            '0',
            STR_PAD_LEFT,
        );

        return '#'.$blend($br, $tr).$blend($bg, $tg).$blend($bb, $tb);
    }
}
