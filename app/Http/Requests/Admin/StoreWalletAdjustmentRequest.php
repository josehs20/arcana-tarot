<?php

namespace App\Http\Requests\Admin;

use App\Enums\LedgerEntryType;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreWalletAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            && $target->isClient()
            && Gate::allows('adjust', Wallet::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(LedgerEntryType::class)],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'operation_id' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Informe se o ajuste é um crédito ou débito.',
            'amount_cents.required' => 'Informe o valor do ajuste em centavos.',
            'amount_cents.integer' => 'O valor deve ser um número inteiro de centavos, sem casas decimais.',
            'amount_cents.min' => 'O valor do ajuste deve ser maior que zero.',
            'reason.required' => 'O motivo do ajuste é obrigatório.',
            'reason.min' => 'Descreva o motivo do ajuste com pelo menos 5 caracteres.',
            'reason.max' => 'O motivo do ajuste deve ter no máximo 500 caracteres.',
            'operation_id.required' => 'A operação de ajuste não foi identificada. Recarregue a página.',
            'operation_id.uuid' => 'A identificação da operação é inválida. Recarregue a página.',
        ];
    }
}
