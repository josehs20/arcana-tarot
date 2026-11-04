<?php

namespace App\Models;

use App\Enums\CommissionEntryType;
use Database\Factories\CommissionLedgerEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

#[Fillable([
    'attendant_id',
    'consultation_id',
    'reversed_entry_id',
    'commission_rate_bps',
    'base_amount_cents',
    'commission_amount_cents',
    'type',
    'actor_id',
    'reason',
    'idempotency_key',
])]
class CommissionLedgerEntry extends Model
{
    /** @use HasFactory<CommissionLedgerEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * O razão de comissões é imutável. Correções sempre geram um novo
     * lançamento de estorno ou ajuste, preservando a trilha histórica.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Lançamentos de comissão são imutáveis.');
        });

        static::deleting(function (): void {
            throw new LogicException('Lançamentos de comissão não podem ser excluídos.');
        });
    }

    protected function casts(): array
    {
        return [
            'type' => CommissionEntryType::class,
            'commission_rate_bps' => 'integer',
            'base_amount_cents' => 'integer',
            'commission_amount_cents' => 'integer',
            'actor_id' => 'integer',
            'reversed_entry_id' => 'integer',
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_entry_id');
    }

    /**
     * Estorno existente para este lançamento. A relação é usada para impedir
     * estorno duplicado e para sinalizar a situação no painel administrativo.
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversed_entry_id');
    }

    /**
     * Valor com sinal para totais. Estornos são persistidos como valor positivo
     * e subtraem do acumulado por causa do tipo.
     */
    public function signedAmountCents(): int
    {
        return $this->type === CommissionEntryType::Reversal
            ? -$this->commission_amount_cents
            : $this->commission_amount_cents;
    }

    public function canBeReversed(): bool
    {
        if ($this->type !== CommissionEntryType::Accrual) {
            return false;
        }

        if ($this->relationLoaded('reversal')) {
            return $this->reversal === null;
        }

        return ! $this->reversal()->exists();
    }
}
