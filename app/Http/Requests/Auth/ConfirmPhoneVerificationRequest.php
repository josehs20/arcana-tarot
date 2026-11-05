<?php

namespace App\Http\Requests\Auth;

use App\Support\AuthenticationSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Código de confirmação do celular.
 *
 * O campo aceita o código colado com espaços/hífens: apenas os dígitos são
 * considerados. O tamanho acompanha SMS_VERIFICATION_CODE_LENGTH.
 */
class ConfirmPhoneVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');

        $this->merge([
            'code' => is_scalar($code) ? (preg_replace('/\D+/', '', (string) $code) ?? '') : $code,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:'.AuthenticationSettings::smsCodeLength()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $length = AuthenticationSettings::smsCodeLength();

        return [
            'code.required' => 'Informe o código recebido por SMS.',
            'code.string' => 'Informe o código recebido por SMS.',
            'code.digits' => 'O código deve ter '.$length.' dígitos.',
        ];
    }
}
