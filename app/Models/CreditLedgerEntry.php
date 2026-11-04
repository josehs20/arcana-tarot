<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use Database\Factories\CreditLedgerEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

#[Fillable([
    'wallet_id',
    'type',
    'amount_cents',
    'balance_after_cents',
    'reference_type',
    'reference_id',
    'reason',
    'created_by',
    'idempotency_key',
])]
class CreditLedgerEntry extends Model
{
    /** @use HasFactory<CreditLedgerEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Lançamentos financeiros são imutáveis: alterações e exclusões são
     * bloqueadas na camada de aplicação. Correções devem gerar um novo
     * lançamento de crédito/débito.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Lançamentos do livro-razão são imutáveis.');
        });

        static::deleting(function (): void {
            throw new LogicException('Lançamentos do livro-razão não podem ser excluídos.');
        });
    }

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_cents' => 'integer',
            'balance_after_cents' => 'integer',
            'created_by' => 'integer',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Rótulo seguro para o histórico. O cliente nunca recebe caminhos,
     * credenciais ou dados internos da origem do lançamento.
     */
    public function referenceLabel(): ?string
    {
        return match ($this->reference_type) {
            Payment::class => 'Pagamento',
            Consultation::class => 'Consulta',
            AuditLog::class => 'Ajuste administrativo',
            null => null,
            default => class_basename($this->reference_type),
        };
    }

    public function sourceLabel(): ?string
    {
        return $this->reason ?? $this->referenceLabel();
    }
}
