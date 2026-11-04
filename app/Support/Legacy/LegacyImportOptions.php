<?php

namespace App\Support\Legacy;

use InvalidArgumentException;

/**
 * Configuração de uma execução da importação de usuários legados.
 *
 * Os mapas de tabela/coluna existem para os casos em que a heurística do
 * `LegacySchemaInspector` não consegue decidir sozinha.
 */
final class LegacyImportOptions
{
    /**
     * @param  array<string, string|null>  $mapping  sobreposições de tabela/coluna
     */
    public function __construct(
        public readonly string $dumpPath,
        public readonly bool $execute = false,
        public readonly bool $force = false,
        public readonly ?int $limit = null,
        public readonly array $mapping = [],
    ) {
        if (trim($dumpPath) === '') {
            throw new InvalidArgumentException('O caminho do dump legado é obrigatório.');
        }

        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('O limite de registros deve ser maior que zero.');
        }
    }

    public function withLimit(?int $limit): self
    {
        return new self($this->dumpPath, $this->execute, $this->force, $limit, $this->mapping);
    }
}
