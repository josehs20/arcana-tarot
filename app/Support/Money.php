<?php

namespace App\Support;

/**
 * Formatação monetária em centavos para o padrão brasileiro.
 *
 * Valores são sempre persistidos em centavos; a conversão para exibição
 * acontece apenas na camada de apresentação.
 */
final class Money
{
    /**
     * Converte um valor decimal digitado ("10", "10.50", "10,50" ou
     * "1.234,56") em centavos inteiros, sem usar float.
     */
    public static function parseDecimalToCents(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim(str_replace(['R$', ' '], '', $value));

        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, ',')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        }

        if (! preg_match('/^(\d{1,15})(?:\.(\d{1,2}))?$/', $normalized, $matches)) {
            return null;
        }

        $whole = (int) $matches[1];

        if ($whole > intdiv(PHP_INT_MAX - 99, 100)) {
            return null;
        }

        $fraction = str_pad($matches[2] ?? '0', 2, '0', STR_PAD_RIGHT);

        return $whole * 100 + (int) $fraction;
    }

    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        $reais = intdiv($absolute, 100);
        $centavos = str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return $sign.'R$ '.number_format($reais, 0, ',', '.').','.$centavos;
    }
}
