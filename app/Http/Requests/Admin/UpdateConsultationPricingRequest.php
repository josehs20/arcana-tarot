<?php

namespace App\Http\Requests\Admin;

use App\Models\Wallet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Parametros globais do credito.
 *
 * O preco por minuto deixou de ser global: cada atendente define o valor da
 * sua modalidade em `attendant_modality_prices` (tela Modalidades dos
 * atendentes). Aqui ficam apenas o valor do credito e o saldo minimo exigido
 * no aceite.
 */
class UpdateConsultationPricingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Wallet::class)
            && $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'credit_unit_price_cents' => ['required', 'integer', 'min:1', 'max:1000000'],
            'minimum_balance_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'credit_unit_price_cents.required' => 'Informe o valor do crédito em centavos.',
            'credit_unit_price_cents.integer' => 'O valor do crédito deve ser um número inteiro de centavos.',
            'credit_unit_price_cents.min' => 'O valor do crédito deve ser maior que zero.',
            'credit_unit_price_cents.max' => 'O valor do crédito excede o limite permitido.',
            'minimum_balance_minutes.required' => 'Informe o saldo mínimo em minutos.',
            'minimum_balance_minutes.integer' => 'O saldo mínimo deve ser um número inteiro de minutos.',
            'minimum_balance_minutes.min' => 'O saldo mínimo deve ser de pelo menos 1 minuto.',
            'minimum_balance_minutes.max' => 'O saldo mínimo não pode exceder 1.440 minutos (24 horas).',
        ];
    }
}
