<?php

namespace App\Models;

use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Campanha "Compre X créditos e ganhe Y".
 *
 * A vigência é opcional: sem datas a promoção vale enquanto estiver ativa. O
 * escopo `active()` concentra a regra usada tanto na home pública quanto na
 * concessão do bônus no momento da confirmação do pagamento.
 */
#[Fillable([
    'title',
    'description',
    'buy_credits',
    'bonus_credits',
    'is_active',
    'starts_at',
    'ends_at',
])]
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'buy_credits' => 'integer',
            'bonus_credits' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Promoções publicadas e dentro da janela de vigência.
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });
    }

    public function isRunning(): bool
    {
        $now = now();

        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->lessThanOrEqualTo($now))
            && ($this->ends_at === null || $this->ends_at->greaterThanOrEqualTo($now));
    }

    /**
     * Chave usada pelo `<x-ui.status-badge>` no painel administrativo.
     */
    public function statusKey(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        if ($this->ends_at !== null && $this->ends_at->isPast()) {
            return 'expired';
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return 'pending';
        }

        return 'active';
    }

    public function statusLabel(): string
    {
        return match ($this->statusKey()) {
            'inactive' => 'Inativa',
            'expired' => 'Expirada',
            'pending' => 'Agendada',
            default => 'Ativa',
        };
    }

    /**
     * Texto curto reutilizado na home, na carteira e no extrato.
     */
    public function headline(): string
    {
        return sprintf(
            'Compre %d créditos e ganhe +%d',
            $this->buy_credits,
            $this->bonus_credits,
        );
    }

    /**
     * Valor do bônus em centavos, sempre derivado da tarifa do crédito.
     */
    public function bonusAmountCents(int $unitPriceCents): int
    {
        if ($unitPriceCents < 1 || $this->bonus_credits < 1) {
            return 0;
        }

        return $unitPriceCents * $this->bonus_credits;
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
