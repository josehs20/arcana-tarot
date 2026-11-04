<?php

namespace Tests\Unit;

use App\Support\Legacy\LegacyImportPlan;
use App\Support\Legacy\LegacyUserRecordMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyUserRecordMapperTest extends TestCase
{
    /**
     * @return array<string, array{0: string|null, 1: int|null}>
     */
    public static function moneyProvider(): array
    {
        return [
            'decimal com ponto' => ['150.75', 15075],
            'decimal com vírgula' => ['150,75', 15075],
            'milhar brasileiro' => ['1.234,56', 123456],
            'sem centavos' => ['42', 4200],
            'com centavo único' => ['7,5', 750],
            'vazio vale zero' => ['', 0],
            'espaços são ignorados' => [' 10.00 ', 1000],
            'prefixo de moeda' => ['R$ 99,90', 9990],
            'negativo' => ['-10.00', -1000],
            'arredonda para cima' => ['1.239', 124],
            'arredonda para baixo' => ['1.234', 123],
            'texto inválido' => ['abc', null],
            'múltiplos separadores' => ['1,2,3', null],
        ];
    }

    #[DataProvider('moneyProvider')]
    public function test_it_parses_legacy_money_into_cents(?string $value, ?int $expected): void
    {
        $mapper = new LegacyUserRecordMapper(new LegacyImportPlan);

        $this->assertSame($expected, $mapper->parseCents($value));
    }
}
