<?php

namespace App\Models;

use App\Enums\CommissionEntryType;
use Database\Factories\CommissionLedgerEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['attendant_id', 'consultation_id', 'commission_rate_bps', 'base_amount_cents', 'commission_amount_cents', 'type', 'reason', 'idempotency_key'])]
class CommissionLedgerEntry extends Model
{
    /** @use HasFactory<CommissionLedgerEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => CommissionEntryType::class,
            'commission_rate_bps' => 'integer',
            'base_amount_cents' => 'integer',
            'commission_amount_cents' => 'integer',
        ];
    }

    public function attendant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendant_id');
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }
}
