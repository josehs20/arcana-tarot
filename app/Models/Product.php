<?php

namespace App\Models;

use App\Support\Money;
use App\Support\ProductImageStorage;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Produto vendido na home.
 *
 * Preço e créditos são definidos no painel. A compra guarda uma cópia destes
 * valores, então editar ou inativar o produto não reescreve o histórico.
 */
#[Fillable([
    'title',
    'description',
    'credits_amount',
    'price_cents',
    'image_path',
    'is_active',
    'sort_order',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'credits_amount' => 'integer',
            'price_cents' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('title');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function priceLabel(): string
    {
        return Money::format($this->price_cents);
    }

    public function creditsLabel(): string
    {
        return $this->credits_amount.' '.($this->credits_amount === 1 ? 'crédito' : 'créditos');
    }

    /**
     * URL pública da imagem. Retorna null quando não há arquivo válido no
     * disco, evitando um card com imagem quebrada.
     */
    public function imageUrl(): ?string
    {
        return app(ProductImageStorage::class)->url($this->image_path);
    }

    public function summary(int $limit = 120): string
    {
        return Str::limit((string) $this->description, $limit);
    }
}
