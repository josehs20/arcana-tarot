<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Monta um conjunto de backup completo e aplica a retenção.
 *
 * Cada execução grava um diretório próprio (`arcana-backup-AAAA-MM-DDThhmmssZ`)
 * com `database.sql.gz`, `files.tar.gz` e `manifest.json` — hashes e contagens
 * de tudo o que foi salvo. A escrita acontece em um diretório temporário
 * (`.building-*`) e só é renomeada para o nome final quando tudo terminou:
 * backup pela metade nunca aparece como backup válido, e o conjunto anterior
 * continua intacto se a execução falhar.
 *
 * A retenção roda depois do rename: com `keep = 1` existem, no máximo, dois
 * diretórios por alguns segundos (o novo e o anterior). O comando segura um
 * lock no cache, então duas execuções não disputam os mesmos arquivos.
 */
class BackupManager
{
    public const DIRECTORY_PREFIX = 'arcana-backup-';

    private const TEMPORARY_PREFIX = '.building-';

    /**
     * Diretórios temporários mais antigos que isso são restos de uma execução
     * morta no meio (deploy, OOM, timeout) e podem ser apagados com segurança.
     */
    private const ABANDONED_TEMPORARY_SECONDS = 43_200;

    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly StorageArchiver $archiver,
    ) {}

    /**
     * @param  string  $destination  diretório onde os conjuntos são gravados
     * @param  list<string>  $storagePaths  caminhos relativos à raiz ou absolutos
     */
    public function run(string $destination, int $keep = 1, array $storagePaths = []): BackupResult
    {
        if ($keep < 1) {
            throw new InvalidArgumentException('A retenção do backup precisa ser de pelo menos um conjunto.');
        }

        $startedAt = microtime(true);
        $root = $this->prepareDestination($destination);

        $this->removeAbandonedTemporaryDirectories($root);

        $stamp = now()->utc()->format('Y-m-d\THis\Z');
        $temporary = $root.DIRECTORY_SEPARATOR.self::TEMPORARY_PREFIX.$stamp.'-'.bin2hex(random_bytes(3));
        $final = $root.DIRECTORY_SEPARATOR.$this->uniqueName($root, self::DIRECTORY_PREFIX.$stamp);

        if (! File::makeDirectory($temporary, 0o775, true) && ! is_dir($temporary)) {
            throw new RuntimeException("Não foi possível criar o diretório temporário do backup: {$temporary}.");
        }

        try {
            $database = $this->dumper->dump($temporary.DIRECTORY_SEPARATOR.'database.sql.gz');

            $files = $storagePaths === []
                ? null
                : $this->archiver->archive(
                    $temporary.DIRECTORY_SEPARATOR.'files.tar.gz',
                    $storagePaths,
                    base_path(),
                    $root,
                );

            $this->writeManifest($temporary, $database, $files, $keep, $storagePaths);

            if (! rename($temporary, $final)) {
                throw new RuntimeException("Não foi possível concluir o diretório de backup: {$final}.");
            }
        } catch (Throwable $exception) {
            File::deleteDirectory($temporary);

            throw $exception;
        }

        $pruned = $this->prune($root, $keep, basename($final));

        return new BackupResult(
            directory: $final,
            database: $database,
            files: $files,
            pruned: $pruned,
            skipped: $files?->skipped ?? [],
            seconds: microtime(true) - $startedAt,
        );
    }

    private function prepareDestination(string $destination): string
    {
        $path = str_starts_with($destination, DIRECTORY_SEPARATOR)
            ? rtrim($destination, DIRECTORY_SEPARATOR)
            : base_path(trim($destination, DIRECTORY_SEPARATOR));

        if (! is_dir($path) && ! File::makeDirectory($path, 0o775, true) && ! is_dir($path)) {
            throw new RuntimeException("Não foi possível criar o diretório de backup: {$path}.");
        }

        if (! is_writable($path)) {
            throw new RuntimeException("Diretório de backup sem permissão de escrita: {$path}.");
        }

        return $path;
    }

    /**
     * Duas execuções no mesmo segundo (manual + agendada) não podem disputar o
     * mesmo nome, então o segundo conjunto recebe um sufixo numérico.
     */
    private function uniqueName(string $root, string $name): string
    {
        $candidate = $name;
        $sequence = 1;

        while (file_exists($root.DIRECTORY_SEPARATOR.$candidate)) {
            $candidate = $name.'-'.(++$sequence);
        }

        return $candidate;
    }

    private function removeAbandonedTemporaryDirectories(string $root): void
    {
        $threshold = now()->getTimestamp() - self::ABANDONED_TEMPORARY_SECONDS;

        foreach (File::directories($root) as $directory) {
            if (! str_starts_with(basename($directory), self::TEMPORARY_PREFIX)) {
                continue;
            }

            if (File::lastModified($directory) < $threshold) {
                File::deleteDirectory($directory);
            }
        }
    }

    /**
     * Remove os conjuntos mais antigos mantendo `$keep` — nunca o que acabou de
     * ser gravado. Diretórios com outro nome no destino são do operador e não
     * são tocados.
     *
     * @return list<string>
     */
    private function prune(string $root, int $keep, string $keepName): array
    {
        $pattern = '/^'.preg_quote(self::DIRECTORY_PREFIX, '/').'\d{4}-\d{2}-\d{2}T\d{6}Z(-\d+)?$/';

        $directories = array_values(array_filter(
            File::directories($root),
            static fn (string $directory): bool => preg_match($pattern, basename($directory)) === 1,
        ));

        usort(
            $directories,
            static fn (string $left, string $right): int => strcmp(basename($right), basename($left)),
        );

        $pruned = [];

        foreach (array_slice($directories, $keep) as $directory) {
            if (basename($directory) === $keepName) {
                continue;
            }

            File::deleteDirectory($directory);
            $pruned[] = basename($directory);
        }

        return $pruned;
    }

    /**
     * @param  list<string>  $storagePaths
     */
    private function writeManifest(
        string $directory,
        DatabaseDumpResult $database,
        ?StorageArchiveResult $files,
        int $keep,
        array $storagePaths,
    ): void {
        $manifest = [
            'generated_at' => now()->utc()->toIso8601String(),
            'application' => [
                'name' => (string) config('app.name'),
                'environment' => app()->environment(),
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
            ],
            'database' => [
                'driver' => $database->driver,
                'name' => $database->database,
                'file' => basename($database->path),
                'tables' => $database->tables,
                'rows' => $database->rows,
                'bytes' => $database->bytes,
                'sha256' => $database->sha256,
            ],
            'files' => $files === null ? null : [
                'file' => basename($files->path),
                'roots' => $files->roots,
                'count' => $files->files,
                'bytes' => $files->bytes,
                'sha256' => $files->sha256,
                'skipped' => $files->skipped,
            ],
            'storage_paths' => $storagePaths,
            'retention' => $keep,
            'restore' => [
                'database' => 'gunzip -c database.sql.gz | mysql -u <usuario> -p <banco>',
                'files' => 'tar -xzf files.tar.gz -C <diretorio-de-restauracao>',
            ],
        ];

        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            throw new RuntimeException('Não foi possível serializar o manifesto do backup.');
        }

        File::put($directory.DIRECTORY_SEPARATOR.'manifest.json', $encoded.PHP_EOL);
    }
}
