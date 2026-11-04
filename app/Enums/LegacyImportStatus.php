<?php

namespace App\Enums;

/**
 * Resultado da análise de um registro do banco legado.
 */
enum LegacyImportStatus: string
{
    case Planned = 'planned';
    case Imported = 'imported';
    case Skipped = 'skipped';
    case Conflict = 'conflict';
    case Failed = 'failed';

    public function label(bool $executed): string
    {
        return match ($this) {
            self::Planned => 'seria importado',
            self::Imported => 'importado',
            self::Skipped => 'ignorado',
            self::Conflict => $executed ? 'conflito' : 'conflito (previsto)',
            self::Failed => 'falha',
        };
    }

    public function isSuccess(): bool
    {
        return $this === self::Imported || $this === self::Planned;
    }
}
