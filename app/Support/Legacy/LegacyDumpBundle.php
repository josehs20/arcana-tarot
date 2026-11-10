<?php

namespace App\Support\Legacy;

use Generator;

/**
 * Conjunto de arquivos legados lidos como uma única origem.
 *
 * O legado costuma ser exportado uma tabela por arquivo (`eam_usuarios.csv`,
 * `eam_clientes.csv`). Este agregador junta as tabelas de todos os arquivos para
 * que a importação continue sendo uma execução só, reaproveitando o mesmo plano,
 * as mesmas regras de senha/crédito e a mesma auditoria.
 *
 * Quando dois arquivos declaram a mesma tabela, vale a primeira ocorrência — a
 * ordem dos arquivos é a ordem informada no comando (ou a ordem alfabética do
 * diretório).
 */
final class LegacyDumpBundle implements LegacyDumpSource
{
    /** @var array<int, LegacyDumpSource> arquivos na ordem de leitura */
    private readonly array $sources;

    /** @var array<string, LegacyDumpSource>|null tabela (minúscula) => arquivo que a contém */
    private ?array $owners = null;

    /**
     * @param  LegacyDumpSource  ...$sources  arquivos na ordem de leitura
     */
    public function __construct(LegacyDumpSource ...$sources)
    {
        $this->sources = array_values($sources);
    }

    public function files(): array
    {
        $files = [];

        foreach ($this->sources as $source) {
            foreach ($source->files() as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    public function size(): int
    {
        $size = 0;

        foreach ($this->files() as $file) {
            $size += $file['size'];
        }

        return $size;
    }

    public function sourceLabel(): string
    {
        return 'conjunto de arquivos';
    }

    public function integrityIssues(): array
    {
        $issues = [];

        foreach ($this->sources as $source) {
            foreach ($source->integrityIssues() as $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    public function tables(): array
    {
        $tables = [];
        $owners = [];

        foreach ($this->sources as $source) {
            foreach ($source->tables() as $key => $table) {
                if (array_key_exists($key, $tables)) {
                    continue;
                }

                $tables[$key] = $table;
                $owners[$key] = $source;
            }
        }

        $this->owners = $owners;

        return $tables;
    }

    public function rows(LegacyDumpTable $table): Generator
    {
        if ($this->owners === null) {
            $this->tables();
        }

        $owner = $this->owners[mb_strtolower($table->name)] ?? null;

        if ($owner === null) {
            return;
        }

        yield from $owner->rows($table);
    }
}
