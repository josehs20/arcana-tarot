<?php

namespace App\Http\Requests\Admin;

use App\Models\TarotDeck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateTarotDeckRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deck = $this->route('deck');

        return $deck instanceof TarotDeck && Gate::allows('update', $deck);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var TarotDeck $deck */
        $deck = $this->route('deck');

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required',
                'string',
                'max:120',
                'alpha_dash',
                Rule::unique('tarot_decks', 'slug')->ignore($deck->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $slug = $this->input('slug');
        $description = $this->input('description');

        $this->merge([
            'name' => is_scalar($name) ? trim((string) $name) : null,
            'slug' => is_scalar($slug) ? Str::slug((string) $slug) : null,
            'description' => is_scalar($description) && trim((string) $description) !== ''
                ? trim((string) $description)
                : null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome do baralho.',
            'name.max' => 'O nome do baralho deve ter no máximo 120 caracteres.',
            'slug.required' => 'Informe o identificador do baralho.',
            'slug.alpha_dash' => 'Use apenas letras, números, hífens e sublinhados no identificador.',
            'slug.unique' => 'Já existe um baralho com este identificador.',
            'description.max' => 'A descrição deve ter no máximo 2000 caracteres.',
            'is_active.required' => 'Defina o estado do baralho.',
        ];
    }
}
