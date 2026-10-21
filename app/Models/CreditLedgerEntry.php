<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use Database\Factories\CreditLedgerEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['wallet_id', 'type', 'amount_cents', 'balance_after_cents', 'reference_type', 'reference_id', 'idempotency_key'])]
class CreditLedgerEntry extends Model
{
    /** @use HasFactory<CreditLedgerEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_cents' => 'integer',
            'balance_after_cents' => 'integer',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
