<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Valida o arquivo de importação do banco enviado pelo painel.
 *
 * O limite de tamanho vem de `config('backup.import_max_kb')` e acompanha o
 * `upload_max_filesize` do PHP; a extensão é conferida à parte porque `.sql.gz`
 * precisa terminar exatamente assim (o `mimes` do Laravel olha o conteúdo, e um
 * dump em texto puro não é reconhecido como `sql` por ele).
 */
class ImportDatabaseRequest extends FormRequest
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
            'database_file' => [
                'required',
                'file',
                'max:'.max(1, (int) config('backup.import_max_kb')),
            ],
            'confirmacao' => ['accepted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $file = $this->file('database_file');

            if (! $file instanceof UploadedFile) {
                return;
            }

            $name = strtolower($file->getClientOriginalName());

            if (str_ends_with($name, '.sql') || str_ends_with($name, '.sql.gz')) {
                return;
            }

            $validator->errors()->add('database_file', 'Envie um arquivo com extensão .sql ou .sql.gz.');
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'database_file.required' => 'Selecione o arquivo .sql ou .sql.gz do banco.',
            'database_file.file' => 'O envio do arquivo falhou.',
            'database_file.uploaded' => 'O arquivo não pôde ser enviado. Verifique o limite de upload do servidor.',
            'database_file.max' => 'O arquivo excede o limite de :max KB por importação.',
            'confirmacao.accepted' => 'Confirme que as tabelas do banco atual serão apagadas e substituídas.',
        ];
    }
}
