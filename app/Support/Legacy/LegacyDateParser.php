<?php

namespace App\Support\Legacy;

use DateTimeImmutable;

/**
 * Normaliza datas do banco legado para `Y-m-d`.
 *
 * O legado mistura `date`, `datetime` e `varchar` com formatos brasileiros e
 * costuma trazer `0000-00-00` para "sem data". Só datas reais de nascimento
 * passam: qualquer valor ambíguo, zerado, futuro ou fora da faixa plausível
 * vira `null`, nunca um palpite.
 */
final class LegacyDateParser
{
    /** Primeiro ano aceito para uma data de nascimento. */
    private const MIN_YEAR = 1900;

    /** @var array<int, string> */
    private const FORMATS = [
        '!Y-m-d',
        '!Y-m-d H:i:s',
        '!d/m/Y',
        '!d/m/Y H:i:s',
        '!d-m-Y',
        '!d.m.Y',
        '!Y/m/d',
    ];

    /** @var array<int, string> */
    private const EMPTY_VALUES = ['', '0', 'null', '0000-00-00', '0000-00-00 00:00:00', '00/00/0000', '00000000'];

    public static function parse(?string $value): ?string
    {
        $raw = trim((string) $value);

        if (in_array($raw, self::EMPTY_VALUES, true)) {
            return null;
        }

        foreach (self::FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $raw);

            if ($date === false || $date->format(substr($format, 1)) !== $raw) {
                continue;
            }

            return self::isPlausible($date) ? $date->format('Y-m-d') : null;
        }

        return null;
    }

    private static function isPlausible(DateTimeImmutable $date): bool
    {
        $year = (int) $date->format('Y');

        if ($year < self::MIN_YEAR) {
            return false;
        }

        return $date <= new DateTimeImmutable('today');
    }
}
