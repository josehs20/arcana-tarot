<?php

namespace App\Models;

use Database\Factories\AttendantProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'display_name', 'bio', 'specialties', 'commission_rate_bps', 'is_published'])]
class AttendantProfile extends Model
{
    /** @use HasFactory<AttendantProfileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'specialties' => 'array',
            'commission_rate_bps' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
