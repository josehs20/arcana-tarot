<?php

namespace App\Http\Requests\Client;

use App\Models\Payment;
use App\Support\CreditPackages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Valida a escolha do pacote de créditos no checkout InfinitePay.
 *
 * O navegador envia apenas a chave do pacote e o `operation_id`: valor e
 * quantidade de créditos vêm do catálogo do servidor, nunca do formulário.
 */
class StoreCreditCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_active
            && (bool) $this->user()?->isClient()
            && Gate::allows('create', Payment::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'package' => [
                'required',
                'string',
                'max:40',
                Rule::in(app(CreditPackages::class)->keys()),
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
            'package.required' => 'Escolha um pacote de créditos.',
            'package.in' => 'O pacote escolhido não está disponível.',
            'operation_id.required' => 'A operação de recarga não foi identificada. Recarregue a página.',
            'operation_id.uuid' => 'A identificação da operação é inválida. Recarregue a página.',
        ];
    }

    /**
     * Recusa, antes de qualquer chamada ao provedor, pacotes que o catálogo não
     * consegue precificar (sem preço de crédito ou fora dos limites).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $packages = app(CreditPackages::class);

            if ($packages->find((string) $this->input('package')) === null) {
                $validator->errors()->add(
                    'package',
                    'Este pacote está indisponível no momento. Recarregue a página.',
                );
            }
        });
    }
}
