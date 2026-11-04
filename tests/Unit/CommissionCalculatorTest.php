<?php

namespace Tests\Unit;

use App\Support\CommissionCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CommissionCalculatorTest extends TestCase
{
    public static function commissionCases(): array
    {
        return [
            'cem reais a vinte e cinco por cento' => [10000, 2500, 2500],
            'meio centavo arredonda para cima' => [2, 2500, 1],
            'vinte e cinco virgula vinte e cinco centavos arredonda para baixo' => [101, 2500, 25],
            'vinte e cinco virgula cinco centavos arredonda para cima' => [102, 2500, 26],
            'taxa zero nao gera comissao' => [10000, 0, 0],
            'taxa integral devolve toda a base' => [12345, 10000, 12345],
            'taxa fracionaria usa basis points' => [10000, 2555, 2555],
            'base de um centavo arredonda half-up' => [1, 9999, 1],
        ];
    }

    #[DataProvider('commissionCases')]
    public function test_calculates_commission_in_cents_with_half_up_rounding(
        int $baseCents,
        int $rateBps,
        int $expectedCents,
    ): void {
        $calculator = new CommissionCalculator;

        $this->assertSame($expectedCents, $calculator->calculate($baseCents, $rateBps));
    }

    public function test_rejects_negative_base(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CommissionCalculator)->calculate(-1, 2500);
    }

    public function test_rejects_rate_above_one_hundred_percent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CommissionCalculator)->calculate(10000, 10001);
    }
}
