<?php

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

class StartSupportConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'visitor_name' => is_string($this->input('visitor_name')) ? trim($this->input('visitor_name')) : $this->input('visitor_name'),
            'visitor_email' => is_string($this->input('visitor_email')) && trim($this->input('visitor_email')) !== ''
                ? trim($this->input('visitor_email'))
                : null,
            'body' => is_string($this->input('body')) ? trim($this->input('body')) : $this->input('body'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'visitor_name' => ['required', 'string', 'max:80'],
            'visitor_email' => ['nullable', 'string', 'email', 'max:255'],
            'body' => ['required', 'string', 'max:'.(int) config('support.message_max_length', 2000)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'visitor_name.required' => 'Informe seu nome para iniciar o atendimento.',
            'visitor_name.max' => 'O nome deve ter no máximo 80 caracteres.',
            'visitor_email.email' => 'Informe um e-mail válido ou deixe o campo vazio.',
            'body.required' => 'Escreva sua mensagem antes de enviar.',
            'body.max' => 'A mensagem deve ter no máximo '.(int) config('support.message_max_length', 2000).' caracteres.',
        ];
    }
}
