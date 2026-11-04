<?php

namespace App\Support;

/**
 * Formata taxas armazenadas em basis points (1 bps = 0,01%).
 *
 * A comissão é persistida em bps para evitar arredondamentos em ponto
 * flutuante. Ex.: 3000 => "30%", 2550 => "25,5%". Valores nulos (consulta
 * ainda não iniciada) são exibidos como "—".
 */
final class Rate
{
    public static function formatBps(?int $basisPoints): string
    {
        if ($basisPoints === null) {
            return '—';
        }

        $sign = $basisPoints < 0 ? '-' : '';
        $absolute = abs($basisPoints);
        $whole = intdiv($absolute, 100);
        $fraction = $absolute % 100;

        if ($fraction === 0) {
            return $sign.$whole.'%';
        }

        $decimals = rtrim(str_pad((string) $fraction, 2, '0', STR_PAD_LEFT), '0');

        return $sign.$whole.','.$decimals.'%';
    }

    /**
     * Valor decimal do percentual para inputs HTML (ex.: 2550 => "25.50").
     */
    public static function inputValue(int $basisPoints): string
    {
        return intdiv($basisPoints, 100).'.'.str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Converte um percentual digitado ("25", "25.5", "25,50") em basis points.
     * Retorna null para formato inválido, valor negativo ou acima de 100%.
     */
    public static function parsePercentToBps(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        if (! preg_match('/^(\d{1,3})(?:[.,](\d{1,2}))?$/', $normalized, $matches)) {
            return null;
        }

        $basisPoints = (int) $matches[1] * 100
            + (int) str_pad($matches[2] ?? '0', 2, '0', STR_PAD_RIGHT);

        return $basisPoints <= 10000 ? $basisPoints : null;
    }
}
