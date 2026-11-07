<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Support\BulkEmailDispatcher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Criação de um disparo de e-mail.
 *
 * O formulário envia `recipients[]` com os IDs marcados na tabela. A lista é
 * revalidada no servidor (existência, unicidade e teto por disparo); e-mails
 * inválidos e repetidos são descartados pelo BulkEmailDispatcher.
 */
class SendBulkEmailRequest extends FormRequest
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
            'recipients' => ['required', 'array', 'min:1', 'max:'.BulkEmailDispatcher::MAX_RECIPIENTS],
            'recipients.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject.required' => 'Informe o assunto do e-mail.',
            'subject.max' => 'O assunto deve ter no máximo '.BulkEmailDispatcher::MAX_SUBJECT_LENGTH.' caracteres.',
            'body.required' => 'Escreva o corpo do e-mail.',
            'body.max' => 'O corpo deve ter no máximo '.BulkEmailDispatcher::MAX_BODY_LENGTH.' caracteres.',
            'recipients.required' => 'Selecione ao menos um destinatário.',
            'recipients.min' => 'Selecione ao menos um destinatário.',
            'recipients.max' => 'Um disparo aceita no máximo '.BulkEmailDispatcher::MAX_RECIPIENTS.' destinatários.',
            'recipients.*.integer' => 'A seleção de destinatários é inválida.',
            'recipients.*.distinct' => 'Há destinatários repetidos na seleção.',
            'recipients.*.exists' => 'Um dos destinatários selecionados não existe mais.',
        ];
    }
}
