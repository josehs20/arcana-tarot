<?php

namespace App\Support;

use App\Models\ConsultationCall;
use App\Models\ConsultationCallSignal;
use Carbon\CarbonInterface;

/**
 * Serializacao publica da chamada para a interface e para o broadcast.
 *
 * Nao expoe dados internos de auditoria; apenas o estado necessario para os
 * dois participantes sincronizarem os controles.
 */
final class ConsultationCallPresenter
{
    /**
     * @return array<string, mixed>|null
     */
    public static function toArray(?ConsultationCall $call): ?array
    {
        if ($call === null) {
            return null;
        }

        return [
            'id' => (int) $call->getKey(),
            'consultation_id' => (int) $call->consultation_id,
            'status' => $call->status?->value,
            'status_label' => $call->status?->label(),
            'modality' => $call->modality?->value,
            'modality_label' => $call->modality?->label(),
            'requires_video' => $call->requiresVideo(),
            'requested_by_id' => (int) $call->requested_by_id,
            'accepted_at' => self::iso($call->accepted_at),
            'connected_at' => self::iso($call->connected_at),
            'ended_at' => self::iso($call->ended_at),
            'ended_reason' => $call->ended_reason?->value,
            'ended_reason_label' => $call->ended_reason?->label(),
            'is_final' => $call->status?->isFinal() ?? true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function signal(ConsultationCallSignal $signal): array
    {
        return [
            'id' => (int) $signal->getKey(),
            'type' => $signal->type?->value,
            'from_user_id' => (int) $signal->from_user_id,
            'payload' => $signal->payload,
            'created_at' => self::iso($signal->created_at),
        ];
    }

    private static function iso(?CarbonInterface $value): ?string
    {
        return $value?->toIso8601String();
    }
}
