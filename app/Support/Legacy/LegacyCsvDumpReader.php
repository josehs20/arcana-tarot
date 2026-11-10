<?php

namespace App\Support\Legacy;

use Generator;

/**
 * Leitor de exportações CSV do banco legado.
 *
 * A exportação do phpMyAdmin grava um bloco de registros por tabela, todos no
 * mesmo arquivo e sem informar o nome de cada tabela: o primeiro registro de
 * cada bloco é o cabeçalho com as colunas. O leitor identifica os blocos pelo
 * cabeçalho e dá nome a eles nesta ordem:
 *
 * 1. nome do arquivo, quando ele se chama como a tabela (`eam_usuarios.csv`);
 * 2. assinatura de colunas do `LegacySchemaPreset` (blocos sem nome);
 * 3. nome sintético (`arquivo#2`), quando nada casa — o bloco aparece no
 *    relatório, mas é ignorado pela importação.
 *
 * O arquivo é lido em fluxo, sem carregar tudo na memória, e nenhum valor é
 * executado como SQL. Linhas com `NULL` viram `null`; o tipo das colunas é
 * inferido dos primeiros registros porque o CSV não traz o `CREATE TABLE`
 * (a detecção de saldo depende de a coluna ser numérica).
 */
final class LegacyCsvDumpReader implements LegacyDumpSource
{
    private const DELIMITER = ',';

    private const ENCLOSURE = '"';

    /**
     * Registros usados para inferir tipo e nulidade das colunas.
     */
    private const SAMPLE_ROWS = 100;

    private const NUMERIC_PATTERN = '/^-?\d+(?:\.\d+)?$/';

    /** @var array<string, LegacyDumpTable>|null */
    private ?array $tables = null;

    /** @var array<string, int> posição de cada bloco no arquivo */
    private array $blocks = [];

    /** @var array<int, array{columns: array<int, string>, samples: array<int, array<int, string>>}>|null */
    private ?array $structure = null;

    public function __construct(private readonly string $path) {}

    public function files(): array
    {
        return [['path' => $this->path, 'size' => $this->size()]];
    }

    public function size(): int
    {
        return LegacyDumpTail::size($this->path);
    }

    public function sourceLabel(): string
    {
        return 'arquivo CSV';
    }

    public function integrityIssues(): array
    {
        $marker = LegacyDumpTail::truncationMarker($this->path);

        if ($marker === null) {
            return [];
        }

        return [['path' => $this->path, 'marker' => $marker, 'unterminated' => false]];
    }

    public function tables(): array
    {
        if ($this->tables !== null) {
            return $this->tables;
        }

        $tables = [];
        $taken = [];
        $blocks = $this->structure();

        foreach ($blocks as $index => $block) {
            $name = $this->nameFor($block['columns'], $index, $taken, count($blocks));

            $taken[mb_strtolower($name)] = true;
            $this->blocks[mb_strtolower($name)] = $index;

            $tables[mb_strtolower($name)] = new LegacyDumpTable(
                name: $name,
                columns: $this->columnsFor($block['columns'], $block['samples']),
            );
        }

        return $this->tables = $tables;
    }

    public function rows(LegacyDumpTable $table): Generator
    {
        $target = $this->blocks[mb_strtolower($table->name)] ?? null;

        if ($target === null) {
            $this->tables();

            $target = $this->blocks[mb_strtolower($table->name)] ?? null;
        }

        if ($target === null) {
            return;
        }

        $index = -1;
        $columns = null;

        foreach ($this->records() as $row) {
            if ($this->isHeader($row)) {
                $index++;

                if ($index === $target) {
                    $columns = $this->normalizeColumns($row);

                    continue;
                }

                if ($columns !== null) {
                    return;
                }

                continue;
            }

            if ($columns === null) {
                continue;
            }

            $record = [];

            foreach ($columns as $position => $column) {
                $record[$column] = $this->value($row[$position] ?? null);
            }

            yield $record;
        }
    }

