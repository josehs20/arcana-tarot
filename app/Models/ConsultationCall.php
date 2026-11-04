<?php

namespace App\Models;

use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Enums\ConsultationModality;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chamada de voz/vídeo vinculada a uma consulta ativa.
 *
 * Existe no máximo uma chamada por consulta (índice único em
 * consultation_id), o que torna a solicitação idempotente e impede duas
 * sessões de mídia concorrentes para o mesmo atendimento.
 */
#[Fillable(['consultation_id', 'requested_by_id', 'modality', 'status', 'ended_reason', 'accepted_at', 'connected_at', 'ended_at'])]
class ConsultationCall extends Model
{
    protected function casts(): array
    {
        return [
            'modality' => ConsultationModality::class,
            'status' => CallStatus::class,
            'ended_reason' => CallEndReason::class,
            'accepted_at' => 'datetime',
            'connected_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function isOpen(): bool
    {
        return $this->status?->isOpen() ?? false;
    }

    public function requiresVideo(): bool
    {
        return $this->modality?->requiresVideo() ?? false;
    }
}
