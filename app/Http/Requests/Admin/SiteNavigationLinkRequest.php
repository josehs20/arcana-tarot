<?php

namespace App\Http\Requests\Admin;

use App\Enums\SiteLinkType;
use App\Enums\SiteNavigationGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SiteNavigationLinkRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:40'],
            'group' => ['required', Rule::enum(SiteNavigationGroup::class)],
            'target_type' => ['required', Rule::enum(SiteLinkType::class)],
            'target' => array_merge(['required', 'string', 'max:255'], $this->targetRules()),
            'opens_in_new_tab' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $label = $this->input('label');
        $type = $this->input('target_type');
        $target = $this->input('target');

        $target = is_scalar($target) ? trim((string) $target) : '';

        if ($type === SiteLinkType::Anchor->value && $target !== '' && ! str_starts_with($target, '#')) {
            $target = '#'.$target;
        }

        $this->merge([
            'label' => is_scalar($label) ? trim((string) $label) : null,
            'target' => is_scalar($target) ? $target : null,
            'opens_in_new_tab' => $this->boolean('opens_in_new_tab'),
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
            'label.required' => 'Informe o texto do link.',
            'label.max' => 'O texto do link deve ter no máximo 40 caracteres.',
            'group.required' => 'Escolha onde o link aparece.',
            'group.enum' => 'Grupo de navegação inválido.',
            'target_type.required' => 'Escolha o tipo de destino.',
            'target_type.enum' => 'Tipo de destino inválido.',
            'target.required' => 'Informe o destino do link.',
            'target.in' => 'Destino não permitido. Use uma das rotas ou âncoras disponíveis.',
            'target.url' => 'Informe uma URL externa completa, começando com https://.',
            'sort_order.integer' => 'A ordem de exibição deve ser um número inteiro.',
            'sort_order.min' => 'A ordem de exibição não pode ser negativa.',
            'sort_order.max' => 'A ordem de exibição deve ser no máximo 999.',
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function targetRules(): array
    {
        return match ($this->input('target_type')) {
            SiteLinkType::Route->value => [Rule::in((array) config('site.allowed_routes', []))],
            SiteLinkType::Anchor->value => [
                Rule::in(array_map(
                    fn (string $anchor): string => '#'.$anchor,
                    (array) config('site.allowed_anchors', []),
                )),
            ],
            SiteLinkType::Url->value => ['url:https'],
            default => [],
        };
    }
}
