<?php

namespace App\Support\Backup;

/**
 * Resultado do arquivo `files.tar.gz` gravado por {@see StorageArchiver}.
 *
 * `path` é vazio quando nenhum arquivo pôde ser lido (o arquivo não é criado).
 * `skipped` lista o que ficou de fora por ausência ou permissão negada — o
 * comando mostra essa lista para o operador conferir.
 */
final readonly class StorageArchiveResult
{
    /**
     * @param  list<string>  $roots
     * @param  list<string>  $skipped
     */
    public function __construct(
        public string $path,
        public array $roots,
        public int $files,
        public int $bytes,
        public string $sha256,
        public array $skipped,
    ) {}
}
