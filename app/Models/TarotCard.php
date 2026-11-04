<?php

namespace App\Models;

use Database\Factories\TarotCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'deck_id',
    'name',
    'slug',
    'sort_order',
    'meaning_upright',
    'meaning_reversed',
    'image_path',
    'image_mime',
    'image_size',
    'image_checksum',
    'is_active',
])]
class TarotCard extends Model
{
    /** @use HasFactory<TarotCardFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'image_size' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function deck(): BelongsTo
    {
        return $this->belongsTo(TarotDeck::class, 'deck_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Indica se o caminho gravado aponta para um arquivo realmente presente no
     * disco privado. Registros antigos podem ter caminhos órfãos.
     */
    public function hasStoredImage(): bool
    {
        return filled($this->image_path)
            && Storage::disk((string) config('tarot.image_disk'))->exists($this->image_path);
    }

    /**
     * Cartas elegíveis para novos envios: ativas e de baralhos ativos.
     */
    public function scopeAvailableForSelection(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereHas('deck', fn (Builder $deck) => $deck->where('is_active', true));
    }
}
