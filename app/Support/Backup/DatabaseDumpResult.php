<?php

namespace App\Support\Backup;

/**
 * Resultado do dump lógico gravado por {@see DatabaseDumper}.
 *
 * Os números são os do arquivo final (já comprimido), exceto `rows` e `tables`,
 * que descrevem o conteúdo. O hash permite conferir a cópia off-site depois e
 * comparar com o `manifest.json` do backup.
 */
final readonly class DatabaseDumpResult
{
    public function __construct(
        public string $path,
        public string $driver,
        public string $database,
        public int $tables,
        public int $rows,
        public int $bytes,
        public string $sha256,
    ) {}
}
