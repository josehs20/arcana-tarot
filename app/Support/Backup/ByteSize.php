<?php

namespace App\Support\Backup;

/**
 * Formatação de tamanhos em bytes usada nos relatórios de backup.
 *
 * Não usa `Number::fileSize()` porque a extensão `intl` não está disponível na
 * imagem de produção e na de testes; a saída segue o mesmo estilo (`1.5 MB`).
 */
final class ByteSize
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    public static function format(int $bytes, int $precision = 1): string
    {
        $value = (float) max($bytes, 0);
        $unit = 0;
        $lastUnit = count(self::UNITS) - 1;

        while ($value >= 1024 && $unit < $lastUnit) {
            $value /= 1024;
            $unit++;
        }

        if ($unit === 0) {
            return sprintf('%d %s', (int) $value, self::UNITS[$unit]);
        }

        return sprintf('%.'.$precision.'f %s', $value, self::UNITS[$unit]);
    }
}
