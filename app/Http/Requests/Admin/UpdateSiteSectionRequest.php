<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesImageUploads;
use App\Support\SiteContent;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateSiteSectionRequest extends FormRequest
{
    use ValidatesImageUploads;

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
            'eyebrow' => ['nullable', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:120'],
            'subtitle' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:2000'],
            'image_alt' => ['nullable', 'string', 'max:160'],
            'primary_cta_label' => ['nullable', 'string', 'max:60'],
            'primary_cta_target' => ['nullable', 'string', 'max:255', $this->targetRule()],
            'secondary_cta_label' => ['nullable', 'string', 'max:60'],
            'secondary_cta_target' => ['nullable', 'string', 'max:255', $this->targetRule()],
            'is_published' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'image' => $this->imageUploadRules(),
            'remove_image' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'eyebrow' => $this->nullableInput('eyebrow'),
            'title' => $this->nullableInput('title'),
            'subtitle' => $this->nullableInput('subtitle'),
            'body' => $this->nullableInput('body'),
            'image_alt' => $this->nullableInput('image_alt'),
            'primary_cta_label' => $this->nullableInput('primary_cta_label'),
            'primary_cta_target' => $this->nullableInput('primary_cta_target'),
            'secondary_cta_label' => $this->nullableInput('secondary_cta_label'),
            'secondary_cta_target' => $this->nullableInput('secondary_cta_target'),
            'is_published' => $this->boolean('is_published'),
            'sort_order' => is_numeric($this->input('sort_order')) ? (int) $this->input('sort_order') : 0,
            'remove_image' => $this->boolean('remove_image'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Informe o título da seção.',
            'title.max' => 'O título deve ter no máximo 120 caracteres.',
            'eyebrow.max' => 'O texto de apoio deve ter no máximo 80 caracteres.',
            'subtitle.max' => 'O subtítulo deve ter no máximo 200 caracteres.',
            'body.max' => 'O texto deve ter no máximo 2000 caracteres.',
            'image_alt.max' => 'A descrição da imagem deve ter no máximo 160 caracteres.',
            'primary_cta_label.max' => 'O texto do botão principal deve ter no máximo 60 caracteres.',
            'secondary_cta_label.max' => 'O texto do botão secundário deve ter no máximo 60 caracteres.',
            'sort_order.integer' => 'A ordem de exibição deve ser um número inteiro.',
            'sort_order.min' => 'A ordem de exibição não pode ser negativa.',
            'sort_order.max' => 'A ordem de exibição deve ser no máximo 999.',
            'image.image' => 'Envie uma imagem válida.',
            'image.mimes' => 'A imagem deve ser JPEG, PNG ou WebP.',
            'image.mimetypes' => 'O conteúdo do arquivo não corresponde a JPEG, PNG ou WebP.',
            'image.max' => 'A imagem deve ter no máximo '.$this->imageUploadMaxMb().' MB.',
        ];
    }

    /**
     * Destinos aceitos: rota interna da allowlist, âncora conhecida da home ou
     * URL https. Qualquer outro valor é rejeitado na origem.
     */
    private function targetRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (blank($value)) {
                return;
            }

            if (app(SiteContent::class)->resolveTarget((string) $value) === null) {
                $fail('Informe uma rota interna válida, uma âncora da home (ex.: #como-funciona) ou uma URL https.');
            }
        };
    }

    private function nullableInput(string $key): ?string
    {
        $value = $this->input($key);

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
