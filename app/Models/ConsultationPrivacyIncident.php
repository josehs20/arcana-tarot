<?php

namespace App\Models;

use App\Enums\PrivacyIncidentStatus;
use App\Enums\SensitiveDataCategory;
use Database\Factories\ConsultationPrivacyIncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'consultation_id',
    'user_id',
    'category',
    'status',
    'detected_types',
    'masked_excerpt',
    'body_original',
    'reviewed_by',
    'reviewed_at',
    'review_notes',
    'ip_address',
])]
class ConsultationPrivacyIncident extends Model
{
    /** @use HasFactory<ConsultationPrivacyIncidentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => SensitiveDataCategory::class,
            'status' => PrivacyIncidentStatus::class,
            'detected_types' => 'array',
            'body_original' => 'encrypted',
            'reviewed_at' => 'datetime',
        ];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Rótulos únicos das categorias detectadas na ocorrência.
     *
     * @return list<string>
     */
    public function detectedLabels(): array
    {
        $types = is_array($this->detected_types) ? $this->detected_types : [];

        $labels = [];

        foreach ($types as $type) {
            $label = is_array($type) ? ($type['label'] ?? null) : null;

            if (is_string($label) && $label !== '' && ! in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    public function detectedCategoryValues(): array
    {
        $types = is_array($this->detected_types) ? $this->detected_types : [];

        $values = [];

        foreach ($types as $type) {
            $value = is_array($type) ? ($type['category'] ?? null) : null;

            if (is_string($value) && $value !== '' && ! in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    public function isOpen(): bool
    {
        return $this->status?->isOpen() ?? true;
    }

    public function statusLabel(): string
    {
        return $this->status?->label() ?? 'Bloqueada';
    }

    public function categoryLabel(): string
    {
        return $this->category?->label() ?? 'Dado pessoal';
    }
}
