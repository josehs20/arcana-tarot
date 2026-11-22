<?php

namespace App\Support\Backup;

use FilesystemIterator;
use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Empacota diretórios do storage em `files.tar.gz`.
 *
 * Usa `PharData` (extensão `phar`, sempre presente) e gzip (`zlib`, compilado
 * por padrão): a imagem de produção não traz `ext-zip`, e o tar preserva
 * caminhos longos das imagens de carta sem o limite de nome do ZIP.
 *
 * A leitura é feita arquivo a arquivo, sem carregar o conteúdo em memória.
 * Entradas sem permissão de leitura são registradas em `skipped` em vez de
 * abortar o backup inteiro: um arquivo privado ilegível não pode impedir o
 * dump do banco de ser guardado. Falhas de E/S na escrita, sim, abortam.
 */
class StorageArchiver
{
    /**
     * @param  list<string>  $paths  caminhos relativos à raiz ou absolutos
     * @param  string|null  $excludedPath  diretório ignorado na varredura (o próprio destino do backup)
     */
    public function archive(
        string $destinationPath,
        array $paths,
        string $rootPath,
        ?string $excludedPath = null,
    ): StorageArchiveResult {
        $tarPath = preg_replace('/\.gz$/', '', $destinationPath) ?: $destinationPath;

        foreach ([$tarPath, $destinationPath] as $candidate) {
            $this->removeArchive($candidate);
        }

        if (file_exists($tarPath) || file_exists($destinationPath)) {
            throw new RuntimeException("Arquivo de storage já existente: {$destinationPath}.");
        }

        $excluded = $excludedPath === null ? null : $this->normalize(rtrim($excludedPath, DIRECTORY_SEPARATOR));

        $tar = new PharData($tarPath, 0, null, Phar::TAR);

        $files = 0;
        $roots = [];
        $skipped = [];

        try {
            foreach ($paths as $path) {
                $absolute = $this->normalize($this->absolutePath($path, $rootPath));

                if ($excluded !== null && ($absolute === $excluded || str_starts_with($absolute, $excluded.'/'))) {
                    continue;
                }

                $localRoot = $this->relativeName($absolute, $rootPath);

                if (is_file($absolute)) {
                    if ($this->addFile($tar, $absolute, $localRoot, $files)) {
                        $roots[] = $localRoot;
                    } else {
                        $skipped[] = $localRoot;
                    }

                    continue;
                }

                if (! is_dir($absolute)) {
                    $skipped[] = $localRoot;

                    continue;
                }

                $roots[] = $localRoot;

                $this->addDirectory($tar, $absolute, $localRoot, $excluded, $files, $skipped);
            }
        } catch (Throwable $exception) {
            unset($tar);
            $this->removeArchive($tarPath);

            throw $exception;
        }

        unset($tar);

        if ($files === 0) {
            $this->removeArchive($tarPath);

            return new StorageArchiveResult('', $roots, 0, 0, '', $skipped);
        }

        $compressed = new PharData($tarPath);
        $compressed->compress(Phar::GZ, 'tar.gz');
        unset($compressed);

        $this->removeArchive($tarPath);

        if (! is_file($destinationPath)) {
            throw new RuntimeException("Falha ao comprimir o arquivo de storage: {$destinationPath}.");
        }

        $sha256 = hash_file('sha256', $destinationPath);

        return new StorageArchiveResult(
            path: $destinationPath,
            roots: $roots,
            files: $files,
            bytes: (int) filesize($destinationPath),
            sha256: is_string($sha256) ? $sha256 : '',
            skipped: $skipped,
        );
    }

    /**
     * Descarta um arquivo phar/tar do disco e do registro interno do PHP.
     * `Phar::unlinkArchive()` mantém o arquivo em memória (a próxima conversão
     * falharia dizendo que ele já existe), então o `unlink` é o plano B.
     */
    private function removeArchive(string $path): void
    {
        if (! is_file($path)) {
            return;
        }

        try {
            Phar::unlinkArchive($path);
        } catch (Throwable) {
            // Arquivo fora do registro do Phar (sobra de execução anterior).
        }

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Percorre um diretório e adiciona diretórios vazios e arquivos ao tar.
     *
     * @param  list<string>  $skipped
     */
    private function addDirectory(
        PharData $tar,
        string $absolute,
        string $localRoot,
        ?string $excluded,
        int &$files,
        array &$skipped,
    ): void {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $item) {
                $local = $localRoot.'/'.str_replace('\\', '/', $iterator->getSubPathname());

                if ($excluded !== null && str_starts_with($this->normalize($item->getPathname()), $excluded.'/')) {
                    continue;
                }

                if ($item->isDir()) {
                    try {
                        $tar->addEmptyDir($local);
                    } catch (Throwable) {
                        /*
                         * O diretório já existe no tar porque um arquivo dele foi
                         * gravado antes; não é erro.
                         */
                    }

                    continue;
                }

                if (! $this->addFile($tar, $item->getPathname(), $local, $files)) {
                    $skipped[] = $local;
                }
            }
        } catch (UnexpectedValueException) {
            $skipped[] = $localRoot;
        }
    }

    private function addFile(PharData $tar, string $absolute, string $local, int &$files): bool
    {
        if (! is_readable($absolute)) {
            return false;
        }

        try {
            $tar->addFile($absolute, $local);
        } catch (Throwable) {
            return false;
        }

        $files++;

        return true;
    }

    private function absolutePath(string $path, string $rootPath): string
    {
        $trimmed = trim($path);

        if ($trimmed === '') {
            throw new RuntimeException('Caminho vazio na configuração de backup (backup.storage_paths).');
        }

        $isAbsolute = str_starts_with($trimmed, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $trimmed) === 1;

        return $isAbsolute
            ? $trimmed
            : rtrim($rootPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$trimmed;
    }

    /**
     * Nome da entrada no tar: caminho relativo à raiz do projeto. Caminhos fora
     * da raiz (backup de diretório avulso) entram pelo nome base.
     */
    private function relativeName(string $absolute, string $rootPath): string
    {
        $root = $this->normalize($rootPath);

        if ($absolute !== $root && str_starts_with($absolute, $root.'/')) {
            return substr($absolute, strlen($root) + 1);
        }

        return basename($absolute);
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
