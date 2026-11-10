<?php

namespace Tests\Unit;

use App\Support\Legacy\LegacyDateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Datas de nascimento do banco legado: formatos mistos e "sem data".
 */
class LegacyDateParserTest extends TestCase
{
    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function dateProvider(): array
    {
        return [
            'iso' => ['1985-01-31', '1985-01-31'],
            'brasileiro' => ['31/01/1985', '1985-01-31'],
            'brasileiro com hífen' => ['31-01-1985', '1985-01-31'],
            'com hora' => ['1985-01-31 00:00:00', '1985-01-31'],
            'com espaços' => [' 31/01/1985 ', '1985-01-31'],
            'data zerada' => ['0000-00-00', null],
            'data zerada com hora' => ['0000-00-00 00:00:00', null],
            'vazio' => ['', null],
            'nulo' => [null, null],
            'texto livre' => ['sem data', null],
            'dia inexistente' => ['31/02/1985', null],
            'ano implausível' => ['31/01/1500', null],
            'data futura' => [now()->addYear()->format('d/m/Y'), null],
        ];
    }

    #[DataProvider('dateProvider')]
    public function test_it_normalizes_legacy_dates(?string $value, ?string $expected): void
    {
        $this->assertSame($expected, LegacyDateParser::parse($value));
    }
}
