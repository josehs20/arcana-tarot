<?php

namespace App\Http\Requests\Admin;

use App\Models\Wallet;
use App\Support\ConsultationPricing;
use App\Support\Money;
use App\Support\PaymentLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Faixa de valores aceita nas recargas de carteira.
 *
 * O formulário trabalha em reais e o domínio em centavos. O mínimo precisa
 * cobrir pelo menos um crédito (senão nenhum pacote do checkout seria vendável)
 * e o máximo não pode ser menor que o mínimo.
 */
class UpdatePaymentLimitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Wallet::class)
            && $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'min_amount_cents' => Money::parseDecimalToCents((string) $this->input('min_amount_reais')),
            'max_amount_cents' => Money::parseDecimalToCents((string) $this->input('max_amount_reais')),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'min_amount_cents' => ['required', 'integer', 'min:1', 'max:'.PaymentLimits::MAX_LIMIT_CENTS],
            'max_amount_cents' => ['required', 'integer', 'min:1', 'max:'.PaymentLimits::MAX_LIMIT_CENTS],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'min_amount_cents.required' => 'Informe o valor mínimo da recarga em reais.',
            'min_amount_cents.integer' => 'O valor mínimo da recarga deve ser informado em reais, com até duas casas decimais.',
            'min_amount_cents.min' => 'O valor mínimo da recarga precisa ser maior que zero.',
            'min_amount_cents.max' => 'O valor mínimo da recarga excede o limite permitido.',
            'max_amount_cents.required' => 'Informe o valor máximo da recarga em reais.',
            'max_amount_cents.integer' => 'O valor máximo da recarga deve ser informado em reais, com até duas casas decimais.',
            'max_amount_cents.min' => 'O valor máximo da recarga precisa ser maior que zero.',
            'max_amount_cents.max' => 'O valor máximo da recarga excede o limite permitido.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $minimum = $this->input('min_amount_cents');
            $maximum = $this->input('max_amount_cents');

            if (! is_int($minimum) || ! is_int($maximum)) {
                return;
            }

            $unitPriceCents = app(ConsultationPricing::class)->creditUnitPriceCents();

            if ($unitPriceCents !== null && $minimum < $unitPriceCents) {
                $validator->errors()->add(
                    'min_amount_cents',
                    sprintf(
                        'O valor mínimo precisa cobrir ao menos 1 crédito (%s).',
                        Money::format($unitPriceCents),
                    ),
                );
            }

            if ($maximum < $minimum) {
                $validator->errors()->add(
                    'max_amount_cents',
                    sprintf(
                        'O valor máximo da recarga deve ser maior ou igual ao mínimo (%s).',
                        Money::format($minimum),
                    ),
                );
            }
        });
    }
}
