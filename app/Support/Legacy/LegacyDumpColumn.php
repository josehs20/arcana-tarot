<?php

namespace App\Support\Legacy;

/**
 * Coluna declarada em um `CREATE TABLE` do dump legado.
 */
final class LegacyDumpColumn
{
    /** @var array<int, string> */
    private const NUMERIC_TYPES = [
        'bit',
        'tinyint',
        'smallint',
        'mediumint',
        'int',
        'integer',
        'bigint',
        'decimal',
        'numeric',
        'double',
        'float',
        'real',
        'year',
    ];

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable,
    ) {}

    public function isNumeric(): bool
    {
        return in_array($this->type, self::NUMERIC_TYPES, true);
    }

    public function label(): string
    {
        return $this->name.' ('.$this->type.($this->nullable ? ', nulo' : '').')';
    }
}
