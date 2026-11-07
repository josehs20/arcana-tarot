<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Sexo/gênero declarado pelo titular da conta.
 *
 * O cadastro público exige a escolha; contas criadas pelo Google, pelo painel
 * administrativo ou importadas do banco legado podem ficar sem o dado (`null`
 * significa "não informado"). O valor é gravado como texto curto em
 * `users.gender`, no mesmo padrão de `users.role`.
 */
enum Gender: string
{
    case Female = 'female';
    case Male = 'male';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Female => 'Feminino',
            self::Male => 'Masculino',
            self::Other => 'Outro',
        };
    }

    /**
     * Opções para `<select>`: valor persistido => rótulo exibido.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $gender) {
            $options[$gender->value] = $gender->label();
        }

        return $options;
    }

    /**
     * Converte o valor livre da coluna `sexo` do banco legado para o enum.
     *
     * Aceita as grafias mais comuns em português e inglês, com ou sem acento.
     * Valores vazios ou desconhecidos viram `null` — a importação nunca
     * inventa um sexo.
     */
    public static function fromLegacyLabel(?string $value): ?self
    {
        $normalized = Str::ascii(mb_strtolower(trim((string) $value)));

        return match (preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '') {
            'f', 'fem', 'feminino', 'female', 'mulher' => self::Female,
            'm', 'masc', 'masculino', 'male', 'homem' => self::Male,
            'o', 'outro', 'outra', 'other', 'nao binario', 'non binary', 'nb', 'trans', 'transgenero' => self::Other,
            default => null,
        };
    }
}
