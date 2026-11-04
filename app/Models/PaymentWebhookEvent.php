<?php

namespace App\Models;

use App\Enums\PaymentEventStatus;
use Database\Factories\PaymentWebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'payment_id',
    'provider',
    'provider_event_id',
    'event_type',
    'provider_payment_id',
    'status',
    'failure_reason',
    'payload_hash',
    'amount_cents',
    'occurred_at',
    'processed_at',
])]
class PaymentWebhookEvent extends Model
{
    /** @use HasFactory<PaymentWebhookEventFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => PaymentEventStatus::class,
            'amount_cents' => 'integer',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
