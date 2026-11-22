<?php

namespace App\Support\Backup;

use Illuminate\Support\Carbon;

/**
 * Um conjunto de backup já gravado no disco (`arcana-backup-...`).
 *
 * Os dados vêm do `manifest.json` do próprio conjunto. Quando o manifesto está
 * ausente ou ilegível o conjunto ainda é listado — o operador precisa ver que
 * existe um diretório incompleto no destino — mas as contagens ficam nulas.
 */
final readonly class BackupSet
{
    /**
     * @param  array<string, mixed>|null  $manifest
     */
    public function __construct(
        public string $name,
        public string $path,
        public ?Carbon $createdAt,
        public ?array $manifest,
        public bool $hasDatabase,
        public bool $hasFiles,
        public int $bytes,
    ) {}

    /**
     * Dump do banco presente no conjunto. Sem ele não há o que restaurar.
     */
    public function hasRestorableDatabase(): bool
    {
        return $this->hasDatabase;
    }

    public function databaseBytes(): int
    {
        return (int) ($this->manifest['database']['bytes'] ?? 0);
    }

    public function databaseTables(): ?int
    {
        return $this->nullableInt($this->manifest['database']['tables'] ?? null);
    }

    public function databaseRows(): ?int
    {
        return $this->nullableInt($this->manifest['database']['rows'] ?? null);
    }

    public function databaseDriver(): ?string
    {
        $driver = $this->manifest['database']['driver'] ?? null;

        return is_string($driver) && $driver !== '' ? $driver : null;
    }

    public function databaseName(): ?string
    {
        $name = $this->manifest['database']['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function databaseSha256(): ?string
    {
        $hash = $this->manifest['database']['sha256'] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    public function filesCount(): ?int
    {
        return is_array($this->manifest['files'] ?? null)
            ? $this->nullableInt($this->manifest['files']['count'] ?? null)
            : null;
    }

    public function filesBytes(): int
    {
        return is_array($this->manifest['files'] ?? null)
            ? (int) ($this->manifest['files']['bytes'] ?? 0)
            : 0;
    }

    public function totalBytes(): int
    {
        return $this->bytes;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
