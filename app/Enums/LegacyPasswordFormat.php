<?php

namespace App\Enums;

/**
 * Formato encontrado na coluna de senha do banco legado.
 *
 * Apenas hashes bcrypt/argon2 são verificáveis pelo `Hash` do Laravel sem
 * intervenção. Digestos legados (md5/sha1/sha256/sha512) e valores em texto
 * puro nunca são gravados como senha: a conta é criada com uma senha
 * inutilizável e o titular redefine o acesso pela recuperação de senha.
 */
enum LegacyPasswordFormat: string
{
    case Bcrypt = 'bcrypt';
    case Argon2 = 'argon2';
    case LegacyDigest = 'legacy_digest';
    case Plaintext = 'plaintext';
    case Missing = 'missing';

    /**
     * Classifica o valor bruto da coluna de senha.
     *
     * A classificação é estrutural (prefixo/tamanho) e não prova que o hash
     * seja verificável pela instalação atual; essa confirmação é feita pelo
     * importador com `Hash::info()` antes de preservar o valor.
     */
    public static function detect(mixed $value): self
    {
        if (! is_scalar($value)) {
            return self::Missing;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return self::Missing;
        }

        if (preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $value) === 1) {
            return self::Bcrypt;
        }

        if (preg_match('/^\$argon2(?:id|i|d)\$/', $value) === 1) {
            return self::Argon2;
        }

        if (preg_match('/^[a-f0-9]{32}$|^[a-f0-9]{40}$|^[a-f0-9]{64}$|^[a-f0-9]{128}$/i', $value) === 1) {
            return self::LegacyDigest;
        }

        return self::Plaintext;
    }

    /**
     * Indica se o formato pode ser verificado pelo `Hash` do Laravel.
     */
    public function isLaravelCompatible(): bool
    {
        return $this === self::Bcrypt || $this === self::Argon2;
    }

    public function requiresPasswordReset(): bool
    {
        return ! $this->isLaravelCompatible();
    }

    public function label(): string
    {
        return match ($this) {
            self::Bcrypt => 'bcrypt (compatível)',
            self::Argon2 => 'argon2 (compatível)',
            self::LegacyDigest => 'digesto legado (redefinição de senha)',
            self::Plaintext => 'texto puro (redefinição de senha)',
            self::Missing => 'sem senha',
        };
    }
}
