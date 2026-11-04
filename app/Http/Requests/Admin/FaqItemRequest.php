<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class FaqItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('access-admin-panel');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:200'],
            'answer' => ['required', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $question = $this->input('question');
        $answer = $this->input('answer');

        $this->merge([
            'question' => is_scalar($question) ? trim((string) $question) : null,
            'answer' => is_scalar($answer) ? trim((string) $answer) : null,
            'is_active' => $this->boolean('is_active'),
            'sort_order' => is_numeric($this->input('sort_order')) ? (int) $this->input('sort_order') : 0,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'question.required' => 'Informe a pergunta.',
            'question.max' => 'A pergunta deve ter no máximo 200 caracteres.',
            'answer.required' => 'Informe a resposta.',
            'answer.max' => 'A resposta deve ter no máximo 2000 caracteres.',
            'sort_order.integer' => 'A ordem de exibição deve ser um número inteiro.',
            'sort_order.min' => 'A ordem de exibição não pode ser negativa.',
            'sort_order.max' => 'A ordem de exibição deve ser no máximo 999.',
        ];
    }
}
