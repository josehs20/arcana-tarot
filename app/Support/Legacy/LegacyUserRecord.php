<?php

namespace App\Support\Legacy;

use App\Enums\LegacyPasswordFormat;

/**
 * Registro de usuário já normalizado a partir de uma linha do dump legado.
 *
 * `passwordValue` guarda o valor bruto da coluna de senha e nunca deve ser
 * exibido, registrado em log ou gravado como senha quando o formato não for
 * verificável pelo Laravel.
 */
final class LegacyUserRecord
{
    public function __construct(
        public readonly string $legacyKey,
        public readonly string $email,
        public readonly string $name,
        public readonly string $passwordValue,
        public readonly LegacyPasswordFormat $passwordFormat,
        public readonly ?string $phone = null,
        public readonly int $balanceCents = 0,
    ) {}
}
