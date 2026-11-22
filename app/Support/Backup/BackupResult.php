<?php

namespace App\Support\Backup;

/**
 * Resultado de uma execução de {@see BackupManager}.
 *
 * `directory` é o conjunto de backup concluído (nome `arcana-backup-...`),
 * `pruned` os conjuntos antigos removidos pela retenção e `skipped` o que não
 * pôde ser lido do storage. O comando usa esses dados apenas para relatar; a
 * verdade do backup está no diretório e no `manifest.json`.
 */
final readonly class BackupResult
{
    /**
     * @param  list<string>  $pruned
     * @param  list<string>  $skipped
     */
    public function __construct(
        public string $directory,
        public DatabaseDumpResult $database,
        public ?StorageArchiveResult $files,
        public array $pruned,
        public array $skipped,
        public float $seconds,
    ) {}

    public function totalBytes(): int
    {
        return $this->database->bytes + ($this->files?->bytes ?? 0);
    }
}
