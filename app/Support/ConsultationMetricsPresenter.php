<?php

namespace App\Support;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\User;

/**
 * Indicadores do atendimento exibidos na tela dedicada.
 *
 * Todo valor é calculado no servidor com os snapshots gravados no aceite:
 * preço por minuto, minutos cobrados, total cobrado e comissão líquida. O
 * navegador apenas formata o contador de tempo (informativo) e troca os
 * rótulos deste payload pelos valores atualizados.
 *
 * A comissão só é exposta ao atendente da consulta; o cliente recebe apenas
 * o que já pode ver na tela da consulta.
 */
final class ConsultationMetricsPresenter
{
    /**
     * @return array{
     *     consultation_id: int,
     *     status: string,
     *     status_label: string,
     *     requested_at: string|null,
     *     requested_at_label: string,
     *     started_at: string|null,
     *     ended_at: string|null,
     *     waiting_seconds: int,
     *     elapsed_seconds: int|null,
     *     elapsed_label: string|null,
     *     billed_minutes: int,
     *     price_per_minute_cents: int|null,
     *     price_per_minute_label: string|null,
     *     charged_cents: int,
     *     charged_label: string,
     *     charged_explanation: string,
     *     commission_cents: int|null,
     *     commission_label: string|null,
     *     commission_rate_label: string|null,
     *     next_refresh_in_seconds: int|null,
     *     updated_at: string|null
     * }
     */
    public static function toArray(Consultation $consultation, User $viewer): array
    {
        $status = $consultation->status ?? ConsultationStatus::Requested;
        $billedMinutes = max(0, (int) $consultation->billed_minutes);
        $pricePerMinuteCents = $consultation->pricePerMinuteCents();
        $chargedCents = $consultation->totalChargedCents();
        $isAttendant = $consultation->isAttendant($viewer);
        $elapsedSeconds = $consultation->durationSeconds();

        $waitingSeconds = 0;

        if ($consultation->created_at !== null) {
            $waitingEnd = $consultation->started_at ?? $consultation->ended_at ?? now();
            $waitingSeconds = max(0, $waitingEnd->getTimestamp() - $consultation->created_at->getTimestamp());
        }

        return [
            'consultation_id' => (int) $consultation->getKey(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'requested_at' => $consultation->created_at?->toIso8601String(),
            'requested_at_label' => $consultation->created_at?->format('d/m/Y H:i') ?? '—',
            'started_at' => $consultation->started_at?->toIso8601String(),
            'ended_at' => $consultation->ended_at?->toIso8601String(),
            'waiting_seconds' => $waitingSeconds,
            'elapsed_seconds' => $elapsedSeconds,
            'elapsed_label' => $consultation->durationLabel(),
            'billed_minutes' => $billedMinutes,
            'price_per_minute_cents' => $pricePerMinuteCents,
            'price_per_minute_label' => $consultation->pricePerMinuteLabel(),
            'charged_cents' => $chargedCents,
            'charged_label' => Money::format($chargedCents),
            'charged_explanation' => $billedMinutes.' '.($billedMinutes === 1 ? 'minuto iniciado' : 'minutos iniciados'),
            'commission_cents' => $isAttendant ? $consultation->commissionNetCents() : null,
            'commission_label' => $isAttendant ? Money::format($consultation->commissionNetCents()) : null,
            'commission_rate_label' => $isAttendant && $consultation->commission_rate_bps !== null
                ? Rate::formatBps($consultation->commission_rate_bps)
                : null,
            'next_refresh_in_seconds' => self::secondsToNextMinute($status, $elapsedSeconds),
            'updated_at' => $consultation->updated_at?->toIso8601String(),
        ];
    }

    /**
     * A cobrança acontece por minuto iniciado e é processada no servidor. O
     * próximo ponto de mudança é o próximo limite de minuto desde `started_at`;
     * a interface busca os números atualizados exatamente nesse instante.
     */
    private static function secondsToNextMinute(ConsultationStatus $status, ?int $elapsedSeconds): ?int
    {
        if ($status !== ConsultationStatus::Active || $elapsedSeconds === null) {
            return null;
        }

        return 60 - ($elapsedSeconds % 60);
    }
}
