<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Auth\RegisterRequest as WebRegisterRequest;

/**
 * Cadastro da API: reutiliza integralmente as regras do cadastro web
 * (domínios permitidos, telefone, idade mínima, gênero e confirmação de senha)
 * e acrescenta apenas o nome do dispositivo usado no token.
 */
class RegisterRequest extends WebRegisterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