    /**
     * Separa o arquivo em blocos a partir dos cabeçalhos e guarda uma amostra de
     * registros de cada um para inferir o tipo das colunas.
     *
     * @return array<int, array{columns: array<int, string>, samples: array<int, array<int, string>>}>
     */
    private function structure(): array
    {
        if ($this->structure !== null) {
            return $this->structure;
        }

        $blocks = [];
        $current = null;

        foreach ($this->records() as $row) {
            if ($this->isHeader($row)) {
                if ($current !== null) {
                    $blocks[] = $current;
                }

                $current = ['columns' => $this->normalizeColumns($row), 'samples' => []];

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (count($current['samples']) < self::SAMPLE_ROWS) {
                $current['samples'][] = $row;
            }
        }

        if ($current !== null) {
            $blocks[] = $current;
        }

        return $this->structure = $blocks;
    }

    /**
     * Nome do bloco: arquivo nomeado como a tabela, assinatura de colunas ou
     * nome sintético para blocos que a importação não usa.
     *
     * @param  array<int, string>  $columns
     * @param  array<string, bool>  $taken
     */
    private function nameFor(array $columns, int $index, array $taken, int $blockCount): string
    {
        $fromPath = $this->nameFromPath($taken, $blockCount);

        if ($fromPath !== null) {
            return $fromPath;
        }

        return $this->signatureName($columns, $taken) ?? $this->syntheticName($index);
    }

    /**
     * Nome da tabela declarado no próprio arquivo (`eam_usuarios.csv`,
     * `eam_usuarios (1).csv`). Só vale para arquivos de um único bloco, porque
     * aí não há dúvida sobre a qual tabela os registros pertencem.
     *
     * @param  array<string, bool>  $taken
     */
    private function nameFromPath(array $taken, int $blockCount): ?string
    {
        if ($blockCount !== 1) {
            return null;
        }

        $base = preg_replace('/\s*\(\d+\)$/', '', pathinfo($this->path, PATHINFO_FILENAME)) ?? '';
        $normalized = $this->normalizeName($base);

        foreach (LegacySchemaPreset::tableNames() as $table) {
            if ($normalized === $this->normalizeName($table) && ! isset($taken[mb_strtolower($table)])) {
                return $table;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<string, bool>  $taken
     */
    private function signatureName(array $columns, array $taken): ?string
    {
        foreach (LegacySchemaPreset::SIGNATURES as $table => $groups) {
            if (isset($taken[mb_strtolower($table)])) {
                continue;
            }

            foreach ($groups as $group) {
                if (array_intersect($group, $columns) === []) {
                    continue 2;
                }
            }

            return $table;
        }

        return null;
    }

    private function syntheticName(int $index): string
    {
        $base = preg_replace('/\s*\(\d+\)$/', '', pathinfo($this->path, PATHINFO_FILENAME)) ?? 'csv';

        return $base.'#'.($index + 1);
    }

    private function normalizeName(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($value)) ?? '';
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, string>>  $samples
     * @return array<int, LegacyDumpColumn>
     */
    private function columnsFor(array $columns, array $samples): array
    {
        $result = [];

        foreach (array_keys($columns) as $position) {
            $result[] = new LegacyDumpColumn(
                name: $columns[$position],
                type: $this->inferType($position, $samples),
                nullable: $this->inferNullable($position, $samples),
            );
        }

        return $result;
    }

    /**
     * @param  array<int, array<int, string>>  $samples
     */
    private function inferType(int $position, array $samples): string
    {
        $found = false;

        foreach ($samples as $sample) {
            $value = $this->value($sample[$position] ?? null);

            if ($value === null || trim($value) === '') {
                continue;
            }

            if (preg_match(self::NUMERIC_PATTERN, trim($value)) !== 1) {
                return 'varchar';
            }

            $found = true;
        }

        return $found ? 'decimal' : 'varchar';
    }

    /**
     * @param  array<int, array<int, string>>  $samples
     */
    private function inferNullable(int $position, array $samples): bool
    {
        foreach ($samples as $sample) {
            $value = $this->value($sample[$position] ?? null);

            if ($value === null || trim($value) === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Registros do arquivo, já normalizados em UTF-8 e sem linhas vazias.
     *
     * @return Generator<int, array<int, string>>
     */
    private function records(): Generator
    {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw new LegacyDumpException('Não foi possível abrir o arquivo legado para leitura.');
        }

        try {
            $first = true;

            while (($row = fgetcsv($handle, null, self::DELIMITER, self::ENCLOSURE, '')) !== false) {
                $fields = [];

                foreach ($row as $value) {
                    $fields[] = $this->normalizeEncoding((string) $value);
                }

                if ($fields === [] || $fields === ['']) {
                    continue;
                }

                if ($first) {
                    $fields[0] = $this->stripBom($fields[0]);
                    $first = false;
                }

                yield $fields;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Diferença entre cabeçalho e registro: o cabeçalho tem apenas nomes de
     * coluna e começa pela chave (`"id","email","senha"`).
     *
     * @param  array<int, string>  $row
     */
    private function isHeader(array $row): bool
    {
        if (count($row) < 2) {
            return false;
        }

        foreach ($row as $field) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', trim($field)) !== 1) {
                return false;
            }
        }

        return mb_strtolower(trim($row[0])) === 'id';
    }

    /**
     * @param  array<int, string>  $row
     * @return array<int, string>
     */
    private function normalizeColumns(array $row): array
    {
        return array_map(fn (string $column): string => mb_strtolower(trim($column)), $row);
    }

    /**
     * Valor do registro: `NULL` e vazio do CSV viram `null`.
     */
    private function value(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return trim($value) === '' || strcasecmp(trim($value), 'NULL') === 0 ? null : $value;
    }

    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }

    /**
     * O legado mistura latin1 e utf8mb3; textos inválidos em UTF-8 são
     * convertidos para não corromper o banco novo.
     */
    private function normalizeEncoding(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252');

        return is_string($converted) ? $converted : $value;
    }
}
