<?php

namespace Tests\Unit;

use App\Enums\Gender;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Conversão do `sexo` do banco legado para o enum do Arcana.
 */
class GenderTest extends TestCase
{
    /**
     * @return array<string, array{0: string|null, 1: Gender|null}>
     */
    public static function legacyProvider(): array
    {
        return [
            'feminino' => ['Feminino', Gender::Female],
            'f' => ['F', Gender::Female],
            'female' => ['female', Gender::Female],
            'masculino' => ['Masculino', Gender::Male],
            'm' => ['M', Gender::Male],
            'male' => ['male', Gender::Male],
            'outro' => ['Outro', Gender::Other],
            'não binário' => ['Não binário', Gender::Other],
            'vazio' => ['', null],
            'nulo' => [null, null],
            'desconhecido' => ['X', null],
            'não informado' => ['Não informado', null],
        ];
    }

    #[DataProvider('legacyProvider')]
    public function test_it_converts_legacy_labels(?string $value, ?Gender $expected): void
    {
        $this->assertSame($expected, Gender::fromLegacyLabel($value));
    }

    public function test_options_expose_the_available_choices(): void
    {
        $this->assertSame([
            'female' => 'Feminino',
            'male' => 'Masculino',
            'other' => 'Outro',
        ], Gender::options());
    }
}
