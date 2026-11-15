<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Credencial do SDK oficial do Google.
 *
 * O app envia somente o ID token; e-mail, nome e demais claims são lidos do
 * token assinado e nunca do corpo da requisição.
 */
class GoogleLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id_token' => ['required', 'string', 'max:4096'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id_token.required' => 'Envie o ID token do Google.',
            'id_token.string' => 'O ID token do Google é inválido.',
        ];
    }

    public function idToken(): string
    {
        return (string) $this->input('id_token');
    }

    public function deviceName(): ?string
    {
        $name = $this->input('device_name');

        return is_string($name) ? $name : null;
    }
}
