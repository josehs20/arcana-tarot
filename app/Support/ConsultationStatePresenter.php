<?php

namespace App\Support;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;

/**
 * Estado resumido da consulta para eventos e recuperação após reconexão.
 *
 * Contém apenas dados operacionais. A conversa nunca é incluída neste payload.
 */
final class ConsultationStatePresenter
{
    /**
     * @return array{
     *     id: int,
     *     status: string,
     *     status_label: string,
     *     status_dot: string,
     *     modality: string,
     *     modality_label: string,
     *     price_per_minute_cents: int|null,
     *     price_per_minute_label: string|null,
     *     allows_messaging: bool,
     *     is_final: bool,
     *     ended_reason: string|null,
     *     ended_reason_label: string|null,
     *     started_at: string|null,
     *     ended_at: string|null,
     *     updated_at: string|null
     * }
     */
    public static function toArray(Consultation $consultation): array
    {
        $status = $consultation->status ?? ConsultationStatus::Requested;

        return [
            'id' => (int) $consultation->getKey(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_dot' => match ($status) {
                ConsultationStatus::Requested => 'busy',
                ConsultationStatus::Active => 'online',
                default => 'offline',
            },
            'modality' => $consultation->modality?->value ?? 'text',
            'modality_label' => $consultation->modalityLabel(),
            'price_per_minute_cents' => $consultation->pricePerMinuteCents(),
            'price_per_minute_label' => $consultation->pricePerMinuteLabel(),
            'allows_messaging' => $consultation->allowsMessaging(),
            'is_final' => $status->isFinal(),
            'ended_reason' => $consultation->ended_reason?->value,
            'ended_reason_label' => $consultation->ended_reason?->label(),
            'started_at' => $consultation->started_at?->toIso8601String(),
            'ended_at' => $consultation->ended_at?->toIso8601String(),
            'updated_at' => $consultation->updated_at?->toIso8601String(),
        ];
    }
}
