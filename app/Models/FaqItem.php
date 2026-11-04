<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Pergunta frequente exibida na home.
 *
 * @property string $question
 * @property string $answer
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable(['question', 'answer', 'is_active', 'sort_order'])]
class FaqItem extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @param  Builder<FaqItem>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
