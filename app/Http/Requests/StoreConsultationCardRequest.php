<?php

namespace App\Http\Requests;

use App\Support\ConsultationMessageManager;
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
     * Aceita o envio de uma ou várias cartas.
     *
     * O formato antigo (`tarot_card_id`) continua válido para integrações e
     * testes existentes; ele é convertido para a lista antes da validação.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('tarot_card_ids') || ! $this->has('tarot_card_id')) {
            return;
        }

        $this->merge(['tarot_card_ids' => [$this->input('tarot_card_id')]]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tarot_card_id' => ['sometimes', 'integer', Rule::exists('tarot_cards', 'id')],
            'tarot_card_ids' => ['required', 'array', 'min:1', 'max:'.ConsultationMessageManager::MAX_CARDS_PER_SEND],
            'tarot_card_ids.*' => ['required', 'integer', 'distinct', Rule::exists('tarot_cards', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $max = ConsultationMessageManager::MAX_CARDS_PER_SEND;

        return [
            'tarot_card_id.integer' => 'Selecione uma carta válida.',
            'tarot_card_id.exists' => 'A carta selecionada não está disponível.',
            'tarot_card_ids.required' => 'Selecione ao menos uma carta para enviar.',
            'tarot_card_ids.array' => 'Selecione as cartas que deseja enviar.',
            'tarot_card_ids.min' => 'Selecione ao menos uma carta para enviar.',
            'tarot_card_ids.max' => 'Envie no máximo '.$max.' cartas por vez.',
            'tarot_card_ids.*.required' => 'Selecione uma carta válida.',
            'tarot_card_ids.*.integer' => 'Selecione uma carta válida.',
            'tarot_card_ids.*.distinct' => 'Você repetiu uma carta na seleção.',
            'tarot_card_ids.*.exists' => 'Uma das cartas selecionadas não está disponível.',
        ];
    }

    /**
     * IDs selecionados, já na ordem em que aparecem na grade.
     *
     * @return list<int>
     */
    public function cardIds(): array
    {
        /** @var array<int, mixed> $ids */
        $ids = $this->validated('tarot_card_ids');

        return array_values(array_map('intval', $ids));
    }
}
