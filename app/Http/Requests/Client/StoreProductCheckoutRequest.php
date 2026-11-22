<?php

namespace App\Http\Requests\Client;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Compra de um produto da vitrine.
 *
 * O navegador envia apenas a operação idempotente: produto, preço e créditos
 * vêm do catálogo no servidor.
 */
class StoreProductCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product
            && $product->is_active
            && (bool) $this->user()?->is_active
            && (bool) $this->user()?->isClient();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'operation_id' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'operation_id.required' => 'A operação de compra não foi identificada. Recarregue a página.',
            'operation_id.uuid' => 'A identificação da operação é inválida. Recarregue a página.',
        ];
    }
}
