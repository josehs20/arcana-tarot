<?php

namespace App\Support\Legacy;

use InvalidArgumentException;

/**
 * Configuração de uma execução da importação de usuários legados.
 *
 * O mapeamento do banco é fixo (`LegacySchemaPreset`); aqui ficam apenas as
 * decisões da execução: arquivos, gravação, força e limite de registros.
 */
final class LegacyImportOptions
{
    /**
     * Caminhos informados no comando: arquivos, diretório ou lista.
     *
     * @var array<int, string>
     */
    public readonly array $sourcePaths;

    /**
     * Caminho para exibição em relatório e auditoria.
     */
    public readonly string $dumpPath;

    /**
     * @param  string|array<int, string>  $dumpPath  arquivo, diretório ou lista
     */
    public function __construct(
        string|array $dumpPath,
        public readonly bool $execute = false,
        public readonly bool $force = false,
        public readonly ?int $limit = null,
    ) {
        $paths = array_values(array_filter(
            array_map(static fn (string|int|float|null $path): string => trim((string) $path), is_array($dumpPath) ? $dumpPath : [$dumpPath]),
            static fn (string $path): bool => $path !== '',
        ));

        if ($paths === []) {
            throw new InvalidArgumentException('O caminho do arquivo legado é obrigatório.');
        }

        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('O limite de registros deve ser maior que zero.');
        }

        $this->sourcePaths = $paths;
        $this->dumpPath = implode(', ', $paths);
    }
}
