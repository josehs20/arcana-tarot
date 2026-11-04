<?php

namespace App\Models;

use App\Enums\CallStatus;
use App\Enums\CommissionEntryType;
use App\Enums\ConsultationEndReason;
use App\Enums\ConsultationModality;
use App\Enums\ConsultationStatus;
use App\Support\Money;
use Database\Factories\ConsultationFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['client_id', 'attendant_id', 'status', 'modality', 'commission_rate_bps', 'rate_credits_per_minute', 'credit_unit_price_cents', 'price_per_minute_cents', 'started_at', 'last_activity_at', 'client_last_activity_at', 'attendant_last_activity_at', 'billed_minutes', 'ended_at', 'ended_reason'])]
class Consultation extends Model
{
    /** @use HasFactory<ConsultationFactory> */
    use HasFactory;

    /**
     * Mantém as travas de concorrência e valida as invariantes de participação.
     *
     * open_client_id e active_attendant_id são preenchidos aqui e protegidos
     * por índices únicos no banco. A checagem explícita existe para devolver
     * uma mensagem de domínio clara antes de o banco recusar a gravação.
     */
    protected static function booted(): void
    {
        static::saving(function (Consultation $consultation): void {
            if ($consultation->exists && ($consultation->isDirty('client_id') || $consultation->isDirty('attendant_id'))) {
                throw new DomainException('Os participantes de uma consulta não podem ser alterados.');
            }

            if (
                $consultation->exists
                && $consultation->isDirty('commission_rate_bps')
                && $consultation->getOriginal('started_at') !== null
            ) {
                throw new DomainException('A taxa de comissão da consulta já iniciada é imutável.');
            }

            if (
                $consultation->exists
                && ($consultation->isDirty('modality') || $consultation->isDirty('price_per_minute_cents'))
                && $consultation->getOriginal('started_at') !== null
            ) {
                throw new DomainException('A modalidade e o preço da consulta já iniciada são imutáveis.');
            }

            $status = $consultation->status;

            if (! $status instanceof ConsultationStatus) {
                $status = ConsultationStatus::tryFrom((string) $status) ?? ConsultationStatus::Requested;
                $consultation->status = $status;
            }

            $isOpen = $status->isOpen();

            $consultation->open_client_id = $isOpen ? $consultation->client_id : null;
            $consultation->active_attendant_id = $status === ConsultationStatus::Active
                ? $consultation->attendant_id
                : null;

            if ($isOpen) {
                $otherOpenExists = static::query()
                    ->where('client_id', $consultation->client_id)
                    ->whereIn('status', ConsultationStatus::openValues())
                    ->when($consultation->exists, fn ($query) => $query->whereKeyNot($consultation->getKey()))
                    ->exists();

                if ($otherOpenExists) {
                    throw new DomainException('O cliente já possui uma solicitação ou consulta em aberto.');
                }
            }

            if ($status === ConsultationStatus::Active) {
                $otherActiveExists = static::query()
                    ->where('attendant_id', $consultation->attendant_id)
                    ->where('status', ConsultationStatus::Active->value)
                    ->when($consultation->exists, fn ($query) => $query->whereKeyNot($consultation->getKey()))
                    ->exists();

                if ($otherActiveExists) {
                    throw new DomainException('O atendente já possui uma consulta ativa.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ConsultationStatus::class,
            'modality' => ConsultationModality::class,
            'ended_reason' => ConsultationEndReason::class,
            'commission_rate_bps' => 'integer',
            'rate_credits_per_minute' => 'integer',
            'credit_unit_price_cents' => 'integer',
            'price_per_minute_cents' => 'integer',
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'client_last_activity_at' => 'datetime',
            'attendant_last_activity_at' => 'datetime',
            'billed_minutes' => 'integer',
            'ended_at' => 'datetime',
            'client_last_read_at' => 'datetime',
            'attendant_last_read_at' => 'datetime',
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

    public function commissionEntries(): HasMany
    {
        return $this->hasMany(CommissionLedgerEntry::class);
    }

    public function call(): HasOne
    {
        return $this->hasOne(ConsultationCall::class);
    }

    public function isActive(): bool
    {
        return $this->status === ConsultationStatus::Active;
    }

    public function isRequested(): bool
    {
        return $this->status === ConsultationStatus::Requested;
    }

    public function isFinal(): bool
    {
        return $this->status?->isFinal() ?? false;
    }

    /**
     * Chat e cartas pertencem ao atendimento por texto. Chamadas de áudio e
     * vídeo usam a interface de chamada e o histórico de sinais.
     */
    public function allowsMessaging(): bool
    {
        return ($this->modality?->allowsChat() ?? true)
            && ($this->status?->allowsMessaging() ?? false);
    }

    public function requiresCall(): bool
    {
        return $this->modality?->requiresMedia() ?? false;
    }

    /**
     * Preço efetivamente aplicado por minuto. Usa o snapshot gravado no
     * aceite e recorre aos valores congelados de tarifa × crédito quando a
     * coluna ainda não existir (consultas anteriores à migration).
     */
    public function pricePerMinuteCents(): ?int
    {
        if ($this->price_per_minute_cents !== null) {
            return (int) $this->price_per_minute_cents;
        }

        if ($this->rate_credits_per_minute === null || $this->credit_unit_price_cents === null) {
            return null;
        }

        return (int) $this->rate_credits_per_minute * (int) $this->credit_unit_price_cents;
    }

    public function pricePerMinuteLabel(): ?string
    {
        $cents = $this->pricePerMinuteCents();

        return $cents === null ? null : Money::format($cents);
    }

    public function modalityLabel(): string
    {
        return $this->modality?->label() ?? ConsultationModality::Text->label();
    }

    /**
     * Total cobrado conforme a regra vigente: preco snapshot x minutos
     * efetivamente cobrados. Nunca recalcula com precos atuais.
     */
    public function totalChargedCents(): int
    {
        $price = $this->pricePerMinuteCents();

        return $price === null ? 0 : $price * (int) $this->billed_minutes;
    }

    public function totalChargedLabel(): string
    {
        return Money::format($this->totalChargedCents());
    }

    /**
     * Comissao liquida (accruals menos estornos) da consulta.
     */
    public function commissionNetCents(): int
    {
        $entries = $this->relationLoaded('commissionEntries')
            ? $this->commissionEntries
            : $this->commissionEntries()->get();

        return (int) $entries->sum(fn (CommissionLedgerEntry $entry): int => $entry->type === CommissionEntryType::Reversal
            ? -$entry->commission_amount_cents
            : $entry->commission_amount_cents);
    }

    public function hasPendingCall(): bool
    {
        return $this->call !== null && $this->call->status === CallStatus::Ringing;
    }

    /**
     * Duração oficial em segundos. Para consultas em andamento usa o relógio
     * do servidor; consultas que nunca iniciaram retornam null.
     */
    public function durationSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        $end = $this->ended_at ?? now();

        return max(0, $end->getTimestamp() - $this->started_at->getTimestamp());
    }

    /**
     * Rótulo compacto e legível para históricos, sem depender do navegador.
     */
    public function durationLabel(): ?string
    {
        $seconds = $this->durationSeconds();

        if ($seconds === null) {
            return null;
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %02dmin', $hours, $minutes);
        }

        if ($minutes > 0) {
            return sprintf('%dmin %02ds', $minutes, $remainingSeconds);
        }

        return sprintf('%ds', $remainingSeconds);
    }

    /**
     * Quantidade de mensagens recebidas após a última leitura do participante.
     * Mensagens do próprio remetente nunca contam como novas.
     */
    public function unreadMessageCountFor(User $user): int
    {
        $column = $this->readColumnFor($user);

        if ($column === null) {
            return 0;
        }

        $lastReadAt = $this->{$column};

        return $this->messages()
            ->where('sender_id', '!=', $user->getKey())
            ->when(
                $lastReadAt !== null,
                fn ($query) => $query->where('created_at', '>', $lastReadAt),
            )
            ->count();
    }

    /**
     * Registra a leitura do histórico pelo participante autenticado. A coluna
     * é preenchida pelo servidor e não faz parte do mass assignment.
     */
    public function markMessagesReadBy(User $user): void
    {
        $column = $this->readColumnFor($user);

        if ($column === null || ! $this->exists) {
            return;
        }

        $this->forceFill([$column => now()])->saveQuietly();
    }

    private function readColumnFor(User $user): ?string
    {
        return match ($user->getKey()) {
            $this->client_id => 'client_last_read_at',
            $this->attendant_id => 'attendant_last_read_at',
            default => null,
        };
    }

    public function hasParticipant(User $user): bool
    {
        return $user->getKey() === $this->client_id || $user->getKey() === $this->attendant_id;
    }

    public function isClient(User $user): bool
    {
        return $user->getKey() === $this->client_id;
    }

    public function isAttendant(User $user): bool
    {
        return $user->getKey() === $this->attendant_id;
    }
}
