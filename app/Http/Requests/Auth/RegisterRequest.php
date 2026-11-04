<?php

namespace App\Http\Requests\Auth;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza nome, e-mail e telefone antes da validação, para que a
     * persistência use sempre o mesmo formato.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $email = $this->input('email');

        $this->merge([
            'name' => is_scalar($name) ? Str::squish((string) $name) : $name,
            'email' => is_scalar($email) ? Str::lower(trim((string) $email)) : $email,
            'phone' => PhoneNumber::normalize($this->input('phone')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => [
                'required',
                'string',
                'digits_between:10,11',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! PhoneNumber::isValid((string) $value)) {
                        $fail('Informe um telefone válido com DDD.');
                    }
                },
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe seu nome.',
            'name.min' => 'Informe seu nome completo.',
            'email.required' => 'Informe um e-mail.',
            'email.email' => 'Informe um e-mail válido.',
            'email.unique' => 'Já existe uma conta com este e-mail.',
            'phone.required' => 'Informe um celular ou telefone com DDD.',
            'phone.digits_between' => 'Informe um telefone com DDD (10 ou 11 dígitos).',
            'password.required' => 'Informe uma senha.',
            'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
            'password.confirmed' => 'A confirmação da senha não confere.',
        ];
    }
}
