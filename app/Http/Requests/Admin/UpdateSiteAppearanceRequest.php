<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Valida as cores (`#rrggbb`) e as fontes da aparência do site.
 *
 * Nunca aceita CSS livre: apenas hexadecimal de 6 dígitos e chaves presentes
 * na allowlist `config('appearance.fonts')`.
 */
class UpdateSiteAppearanceRequest extends FormRequest
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
        $hex = ['required', 'string', 'regex:/^#(?:[0-9a-fA-F]{6})$/'];
        $colors = [];

        foreach (['light', 'dark'] as $theme) {
            foreach (array_keys((array) config("appearance.defaults.{$theme}", [])) as $key) {
                $colors["colors.{$theme}.{$key}"] = $hex;
            }
        }

        return $colors + [
            'fonts.display' => ['required', 'string', Rule::in(array_keys((array) config('appearance.fonts.display', [])))],
            'fonts.body' => ['required', 'string', Rule::in(array_keys((array) config('appearance.fonts.body', [])))],
        ];
    }

    protected function prepareForValidation(): void
    {
        $colors = $this->input('colors');
        $normalized = [];

        if (is_array($colors)) {
            foreach ($colors as $theme => $palette) {
                if (! is_array($palette)) {
                    continue;
                }

                foreach ($palette as $key => $value) {
                    $normalized[$theme][$key] = is_string($value) ? strtolower(trim($value)) : $value;
                }
            }
        }

        $this->merge([
            'colors' => $normalized,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'colors.*.*.regex' => 'Cada cor deve estar no formato hexadecimal (#rrggbb).',
            'colors.*.*.required' => 'Informe todas as cores de cada tema.',
            'fonts.display.required' => 'Escolha a fonte de títulos.',
            'fonts.display.in' => 'Fonte de títulos inválida.',
            'fonts.body.required' => 'Escolha a fonte de textos.',
            'fonts.body.in' => 'Fonte de textos inválida.',
        ];
    }
}
