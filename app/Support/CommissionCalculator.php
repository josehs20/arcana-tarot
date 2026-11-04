<?php

namespace App\Support;

use InvalidArgumentException;
use OverflowException;

/**
 * Cálculo de comissão com aritmética inteira e arredondamento half-up.
 *
 * A taxa é recebida em basis points (0–10000) e a base em centavos. O resultado
 * é o centavo mais próximo, sem qualquer operação em ponto flutuante.
 */
final class CommissionCalculator
{
    public function calculate(int $baseCents, int $rateBps): int
    {
        if ($baseCents < 0) {
            throw new InvalidArgumentException('A base da comissão não pode ser negativa.');
        }

        if ($rateBps < 0 || $rateBps > 10000) {
            throw new InvalidArgumentException('A taxa da comissão deve estar entre 0 e 10000 basis points.');
        }

        if ($baseCents === 0 || $rateBps === 0) {
            return 0;
        }

        if ($baseCents > intdiv(PHP_INT_MAX - 5000, $rateBps)) {
            throw new OverflowException('O cálculo da comissão excede o limite numérico suportado.');
        }

        return intdiv(($baseCents * $rateBps) + 5000, 10000);
    }
}
