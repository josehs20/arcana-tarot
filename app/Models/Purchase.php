<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compra de um produto.
 *
 * `product_title`, `amount_cents` e `credits_amount` são fotos do momento da
 * compra: o histórico continua legível mesmo se o produto for editado ou
 * removido. A confirmação vem exclusivamente do webhook do provedor.
 */
#[Fillable([
    'user_id',
    'product_id',
    'product_title',
    'amount_cents',
    'credits_amount',
    'credit_unit_price_cents',
    'status',
    'provider',
    'external_reference',
    'idempotency_key',
    'checkout_url',
    'invoice_slug',
    'transaction_nsu',
    'failure_reason',
    'paid_at',
    'refunded_at',
])]
class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'credits_amount' => 'integer',
            'credit_unit_price_cents' => 'integer',
            'status' => PurchaseStatus::class,
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }
}
