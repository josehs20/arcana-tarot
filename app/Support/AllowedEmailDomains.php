<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Domínios de e-mail aceitos no cadastro por e-mail e senha.
 *
 * A lista vem de `authentication.registration.allowed_email_domains` e é usada
 * em três pontos: validação (App\Rules\AllowedEmailDomain), mensagem de erro e
 * texto de ajuda do formulário. A comparação considera apenas o domínio, em
 * minúsculas e por igualdade exata — `gmail.com.br` não é `gmail.com` e
 * subdomínios não são aceitos. Contas criadas pelo Google não passam por esta
 * regra, porque o endereço já foi verificado pelo próprio provedor.
 */
final class AllowedEmailDomains
{
    /**
     * Domínios configurados, sem repetição e na ordem da configuração.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $configured = config('authentication.registration.allowed_email_domains', []);

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (! is_array($configured)) {
            return [];
        }

        $domains = [];

        foreach ($configured as $domain) {
            $normalized = is_scalar($domain) ? self::normalize((string) $domain) : '';

            if ($normalized !== '') {
                $domains[$normalized] = true;
            }
        }

        return array_keys($domains);
    }

    /**
     * Indica se o e-mail pertence a um dos domínios aceitos.
     */
    public static function isAllowed(?string $email): bool
    {
        $domain = self::domainOf($email);

        return $domain !== null && in_array($domain, self::all(), true);
    }

    /**
     * Domínio do e-mail em minúsculas, ou null quando o endereço não tem "@".
     */
    public static function domainOf(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        $domain = self::normalize(Str::afterLast($email, '@'));

        return $domain === '' ? null : $domain;
    }

    /**
     * Lista pronta para exibição: "@gmail.com, @hotmail.com ou @outlook.com".
     */
    public static function labels(): string
    {
        $domains = array_map(static fn (string $domain): string => '@'.$domain, self::all());

        if ($domains === []) {
            return '';
        }

        $last = (string) array_pop($domains);

        return $domains === [] ? $last : implode(', ', $domains).' ou '.$last;
    }

    /**
     * Mensagem usada na validação e no texto de ajuda do cadastro.
     */
    public static function message(): string
    {
        $labels = self::labels();

        return $labels === ''
            ? 'Informe um e-mail válido.'
            : 'Aceitamos apenas e-mails '.$labels.'.';
    }

    private static function normalize(string $domain): string
    {
        return rtrim(Str::lower(trim($domain)), '.');
    }
}
