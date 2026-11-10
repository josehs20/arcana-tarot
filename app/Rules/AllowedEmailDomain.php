<?php

namespace App\Rules;

use App\Support\AllowedEmailDomains;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Restringe o cadastro aos provedores de e-mail aceitos.
 *
 * A regra complementa `email` e `unique`: endereços fora da lista
 * (`gmail.com`, `hotmail.com`, `outlook.com`, `ig.com.br`) são recusados com a
 * mesma mensagem exibida no texto de ajuda do formulário.
 */
class AllowedEmailDomain implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || AllowedEmailDomains::isAllowed($value)) {
            return;
        }

        $fail(AllowedEmailDomains::message());
    }
}
