<?php

namespace App\Support\Backup;

/**
 * Resultado de uma importação de banco por arquivo SQL.
 *
 * `dropped` é quantas tabelas existiam antes (e foram apagadas), `tables` o que
 * o dump recriou, `statements` quantos comandos foram executados. O painel usa
 * esses números para confirmar que a importação não parou no meio.
 */
final readonly class DatabaseImportResult
{
    public function __construct(
        public string $driver,
        public int $dropped,
        public int $statements,
        public int $tables,
        public int $bytes,
        public float $seconds,
    ) {}
}
