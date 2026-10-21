<?php

namespace App\Models;

use Database\Factories\TarotCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deck_id', 'name', 'slug', 'sort_order', 'meaning_upright', 'meaning_reversed', 'image_path', 'is_active'])]
class TarotCard extends Model
{
    /** @use HasFactory<TarotCardFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function deck(): BelongsTo
    {
        return $this->belongsTo(TarotDeck::class, 'deck_id');
    }
}
