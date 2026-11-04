<?php

namespace App\Models;

use App\Enums\CallSignalType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sinal WebRTC (oferta SDP, resposta SDP ou candidato ICE).
 *
 * O payload é opaco para o servidor e só pode ser lido pelos participantes da
 * consulta. Não há mídia aqui: apenas a negociação necessária para que os
 * navegadores se conectem diretamente.
 */
#[Fillable(['consultation_id', 'from_user_id', 'type', 'payload'])]
class ConsultationCallSignal extends Model
{
    protected function casts(): array
    {
        return [
            'type' => CallSignalType::class,
            'payload' => 'array',
        ];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }
}
