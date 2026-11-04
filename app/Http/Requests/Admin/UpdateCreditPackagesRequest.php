<?php

namespace App\Http\Requests\Admin;

use App\Models\Wallet;
use App\Support\ConsultationPricing;
use App\Support\CreditPackages;
use App\Support\Money;
use App\Support\PaymentLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Catálogo de pacotes de créditos do checkout.
 *
 * A administração informa apenas a quantidade de créditos (e um rótulo
 * opcional): o preço em reais continua sendo derivado no servidor a partir do
 * valor do crédito. Pacotes cujo total fique fora dos limites de recarga são
 * recusados aqui, para não desaparecerem em silêncio do checkout do cliente.
 */
class UpdateCreditPackagesRequest extends FormRequest
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
            'packages' => ['required', 'array', 'min:1', 'max:'.CreditPackages::MAX_PACKAGES],
            'packages.*.credits' => [
                'required',
                'integer',
                'min:1',
                'max:'.CreditPackages::MAX_CREDITS,
                'distinct',
            ],
            'packages.*.label' => [
                'nullable',
                'string',
                'max:'.CreditPackages::MAX_LABEL_LENGTH,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'packages.required' => 'Informe pelo menos um pacote de créditos.',
            'packages.array' => 'O catálogo de pacotes é inválido. Recarregue a página.',
            'packages.min' => 'Informe pelo menos um pacote de créditos.',
            'packages.max' => 'O catálogo aceita no máximo '.CreditPackages::MAX_PACKAGES.' pacotes.',
            'packages.*.credits.required' => 'Informe a quantidade de créditos do pacote.',
            'packages.*.credits.integer' => 'A quantidade de créditos deve ser um número inteiro.',
            'packages.*.credits.min' => 'Cada pacote precisa de pelo menos 1 crédito.',
            'packages.*.credits.max' => 'A quantidade de créditos do pacote excede o limite permitido.',
            'packages.*.credits.distinct' => 'Há pacotes repetidos com a mesma quantidade de créditos.',
            'packages.*.label.string' => 'O nome do pacote deve ser um texto.',
            'packages.*.label.max' => 'O nome do pacote deve ter no máximo '.CreditPackages::MAX_LABEL_LENGTH.' caracteres.',
        ];
    }

    /**
     * Confere o preço final de cada pacote contra os limites de recarga.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unitPriceCents = app(ConsultationPricing::class)->creditUnitPriceCents();

            if ($unitPriceCents === null) {
                return;
            }

            $limits = app(PaymentLimits::class);
            $minimum = $limits->minimumCents();
            $maximum = $limits->maximumCents();

            foreach ((array) $this->input('packages', []) as $index => $package) {
                $credits = (int) (is_array($package) ? ($package['credits'] ?? 0) : 0);

                // Quantidades inválidas já são sinalizadas pelas regras base.
                if ($credits < 1 || $credits > CreditPackages::MAX_CREDITS) {
                    continue;
                }

                $amountCents = $credits * $unitPriceCents;

                if ($amountCents >= $minimum && $amountCents <= $maximum) {
                    continue;
                }

                $validator->errors()->add(
                    'packages.'.$index.'.credits',
                    sprintf(
                        'Com o crédito a %s, este pacote custaria %s — fora dos limites de recarga (%s a %s).',
                        Money::format($unitPriceCents),
                        Money::format($amountCents),
                        Money::format($minimum),
                        Money::format($maximum),
                    ),
                );
            }
        });
    }
}
