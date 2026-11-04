<?php

namespace App\Support\Legacy;

/**
 * Tabela declarada em um `CREATE TABLE` do dump legado.
 *
 * As colunas são mantidas na ordem de declaração porque `INSERT` sem lista de
 * colunas depende dessa ordem.
 */
final class LegacyDumpTable
{
    /**
     * @param  array<int, LegacyDumpColumn>  $columns
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
    ) {}

    public function is(LegacyDumpTable $other): bool
    {
        return strcasecmp($this->name, $other->name) === 0;
    }

    /**
     * @return array<int, string>
     */
    public function columnNames(): array
    {
        return array_map(fn (LegacyDumpColumn $column): string => $column->name, $this->columns);
    }

    public function column(string $name): ?LegacyDumpColumn
    {
        foreach ($this->columns as $column) {
            if (strcasecmp($column->name, $name) === 0) {
                return $column;
            }
        }

        return null;
    }

    public function hasColumn(string $name): bool
    {
        return $this->column($name) !== null;
    }

    /**
     * Retorna a primeira coluna encontrada seguindo a prioridade dos nomes
     * candidatos.
     *
     * @param  array<int, string>  $candidates
     */
    public function findColumn(array $candidates): ?LegacyDumpColumn
    {
        foreach ($candidates as $candidate) {
            $column = $this->column($candidate);

            if ($column !== null) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Nomes das colunas para exibição no relatório.
     */
    public function describeColumns(int $limit = 12): string
    {
        $labels = array_map(
            fn (LegacyDumpColumn $column): string => $column->name.':'.$column->type,
            array_slice($this->columns, 0, $limit),
        );

        if (count($this->columns) > $limit) {
            $labels[] = '… +'.(count($this->columns) - $limit);
        }

        return implode(', ', $labels);
    }
}
