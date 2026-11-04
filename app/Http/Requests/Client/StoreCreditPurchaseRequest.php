<?php

namespace App\Http\Requests\Client;

use App\Models\Payment;
use App\Support\ConsultationPricing;
use App\Support\Money;
use App\Support\PaymentLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreCreditPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_active
            && (bool) $this->user()?->isClient()
            && Gate::allows('create', Payment::class);
    }

    /**
     * O formulário trabalha em reais; o domínio e o banco trabalham em centavos.
     * O valor enviado pelo navegador é apenas uma intenção: o servidor valida os
     * limites, o múltiplo do preço do crédito e recalcula a quantidade.
     */
    protected function prepareForValidation(): void
    {
        if ($this->exists('amount_reais')) {
            $this->merge([
                'amount_cents' => Money::parseDecimalToCents((string) $this->input('amount_reais')),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $limits = app(PaymentLimits::class);

        return [
            'amount_cents' => [
                'required',
                'integer',
                'min:'.$limits->minimumCents(),
                'max:'.$limits->maximumCents(),
            ],
            'operation_id' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount_cents.required' => 'Informe um valor válido para a recarga.',
            'amount_cents.integer' => 'O valor da recarga deve ser informado em reais, com até duas casas decimais.',
            'amount_cents.min' => 'O valor da recarga é menor que o mínimo permitido.',
            'amount_cents.max' => 'O valor da recarga é maior que o máximo permitido.',
            'operation_id.required' => 'A operação de recarga não foi identificada. Recarregue a página.',
            'operation_id.uuid' => 'A identificação da operação é inválida. Recarregue a página.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('amount_cents')) {
                    return;
                }

                $unitPriceCents = app(ConsultationPricing::class)->creditUnitPriceCents();

                if ($unitPriceCents === null) {
                    $validator->errors()->add(
                        'amount_cents',
                        'O valor do crédito ainda não foi configurado pela administração.',
                    );

                    return;
                }

                if ((int) $this->input('amount_cents') % $unitPriceCents !== 0) {
                    $validator->errors()->add(
                        'amount_cents',
                        'O valor da recarga deve ser múltiplo de '.Money::format($unitPriceCents).'.',
                    );
                }
            },
        ];
    }
}
