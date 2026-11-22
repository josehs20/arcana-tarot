<?php

namespace App\Support\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Leitura dos conjuntos de backup gravados no destino.
 *
 * O painel mostra o que está no disco, não o que a última execução disse ter
 * gravado: se um conjunto foi copiado, corrompido ou apagado à mão, é o disco
 * que manda. Só diretórios com o mesmo padrão usado pela retenção
 * (`arcana-backup-AAAA-MM-DDThhmmssZ`) entram na lista — o que o operador
 * colocar no destino continua invisível para a aplicação.
 */
final class BackupCatalog
{
    /**
     * Diretório dos backups de segurança gravados antes de uma importação.
     *
     * Fica separado do destino principal para não competir com a retenção de um
     * conjunto: o backup automático das seis horas continua sendo substituído
     * no ritmo dele, enquanto o "antes da importação" tem a própria retenção.
     */
    public const SAFETY_SUBDIRECTORY = 'pre-import';

    /**
     * @return list<BackupSet>
     */
    public function sets(string $destination): array
    {
        $root = $this->resolve($destination);

        if (! is_dir($root)) {
            return [];
        }

        $pattern = '/^'.preg_quote(BackupManager::DIRECTORY_PREFIX, '/').'\d{4}-\d{2}-\d{2}T\d{6}Z(-\d+)?$/';

        $sets = [];

        foreach (File::directories($root) as $directory) {
            if (preg_match($pattern, basename($directory)) !== 1) {
                continue;
            }

            $sets[] = $this->read($directory);
        }

        usort(
            $sets,
            static fn (BackupSet $left, BackupSet $right): int => strcmp($right->name, $left->name),
        );

        return $sets;
    }

    public function latest(string $destination): ?BackupSet
    {
        return $this->sets($destination)[0] ?? null;
    }

    /**
     * Converte o valor de configuração em caminho absoluto: relativo é
     * resolvido a partir da raiz do projeto, como no `arcana:backup`.
     */
    public function resolve(string $destination): string
    {
        $destination = trim($destination);

        if ($destination === '') {
            return storage_path('backups');
        }

        return str_starts_with($destination, DIRECTORY_SEPARATOR)
            ? rtrim($destination, DIRECTORY_SEPARATOR)
            : base_path(trim($destination, DIRECTORY_SEPARATOR));
    }

    private function read(string $directory): BackupSet
    {
        $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
        $databasePath = $directory.DIRECTORY_SEPARATOR.'database.sql.gz';
        $filesPath = $directory.DIRECTORY_SEPARATOR.'files.tar.gz';

        $bytes = 0;

        foreach ([$manifestPath, $databasePath, $filesPath] as $path) {
            if (is_file($path)) {
                $bytes += (int) filesize($path);
            }
        }

        $manifest = $this->manifest($manifestPath);

        return new BackupSet(
            name: basename($directory),
            path: $directory,
            createdAt: $this->createdAt($manifest, $directory),
            manifest: $manifest,
            hasDatabase: is_file($databasePath),
            hasFiles: is_file($filesPath),
            bytes: $bytes,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function manifest(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Data do backup: a do manifesto e, se ele estiver ausente, a do nome do
     * diretório. O horário do diretório no sistema de arquivos é o último
     * recurso (o rename preserva o `mtime` da gravação).
     *
     * @param  array<string, mixed>|null  $manifest
     */
    private function createdAt(?array $manifest, string $directory): ?Carbon
    {
        $generatedAt = $manifest['generated_at'] ?? null;

        if (is_string($generatedAt) && $generatedAt !== '') {
            try {
                return Carbon::parse($generatedAt);
            } catch (Throwable) {
                // Manifesto editado à mão: cai para o nome do diretório.
            }
        }

        $name = basename($directory);

        if (preg_match('/^'.preg_quote(BackupManager::DIRECTORY_PREFIX, '/').'(\d{4}-\d{2}-\d{2}T\d{6})Z/', $name, $matches) === 1) {
            $parsed = Carbon::createFromFormat('Y-m-d\THis', $matches[1], 'UTC');

            if ($parsed instanceof Carbon) {
                return $parsed;
            }
        }

        $modified = filemtime($directory);

        return $modified === false ? null : Carbon::createFromTimestamp($modified);
    }
}
