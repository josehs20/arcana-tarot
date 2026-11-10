<?php

namespace App\Support\Legacy;

use App\Enums\Gender;

/**
 * Dados complementares de um cliente legado, vindos de uma tabela ligada ao
 * usuário (no banco real, `eam_clientes`).
 *
 * Os valores já chegam normalizados: telefone sem máscara, data em `Y-m-d` e
 * sexo convertido para `App\Enums\Gender`. O que não pôde ser interpretado
 * fica `null`.
 */
final class LegacyProfileRecord
{
    public function __construct(
        public readonly ?string $phone = null,
        public readonly ?string $birthDate = null,
        public readonly ?Gender $gender = null,
    ) {}
}
