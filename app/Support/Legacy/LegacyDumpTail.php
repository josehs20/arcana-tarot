<?php

namespace App\Support\Legacy;

/**
 * Inspeção do fim do arquivo legado.
 *
 * Exportações interrompidas (phpMyAdmin/mysqldump) terminam com uma página de
 * erro no lugar dos dados. O trecho final é lido sem carregar o arquivo
 * inteiro, porque o dump real tem centenas de megabytes.
 */
final class LegacyDumpTail
{
    /**
     * Mensagens que aparecem no arquivo quando a exportação foi cortada.
     */
    private const TRUNCATION_MARKERS = [
        'MySQL server has gone away',
        'mysqli_sql_exception',
        'Fatal error</b>',
        '<div class="alert alert-danger"',
    ];

    public static function size(string $path): int
    {
        clearstatcache(true, $path);

        $size = @filesize($path);

        return $size === false ? 0 : $size;
    }

    /**
     * Últimos bytes do arquivo, ou string vazia quando ele não pode ser lido.
     */
    public static function read(string $path, int $bytes): string
    {
        $size = self::size($path);

        if ($size === 0) {
            return '';
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new LegacyDumpException('Não foi possível abrir o arquivo legado para leitura.');
        }

        $length = min($size, $bytes);
        fseek($handle, $size - $length);
        $tail = (string) fread($handle, $length);
        fclose($handle);

        return $tail;
    }

    /**
     * Marcador de exportação interrompida presente no fim do arquivo.
     */
    public static function truncationMarker(string $path): ?string
    {
        $tail = self::read($path, 65_536);

        foreach (self::TRUNCATION_MARKERS as $marker) {
            if (str_contains($tail, $marker)) {
                return $marker;
            }
        }

        return null;
    }
}
