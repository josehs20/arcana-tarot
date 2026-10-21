<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Database\Factories\AttendantApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'email', 'phone', 'experience_summary', 'message', 'status', 'reviewed_by', 'review_notes', 'submitted_at', 'reviewed_at'])]
class AttendantApplication extends Model
{
    /** @use HasFactory<AttendantApplicationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
