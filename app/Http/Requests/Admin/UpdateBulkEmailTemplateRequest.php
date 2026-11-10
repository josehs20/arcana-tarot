<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Support\BulkEmailDispatcher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Salvamento do assunto e do corpo padrão do disparo.
 *
 * O padrão é apenas um atalho de digitação: cada campanha guarda sua própria
 * cópia do texto no momento do envio.
 */
class UpdateBulkEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', User::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'subject' => trim((string) $this->input('subject')),
            'body' => trim((string) $this->input('body')),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:'.BulkEmailDispatcher::MAX_SUBJECT_LENGTH],
            'body' => ['required', 'string', 'max:'.BulkEmailDispatcher::MAX_BODY_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject.required' => 'Informe o assunto padrão.',
            'subject.max' => 'O assunto deve ter no máximo '.BulkEmailDispatcher::MAX_SUBJECT_LENGTH.' caracteres.',
            'body.required' => 'Escreva o corpo padrão.',
            'body.max' => 'O corpo deve ter no máximo '.BulkEmailDispatcher::MAX_BODY_LENGTH.' caracteres.',
        ];
    }
}
