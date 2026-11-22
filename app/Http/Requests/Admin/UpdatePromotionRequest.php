<?php

namespace App\Http\Requests\Admin;

use App\Models\Promotion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdatePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $promotion = $this->route('promotion');

        return $promotion instanceof Promotion && Gate::allows('update', $promotion);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'buy_credits' => ['required', 'integer', 'min:1', 'max:10000'],
            'bonus_credits' => ['required', 'integer', 'min:1', 'max:10000'],
            'is_active' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $description = $this->input('description');

        $this->merge([
            'title' => is_scalar($this->input('title')) ? trim((string) $this->input('title')) : null,
            'description' => is_scalar($description) && trim((string) $description) !== ''
                ? trim((string) $description)
                : null,
            'starts_at' => $this->filled('starts_at') ? $this->input('starts_at') : null,
            'ends_at' => $this->filled('ends_at') ? $this->input('ends_at') : null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Informe o título da promoção.',
            'title.max' => 'O título deve ter no máximo 120 caracteres.',
            'description.max' => 'A descrição deve ter no máximo 255 caracteres.',
            'buy_credits.required' => 'Informe quantos créditos o cliente precisa comprar.',
            'buy_credits.min' => 'A quantidade comprada deve ser de pelo menos 1 crédito.',
            'bonus_credits.required' => 'Informe quantos créditos extras o cliente recebe.',
            'bonus_credits.min' => 'O bônus deve ser de pelo menos 1 crédito.',
            'is_active.required' => 'Defina o estado da promoção.',
            'starts_at.date' => 'A data de início é inválida.',
            'ends_at.date' => 'A data de término é inválida.',
            'ends_at.after_or_equal' => 'O término não pode ser anterior ao início.',
        ];
    }
}
