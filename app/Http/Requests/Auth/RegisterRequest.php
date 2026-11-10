<?php

namespace App\Http\Requests\Auth;

use App\Enums\Gender;
use App\Rules\AllowedEmailDomain;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    /**
     * Idade mínima exigida no cadastro (termos de uso) e limite de sanidade
     * para barrar datas digitadas por engano.
     */
    private const MINIMUM_AGE = 18;

    private const MAXIMUM_AGE = 120;

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
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                new AllowedEmailDomain,
                'unique:users,email',
            ],
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
            'birth_date' => [
                'required',
                'date_format:Y-m-d',
                'before:'.now()->subYears(self::MINIMUM_AGE)->toDateString(),
                'after:'.now()->subYears(self::MAXIMUM_AGE)->toDateString(),
            ],
            'gender' => ['required', 'string', Rule::enum(Gender::class)],
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
            'birth_date.required' => 'Informe sua data de nascimento.',
            'birth_date.date_format' => 'Informe a data de nascimento no formato DD/MM/AAAA.',
            'birth_date.before' => 'É preciso ter 18 anos ou mais para se cadastrar.',
            'birth_date.after' => 'Confira a data de nascimento informada.',
            'gender.required' => 'Informe o sexo.',
            'gender.string' => 'Informe o sexo.',
            'gender.enum' => 'Escolha uma opção válida de sexo.',
            'password.required' => 'Informe uma senha.',
            'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
            'password.confirmed' => 'A confirmação da senha não confere.',
        ];
    }
}
