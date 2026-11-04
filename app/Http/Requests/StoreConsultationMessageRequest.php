<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreConsultationMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('sendMessage', $this->route('consultation'));
    }

    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        if (is_string($body)) {
            $this->merge(['body' => trim($body)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.(int) config('consultation.message_max_length')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Escreva uma mensagem antes de enviar.',
            'body.string' => 'A mensagem deve ser um texto.',
            'body.max' => 'A mensagem deve ter no máximo '.(int) config('consultation.message_max_length').' caracteres.',
        ];
    }
}
