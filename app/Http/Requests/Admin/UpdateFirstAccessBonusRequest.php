<?php

namespace App\Http\Requests\Admin;

use App\Models\Wallet;
use App\Support\ConsultationPricing;
use App\Support\FirstAccessBonus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Configuração do bônus de primeiro acesso.
 *
 * O formulário envia a chave `enabled` (checkbox) e a quantidade de créditos.
 * Ativar o bônus exige ao menos 1 crédito e uma tarifa de crédito definida —
 * sem tarifa não há como converter o benefício em centavos no livro-razão.
 */
class UpdateFirstAccessBonusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Wallet::class)
            && $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'enabled' => $this->boolean('enabled'),
            'credits' => (int) $this->input('credits', 0),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'credits' => ['required', 'integer', 'min:0', 'max:'.FirstAccessBonus::MAX_CREDITS],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'enabled.required' => 'Informe se o bônus está ativo.',
            'enabled.boolean' => 'O status do bônus é inválido.',
            'credits.required' => 'Informe a quantidade de créditos do bônus.',
            'credits.integer' => 'A quantidade de créditos deve ser um número inteiro.',
            'credits.min' => 'A quantidade de créditos não pode ser negativa.',
            'credits.max' => 'A quantidade de créditos excede o limite de '.FirstAccessBonus::MAX_CREDITS.'.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $this->boolean('enabled')) {
                return;
            }

            if ((int) $this->input('credits') < 1) {
                $validator->errors()->add('credits', 'Para ativar o bônus, informe ao menos 1 crédito.');
            }

            if (app(ConsultationPricing::class)->creditUnitPriceCents() === null) {
                $validator->errors()->add(
                    'enabled',
                    'Defina o valor do crédito no Financeiro antes de ativar o bônus de primeiro acesso.',
                );
            }
        });
    }
}
