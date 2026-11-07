<?php

namespace App\Support\Legacy;

/**
 * Escolhe o leitor adequado à exportação legada.
 *
 * O formato é identificado pela extensão — `.csv` é exportação de registros do
 * phpMyAdmin, qualquer outro arquivo é tratado como dump SQL — e a quantidade de
 * arquivos define a estratégia: um arquivo é lido direto, vários (exportação de
 * uma tabela por arquivo) são agregados por `LegacyDumpBundle`. Um diretório é
 * expandido para as exportações `.sql`/`.csv` que ele contém, em ordem
 * alfabética. Assim o comando `arcana:import-legacy-users` aceita todas as
 * formas de exportação sem opções extras.
 */
final class LegacyDumpFactory
{
    private const CSV_EXTENSIONS = ['csv'];

    /**
     * Extensões aceitas quando um diretório é informado.
     */
    private const SUPPORTED_EXTENSIONS = ['csv', 'sql'];

    /**
     * @param  string|array<int, string>  $path  arquivo, diretório ou lista de caminhos
     */
    public static function open(string|array $path): LegacyDumpSource
    {
        $files = self::expand(is_array($path) ? $path : [$path]);

        if ($files === []) {
            throw new LegacyDumpException('Nenhum arquivo legado encontrado; informe um arquivo .sql/.csv ou um diretório com a exportação.');
        }

        if (count($files) === 1) {
            return self::reader($files[0]);
        }

        return new LegacyDumpBundle(...array_map(self::reader(...), $files));
    }

    private static function reader(string $path): LegacyDumpSource
    {
        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::CSV_EXTENSIONS, true)
            ? new LegacyCsvDumpReader($path)
            : new LegacySqlDumpReader($path);
    }

    /**
     * Expande diretórios em arquivos de exportação e mantém os caminhos
     * informados como estão (a validação fica com o leitor).
     *
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private static function expand(array $paths): array
    {
        $files = [];

        foreach ($paths as $raw) {
            $path = trim((string) $raw);

            if ($path === '') {
                continue;
            }

            if (is_dir($path)) {
                foreach (self::filesIn($path) as $file) {
                    $files[] = $file;
                }

                continue;
            }

            $files[] = $path;
        }

        return array_values(array_unique($files));
    }

    /**
     * Exportações encontradas no diretório, em ordem alfabética.
     *
     * @return array<int, string>
     */
    private static function filesIn(string $directory): array
    {
        $entries = @scandir($directory);

        if ($entries === false) {
            throw new LegacyDumpException("Não foi possível ler o diretório da exportação: {$directory}");
        }

        $files = [];

        foreach ($entries as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }

            $path = rtrim($directory, DIRECTORY_SEPARATOR.'/').DIRECTORY_SEPARATOR.$entry;

            if (! is_file($path)) {
                continue;
            }

            if (! in_array(mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::SUPPORTED_EXTENSIONS, true)) {
                continue;
            }

            $files[] = $path;
        }

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        return $files;
    }
}
