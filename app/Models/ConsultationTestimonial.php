<?php

namespace App\Models;

use Database\Factories\ConsultationTestimonialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Depoimento deixado pelo cliente depois de um atendimento concluído.
 *
 * O texto é publicado no perfil público do atendente assim que enviado; a
 * administração só pode ocultar (moderação) ou republicar. Não há edição pelo
 * cliente: o depoimento é a fotografia da experiência ao fim da consulta.
 */
#[Fillable(['consultation_id', 'client_id', 'attendant_id', 'rating', 'message'])]
class ConsultationTestimonial extends Model
{
    /** @use HasFactory<ConsultationTestimonialFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_hidden' => 'boolean',
            'hidden_at' => 'datetime',
        ];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function attendant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendant_id');
    }

    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by_id');
    }

    /**
     * Depoimentos visíveis no perfil público.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }

    /**
     * Depoimentos ocultados pela moderação.
     */
    public function scopeHidden(Builder $query): Builder
    {
        return $query->where('is_hidden', true);
    }

    public function isVisible(): bool
    {
        return ! $this->is_hidden;
    }

    /**
     * Nome exibido junto ao depoimento: primeiro nome e inicial do sobrenome.
     *
     * O perfil do atendente é público, então o nome completo e o e-mail do
     * cliente nunca são expostos.
     */
    public function clientDisplayName(): string
    {
        $parts = Str::of((string) ($this->client?->name ?? ''))
            ->squish()
            ->explode(' ')
            ->filter()
            ->values();

        if ($parts->isEmpty()) {
            return 'Cliente';
        }

        $firstName = (string) $parts->first();

        if ($parts->count() === 1) {
            return $firstName;
        }

        $initial = Str::upper(Str::substr((string) $parts->last(), 0, 1));

        return $initial === '' ? $firstName : $firstName.' '.$initial.'.';
    }

    /**
     * Estrelas preenchidas e vazias, para exibição sem depender de JavaScript.
     */
    public function ratingStars(): string
    {
        $filled = max(0, min(5, $this->rating));

        return str_repeat('★', $filled).str_repeat('☆', 5 - $filled);
    }

    /**
     * Descrição acessível da nota, usada por leitores de tela.
     */
    public function ratingLabel(): string
    {
        return $this->rating.' de 5 estrelas';
    }
}
