<?php

namespace App\Models;

use Database\Factories\TarotDeckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description', 'is_active'])]
class TarotDeck extends Model
{
    /** @use HasFactory<TarotDeckFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function cards(): HasMany
    {
        return $this->hasMany(TarotCard::class, 'deck_id');
    }
}
