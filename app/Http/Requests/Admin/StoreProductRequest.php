<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesImageUploads;
use App\Models\Product;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Cadastro de um produto da vitrine.
 *
 * O preço é digitado em reais e convertido para centavos sem float no
 * servidor. A imagem é opcional no cadastro; quando enviada, passa pelo
 * normalizador antes de ser gravada.
 */
class StoreProductRequest extends FormRequest
{
    use ValidatesImageUploads;

    public function authorize(): bool
    {
        return Gate::allows('create', Product::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'credits_amount' => ['required', 'integer', 'min:1', 'max:100000'],
            'price' => ['required', 'string', 'max:20', $this->validPriceRule()],
            'price_cents' => ['nullable', 'integer', 'min:100', 'max:100000000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'is_active' => ['required', 'boolean'],
            'remove_image' => ['nullable', 'boolean'],
            'image' => $this->imageUploadRules(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->normalizedProductFields());
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizedProductFields(): array
    {
        $title = $this->input('title');
        $description = $this->input('description');
        $price = $this->input('price');

        return [
            'title' => is_scalar($title) ? trim((string) $title) : null,
            'description' => is_scalar($description) && trim((string) $description) !== ''
                ? trim((string) $description)
                : null,
            'price' => is_scalar($price) ? trim((string) $price) : null,
            'price_cents' => is_scalar($price)
                ? Money::parseDecimalToCents((string) $price)
                : null,
            'sort_order' => $this->filled('sort_order') ? (int) $this->input('sort_order') : 0,
        ];
    }

    /**
     * Valida o texto digitado e garante o piso de R$ 1,00 do provedor.
     */
    protected function validPriceRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $cents = is_scalar($value) ? Money::parseDecimalToCents((string) $value) : null;

            if ($cents === null) {
                $fail('Informe um preço válido, como 29,90.');

                return;
            }

            if ($cents < 100) {
                $fail('O preço mínimo é R$ 1,00.');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Informe o título do produto.',
            'title.max' => 'O título deve ter no máximo 120 caracteres.',
            'description.max' => 'A descrição deve ter no máximo 2000 caracteres.',
            'credits_amount.required' => 'Informe quantos créditos o produto entrega.',
            'credits_amount.min' => 'O produto deve entregar pelo menos 1 crédito.',
            'credits_amount.max' => 'A quantidade de créditos é muito alta.',
            'price.required' => 'Informe o preço do produto.',
            'price.max' => 'O preço informado é inválido.',
            'price_cents.min' => 'O preço mínimo é R$ 1,00.',
            'price_cents.max' => 'O preço informado é muito alto.',
            'sort_order.integer' => 'A ordem de exibição deve ser um número inteiro.',
            'sort_order.min' => 'A ordem de exibição não pode ser negativa.',
            'is_active.required' => 'Defina o estado do produto.',
            'image.image' => 'O arquivo enviado não é uma imagem válida.',
            'image.mimes' => 'A imagem deve ser JPEG, PNG ou WebP.',
            'image.mimetypes' => 'O conteúdo do arquivo não corresponde a JPEG, PNG ou WebP.',
            'image.max' => 'A imagem deve ter no máximo '.$this->imageUploadMaxMb().' MB.',
            'image.uploaded' => 'Não foi possível receber a imagem. Verifique o tamanho do arquivo e tente novamente.',
        ];
    }
}
