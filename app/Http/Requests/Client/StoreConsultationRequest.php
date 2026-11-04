<?php

namespace App\Http\Requests\Client;

use App\Enums\ConsultationModality;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_active
            && (bool) $this->user()?->isClient();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'attendant_id' => ['required', 'integer', 'exists:users,id'],
            /*
             * A modalidade e obrigatoria na interface, mas segue opcional no
             * servidor para preservar integracoes antigas: sem o campo, a
             * consulta e tratada como texto.
             */
            'modality' => ['nullable', Rule::enum(ConsultationModality::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'attendant_id.required' => 'Escolha um atendente para solicitar a consulta.',
            'attendant_id.integer' => 'Atendente inválido.',
            'attendant_id.exists' => 'Este atendente não está disponível.',
            'modality.enum' => 'Escolha uma modalidade de atendimento válida.',
        ];
    }
}
