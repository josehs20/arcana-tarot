<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Database\Factories\AttendantApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'name',
    'email',
    'phone',
    'city',
    'state',
    'experience_summary',
    'message',
    'specialties',
    'portfolio_url',
    'status',
    'reviewed_by',
    'review_notes',
    'submitted_at',
    'consent_at',
    'status_changed_at',
    'review_started_at',
    'reviewed_at',
])]
#[Hidden(['reviewed_by', 'review_notes'])]
class AttendantApplication extends Model
{
    /** @use HasFactory<AttendantApplicationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'specialties' => 'array',
            'status' => ApplicationStatus::class,
            'submitted_at' => 'datetime',
            'consent_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'review_started_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Histórico de ações administrativas sobre esta candidatura.
     */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'subject')
            ->latest('created_at')
            ->latest('id');
    }

    public function canStartReview(): bool
    {
        return $this->status === ApplicationStatus::Pending;
    }

    public function canBeDecided(): bool
    {
        return in_array($this->status, [ApplicationStatus::Pending, ApplicationStatus::UnderReview], true);
    }

    public function formattedPhone(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->phone) ?? '';

        return match (strlen($digits)) {
            11 => sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7)),
            10 => sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6)),
            default => $this->phone,
        };
    }

    public function location(): ?string
    {
        $parts = array_values(array_filter([$this->city, $this->state]));

        return $parts === [] ? null : implode(' / ', $parts);
    }
}
