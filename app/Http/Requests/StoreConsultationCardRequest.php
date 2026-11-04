<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreConsultationCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('sendCard', $this->route('consultation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tarot_card_id' => ['required', 'integer', Rule::exists('tarot_cards', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tarot_card_id.required' => 'Selecione uma carta para enviar.',
            'tarot_card_id.integer' => 'Selecione uma carta válida.',
            'tarot_card_id.exists' => 'A carta selecionada não está disponível.',
        ];
    }
}
