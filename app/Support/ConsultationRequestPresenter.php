<?php

namespace App\Support;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;

/**
 * Cartão de uma solicitação pendente para o painel do atendente.
 *
 * Alimenta tanto o evento em tempo real (`ConsultationRequested`, no canal
 * privado do usuário) quanto a recuperação por HTTP usada quando o navegador
 * estava desconectado no momento do pedido. As URLs são apenas referências:
 * cada ação revalida participação, papel e estado no servidor.
 */
final class ConsultationRequestPresenter
{
    /**
     * @return array{
     *     id: int,
     *     status: string,
     *     status_label: string,
     *     client_name: string,
     *     attendant_name: string,
     *     modality: string,
     *     modality_label: string,
     *     price_per_minute_cents: int|null,
     *     price_per_minute_label: string|null,
     *     requested_at: string|null,
     *     requested_at_label: string,
     *     waiting_seconds: int,
     *     show_url: string,
     *     workspace_url: string,
     *     accept_url: string,
     *     reject_url: string
     * }
     */
    public static function toArray(Consultation $consultation): array
    {
        $consultation->loadMissing(['client', 'attendant.attendantProfile']);

        $status = $consultation->status ?? ConsultationStatus::Requested;
        $requestedAt = $consultation->created_at;

        return [
            'id' => (int) $consultation->getKey(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'client_name' => $consultation->client?->name ?? 'Cliente',
            'attendant_name' => $consultation->attendant?->attendantProfile?->display_name
                ?? $consultation->attendant?->name
                ?? 'Atendente',
            'modality' => $consultation->modality?->value ?? 'text',
            'modality_label' => $consultation->modalityLabel(),
            'price_per_minute_cents' => $consultation->pricePerMinuteCents(),
            'price_per_minute_label' => $consultation->pricePerMinuteLabel(),
            'requested_at' => $requestedAt?->toIso8601String(),
            'requested_at_label' => $requestedAt?->format('d/m/Y H:i') ?? '—',
            'waiting_seconds' => $requestedAt === null
                ? 0
                : max(0, now()->getTimestamp() - $requestedAt->getTimestamp()),
            'show_url' => route('consultations.show', $consultation),
            'workspace_url' => route('consultations.workspace', $consultation),
            'accept_url' => route('attendant.consultations.accept', $consultation),
            'reject_url' => route('attendant.consultations.reject', $consultation),
        ];
    }
}
