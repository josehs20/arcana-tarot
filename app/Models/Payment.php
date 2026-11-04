<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'provider',
    'provider_payment_id',
    'external_reference',
    'amount_cents',
    'credits',
    'credit_unit_price_cents',
    'package_key',
    'status',
    'raw_status',
    'pix_copy_paste',
    'checkout_url',
    'invoice_slug',
    'transaction_nsu',
    'capture_method',
    'failure_reason',
    'idempotency_key',
    'expires_at',
    'paid_at',
    'verified_at',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_cents' => 'integer',
            'credits' => 'integer',
            'credit_unit_price_cents' => 'integer',
            'pix_copy_paste' => 'encrypted',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PaymentWebhookEvent::class);
    }
}
