<?php

namespace App\Http\Requests\Admin;

use App\Models\TarotCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreTarotCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', TarotCard::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:99999'],
            'meaning_upright' => ['nullable', 'string', 'max:5000'],
            'meaning_reversed' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['required', 'boolean'],
            'image' => $this->imageRules(required: true),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->normalizedTextFields());
    }

    /**
     * @return array<int, string>
     */
    protected function imageRules(bool $required): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'mimetypes:image/jpeg,image/png,image/webp',
            'max:'.max(1, (int) config('tarot.image_max_kb')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizedTextFields(): array
    {
        $name = $this->input('name');
        $upright = $this->input('meaning_upright');
        $reversed = $this->input('meaning_reversed');

        return [
            'name' => is_scalar($name) ? trim((string) $name) : null,
            'meaning_upright' => is_scalar($upright) && trim((string) $upright) !== ''
                ? trim((string) $upright)
                : null,
            'meaning_reversed' => is_scalar($reversed) && trim((string) $reversed) !== ''
                ? trim((string) $reversed)
                : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome da carta.',
            'name.max' => 'O nome da carta deve ter no máximo 120 caracteres.',
            'sort_order.required' => 'Informe a ordem de exibição da carta.',
            'sort_order.integer' => 'A ordem de exibição deve ser um número inteiro.',
            'sort_order.min' => 'A ordem de exibição não pode ser negativa.',
            'sort_order.max' => 'A ordem de exibição é muito alta.',
            'meaning_upright.max' => 'O significado normal deve ter no máximo 5000 caracteres.',
            'meaning_reversed.max' => 'O significado invertido deve ter no máximo 5000 caracteres.',
            'is_active.required' => 'Defina o estado da carta.',
            'image.required' => 'Envie a imagem frontal da carta.',
            'image.image' => 'O arquivo enviado não é uma imagem válida.',
            'image.mimes' => 'A imagem deve ser JPEG, PNG ou WebP.',
            'image.mimetypes' => 'O conteúdo do arquivo não corresponde a JPEG, PNG ou WebP.',
            'image.max' => 'A imagem deve ter no máximo '.(int) config('tarot.image_max_kb').' KB.',
            'image.uploaded' => 'Não foi possível receber a imagem. Verifique o tamanho do arquivo e tente novamente.',
        ];
    }
}
