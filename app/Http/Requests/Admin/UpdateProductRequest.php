<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Support\Facades\Gate;

/**
 * Edição de produto: mesmas regras do cadastro, com autorização na instância.
 *
 * A imagem continua opcional — enviar outra substitui a atual, e o formulário
 * oferece a remoção explícita da imagem existente.
 */
class UpdateProductRequest extends StoreProductRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product && Gate::allows('update', $product);
    }
}
