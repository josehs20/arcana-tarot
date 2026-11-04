<?php

namespace App\Http\Requests\Admin;

use App\Models\CommissionLedgerEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ReverseCommissionEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('entry') instanceof CommissionLedgerEntry
            && Gate::allows('viewAny', CommissionLedgerEntry::class);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Informe o motivo do estorno.',
            'reason.min' => 'Descreva o motivo do estorno com pelo menos 5 caracteres.',
            'reason.max' => 'O motivo do estorno deve ter no máximo 500 caracteres.',
        ];
    }
}
