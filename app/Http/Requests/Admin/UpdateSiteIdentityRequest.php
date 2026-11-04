<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateSiteIdentityRequest extends FormRequest
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
        $maxKb = max(1, (int) config('site.max_image_kb', 2048));

        return [
            'site_name' => ['required', 'string', 'max:80'],
            'site_description' => ['required', 'string', 'max:300'],
            'logo' => [
                'nullable',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.$maxKb,
            ],
            'favicon' => [
                'nullable',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.$maxKb,
            ],
            'remove_logo' => ['sometimes', 'boolean'],
            'remove_favicon' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('site_name');
        $description = $this->input('site_description');

        $this->merge([
            'site_name' => is_scalar($name) ? trim((string) $name) : null,
            'site_description' => is_scalar($description) ? trim((string) $description) : null,
            'remove_logo' => $this->boolean('remove_logo'),
            'remove_favicon' => $this->boolean('remove_favicon'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'site_name.required' => 'Informe o nome do site.',
            'site_name.max' => 'O nome do site deve ter no máximo 80 caracteres.',
            'site_description.required' => 'Informe a descrição exibida na home e nos metadados.',
            'site_description.max' => 'A descrição deve ter no máximo 300 caracteres.',
            'logo.image' => 'O logotipo deve ser uma imagem válida.',
            'logo.mimes' => 'O logotipo deve ser JPEG, PNG ou WebP.',
            'logo.mimetypes' => 'O conteúdo do logotipo não corresponde a JPEG, PNG ou WebP.',
            'logo.max' => 'O logotipo deve ter no máximo '.(int) config('site.max_image_kb', 2048).' KB.',
            'favicon.image' => 'O favicon deve ser uma imagem válida.',
            'favicon.mimes' => 'O favicon deve ser JPEG, PNG ou WebP.',
            'favicon.mimetypes' => 'O conteúdo do favicon não corresponde a JPEG, PNG ou WebP.',
            'favicon.max' => 'O favicon deve ter no máximo '.(int) config('site.max_image_kb', 2048).' KB.',
        ];
    }
}
