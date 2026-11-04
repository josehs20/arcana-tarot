<?php

namespace App\Support\Legacy;

/**
 * Linha do dump com as colunas identificadas pelo nome declarado na tabela.
 *
 * `INSERT` sem lista de colunas chega com chaves posicionais; nesse caso os
 * nomes são recuperados da ordem do `CREATE TABLE`.
 */
final class LegacyRow
{
    /**
     * @param  array<string, string|null>  $values  chaves em minúsculas
     */
    private function __construct(private readonly array $values) {}

    /**
     * @param  array<string|int, string|null>  $row
     */
    public static function from(array $row, LegacyDumpTable $table): self
    {
        $values = [];

        foreach ($row as $key => $value) {
            if (is_int($key)) {
                $key = $table->columns[$key]->name ?? (string) $key;
            }

            $values[mb_strtolower((string) $key)] = is_scalar($value) ? (string) $value : null;
        }

        return new self($values);
    }

    public function get(?string $column): ?string
    {
        if ($column === null) {
            return null;
        }

        return $this->values[mb_strtolower($column)] ?? null;
    }

    public function text(?string $column): string
    {
        return trim((string) $this->get($column));
    }
}
