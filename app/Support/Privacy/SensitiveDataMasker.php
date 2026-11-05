<?php

namespace App\Support\Privacy;

use App\Enums\SensitiveDataCategory;
use App\Support\PhoneNumber;

/**
 * Mascaramento dos valores identificados.
 *
 * O painel administrativo nunca recebe o dado completo: cada ocorrência
 * guarda apenas o trecho com os valores mascarados. O texto original fica
 * cifrado e só é revelado com registro de auditoria.
 */
final class SensitiveDataMasker
{
    public static function mask(SensitiveDataCategory $category, string $raw): string
    {
        $digits = BrazilianDocument::onlyDigits($raw);

        return match ($category) {
            SensitiveDataCategory::Cpf => self::cpf($digits),
            SensitiveDataCategory::Cnpj => self::cnpj($digits),
            SensitiveDataCategory::Phone, SensitiveDataCategory::WhatsApp => self::phone($digits),
            SensitiveDataCategory::Email => self::email($raw),
            SensitiveDataCategory::ExternalLink => self::link($raw),
            SensitiveDataCategory::HiddenContact => self::hidden($digits),
        };
    }

    private static function cpf(string $digits): string
    {
        if (strlen($digits) !== 11) {
            return self::hidden($digits);
        }

        return substr($digits, 0, 3).'.***.***-'.substr($digits, -2);
    }

    private static function cnpj(string $digits): string
    {
        if (strlen($digits) !== 14) {
            return self::hidden($digits);
        }

        return substr($digits, 0, 2).'.***.***/****-'.substr($digits, -2);
    }

    private static function phone(string $digits): string
    {
        $normalized = PhoneNumber::normalize($digits);

        if (strlen($normalized) === 11) {
            return sprintf('(%s) *****-%s', substr($normalized, 0, 2), substr($normalized, -4));
        }

        if (strlen($normalized) === 10) {
            return sprintf('(%s) ****-%s', substr($normalized, 0, 2), substr($normalized, -4));
        }

        return self::hidden($normalized);
    }

    private static function email(string $raw): string
    {
        $parts = explode('@', $raw, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return '***@***';
        }

        $domainLabels = explode('.', $parts[1]);
        $lastLabel = end($domainLabels) ?: '***';
        $firstLabel = $domainLabels[0] !== '' ? $domainLabels[0] : '***';

        return mb_substr($parts[0], 0, 1).'***@'.mb_substr($firstLabel, 0, 1).'***.'.$lastLabel;
    }

    private static function link(string $raw): string
    {
        $host = parse_url(str_starts_with($raw, 'http') ? $raw : 'https://'.$raw, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return '[link removido]';
        }

        $labels = explode('.', $host);

        if (count($labels) < 2) {
            return '[link removido]';
        }

        $labels[0] = mb_substr($labels[0], 0, 1).'***';

        return implode('.', $labels).'/***';
    }

    private static function hidden(string $digits): string
    {
        if ($digits === '') {
            return '***';
        }

        return str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
    }
}
