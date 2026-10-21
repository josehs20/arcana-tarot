<?php

namespace App\Models;

use App\Enums\ConsultationEndReason;
use App\Enums\ConsultationStatus;
use Database\Factories\ConsultationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['client_id', 'attendant_id', 'status', 'commission_rate_bps', 'rate_credits_per_minute', 'credit_unit_price_cents', 'started_at', 'ended_at', 'ended_reason'])]
class Consultation extends Model
{
    /** @use HasFactory<ConsultationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ConsultationStatus::class,
            'ended_reason' => ConsultationEndReason::class,
            'commission_rate_bps' => 'integer',
            'rate_credits_per_minute' => 'integer',
            'credit_unit_price_cents' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function attendant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendant_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConsultationMessage::class);
    }
}
