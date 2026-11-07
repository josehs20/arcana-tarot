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
 *
 * O valor cobrado acompanha o relógio sem cálculo no navegador: o minuto já
 * iniciado é projetado aqui (`pending_minutes`/`charge_pending`) enquanto o
 * comando agendado não registra o débito, exatamente como o encerramento
 * projetaria o último minuto. Quando a carteira não cobre a próxima cobrança a
 * projeção para, porque o servidor não vai debitar.
 *
 * O contador regressivo (`remaining_seconds`) também é calculado em dinheiro:
 * minutos debitados + minutos que o saldo ainda paga, menos o tempo já
 * percorrido. Ele zera exatamente quando o servidor encerra a consulta por
 * saldo insuficiente (`ConsultationLifecycleManager::closeIfOutOfCredits`).
 *
 * O razão de comissões só recebe o lançamento no encerramento
 * (`CommissionManager::accrue()`), então durante o atendimento a comissão é
 * **estimada** com a regra do fechamento — taxa snapshot x valor cobrado — e
 * marcada com `commission_is_estimate`. Quando já existe lançamento (consulta
 * encerrada ou estornada), o valor oficial do razão tem precedência.
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
     *     elapsed_minutes: int,
     *     billed_minutes: int,
     *     pending_minutes: int,
     *     charge_pending: bool,
     *     price_per_minute_cents: int|null,
     *     price_per_minute_label: string|null,
     *     charged_cents: int,
     *     charged_label: string,
     *     charged_explanation: string,
     *     commission_cents: int|null,
     *     commission_label: string|null,
     *     commission_is_estimate: bool,
     *     commission_explanation: string|null,
     *     commission_rate_label: string|null,
     *     available_minutes: int|null,
     *     available_label: string|null,
     *     available_explanation: string|null,
     *     balance_cents: int|null,
     *     balance_label: string|null,
     *     balance_explanation: string|null,
     *     remaining_seconds: int|null,
     *     remaining_label: string|null,
     *     remaining_explanation: string|null,
     *     next_refresh_in_seconds: int|null,
     *     server_now: string,
     *     updated_at: string|null
     * }
     */
    public static function toArray(Consultation $consultation, User $viewer): array
    {
        $status = $consultation->status ?? ConsultationStatus::Requested;
        $billedMinutes = max(0, (int) $consultation->billed_minutes);
        $pricePerMinuteCents = $consultation->pricePerMinuteCents();
        $isAttendant = $consultation->isAttendant($viewer);
        $elapsedSeconds = $consultation->durationSeconds();
        $elapsedMinutes = self::elapsedMinutes($elapsedSeconds, $billedMinutes);
        $available = self::availableTime($consultation);
        $charge = self::charge($consultation, $status, $billedMinutes, $elapsedMinutes, $pricePerMinuteCents, $available);
        $balance = self::balance($consultation, $charge['pending_minutes'], $pricePerMinuteCents);
        $commission = self::commission($consultation, $isAttendant, $charge['cents']);
        $remaining = self::remainingTime($available, $elapsedSeconds, $billedMinutes);

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
            'elapsed_minutes' => $elapsedMinutes,
            'billed_minutes' => $billedMinutes,
            'pending_minutes' => $charge['pending_minutes'],
            'charge_pending' => $charge['pending_minutes'] > 0,
            'price_per_minute_cents' => $pricePerMinuteCents,
            'price_per_minute_label' => $consultation->pricePerMinuteLabel(),
            'charged_cents' => $charge['cents'],
            'charged_label' => Money::format($charge['cents']),
            'charged_explanation' => $charge['explanation'],
            'commission_cents' => $commission['cents'],
            'commission_label' => $commission['label'],
            'commission_is_estimate' => $commission['is_estimate'],
            'commission_explanation' => $commission['explanation'],
            'commission_rate_label' => $commission['rate_label'],
            'available_minutes' => $available['minutes'],
            'available_label' => $available['label'],
            'available_explanation' => $available['explanation'],
            'balance_cents' => $balance['cents'],
            'balance_label' => $balance['label'],
            'balance_explanation' => $balance['explanation'],
            'remaining_seconds' => $remaining['seconds'],
            'remaining_label' => $remaining['label'],
            'remaining_explanation' => $remaining['explanation'],
            'next_refresh_in_seconds' => self::secondsToNextMinute($status, $elapsedSeconds),
            'server_now' => now()->toIso8601String(),
            'updated_at' => $consultation->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Minutos iniciados pelo relógio do servidor.
     *
     * É a mesma conta do `ConsultationBillingManager::billDue()` (`max(1,
     * ceil(elapsed / 60))`): o primeiro minuto é cobrado no aceite e o último no
     * encerramento. Serve para a tela mostrar o minuto que já começou antes de o
     * comando agendado registrar o débito.
     */
    private static function elapsedMinutes(?int $elapsedSeconds, int $billedMinutes): int
    {
        if ($elapsedSeconds === null) {
            return $billedMinutes;
        }

        return max(1, (int) ceil($elapsedSeconds / 60));
    }

    /**
     * Valor cobrado exibido na tela: razão de créditos + minuto em andamento.
     *
     * `billed_minutes` só avança quando o débito é registrado, então entre o
     * limite do minuto e a execução de `consultations:charge-minutes` o cartão
     * ficaria parado. A projeção é feita aqui, no servidor, e marcada com
     * `pending_minutes`; `available_minutes` impede prometer um minuto que a
     * carteira não cobre (a cobrança para e a consulta é interrompida).
     *
     * @param  array{minutes: int|null, label: string|null, explanation: string|null}  $available
     * @return array{cents: int, pending_minutes: int, explanation: string}
     */
    private static function charge(
        Consultation $consultation,
        ConsultationStatus $status,
        int $billedMinutes,
        int $elapsedMinutes,
        ?int $pricePerMinuteCents,
        array $available,
    ): array {
        $billedCents = $consultation->totalChargedCents();

        if ($status !== ConsultationStatus::Active || $pricePerMinuteCents === null || $pricePerMinuteCents <= 0) {
            return [
                'cents' => $billedCents,
                'pending_minutes' => 0,
                'explanation' => self::minutesExplanation($billedMinutes),
            ];
        }

        $canBillNextMinute = ($available['minutes'] ?? 0) >= 1;
        // A projeção para no que a carteira paga: com o comando de cobrança
        // atrasado, `elapsed_minutes` pode passar de `billed + available` e o
        // cartão prometeria minutos que o servidor nunca vai debitar.
        $projectedMinutes = min(
            $billedMinutes + max(0, (int) $available['minutes']),
            max($billedMinutes, $elapsedMinutes),
        );
        $displayMinutes = $canBillNextMinute ? $projectedMinutes : $billedMinutes;
        $pendingMinutes = max(0, $displayMinutes - $billedMinutes);

        return [
            'cents' => $displayMinutes * $pricePerMinuteCents,
            'pending_minutes' => $pendingMinutes,
            'explanation' => self::minutesExplanation($displayMinutes)
                .($pendingMinutes > 0 ? ' · cobrança em processamento' : ''),
        ];
    }

    private static function minutesExplanation(int $minutes): string
    {
        return $minutes.' '.($minutes === 1 ? 'minuto iniciado' : 'minutos iniciados');
    }

    /**
     * Contagem regressiva até o saldo do cliente acabar.
     *
     * A conta é feita em dinheiro, não no relógio: os minutos já debitados
     * (`billed_minutes`, pagos desde o aceite) mais os que a carteira ainda paga
     * (`available_minutes`) formam o tempo comprado. Descontando o que já passou
     * desde `started_at`:
     *
     *   (minutos pagos + minutos que o saldo paga) × 60 − segundos decorridos
     *
     * O contador chega a zero exatamente quando não há mais minuto pago em
     * andamento nem saldo para o próximo — a mesma condição que o servidor usa
     * para encerrar a consulta (`ConsultationLifecycleManager::
     * closeIfOutOfCredits`). Contar apenas o que falta para o próximo limite de
     * minuto prendia a tela em `00:00` enquanto o comando de cobrança estava
     * atrasado e ainda havia saldo para continuar.
     *
     * @param  array{minutes: int|null, label: string|null, explanation: string|null}  $available
     * @return array{seconds: int|null, label: string|null, explanation: string|null}
     */
    private static function remainingTime(array $available, ?int $elapsedSeconds, int $billedMinutes): array
    {
        if ($available['minutes'] === null) {
            return ['seconds' => null, 'label' => null, 'explanation' => null];
        }

        $coveredSeconds = ($billedMinutes + $available['minutes']) * 60;
        $seconds = max(0, $coveredSeconds - max(0, (int) $elapsedSeconds));

        return [
            'seconds' => $seconds,
            'label' => self::clockLabel($seconds),
            'explanation' => $available['explanation'],
        ];
    }

    private static function clockLabel(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $rest);
        }

        return sprintf('%02d:%02d', $minutes, $rest);
    }

    /**
     * Comissão exibida na tela do atendimento.
     *
     * O razão de comissões só é alimentado no encerramento, então uma consulta
     * em andamento renderizava R$ 0,00 mesmo com minutos já cobrados. Enquanto
     * não existe lançamento, o valor é estimado com a mesma regra do
     * fechamento: taxa snapshot x base cobrada (`billed_minutes` só avança
     * depois do débito registrado, então a base acompanha o razão de créditos).
     *
     * A estimativa nunca substitui um lançamento existente — um estorno total,
     * por exemplo, continua aparecendo como R$ 0,00 com `is_estimate` falso.
     *
     * @return array{cents: int|null, label: string|null, is_estimate: bool, explanation: string|null, rate_label: string|null}
     */
    private static function commission(Consultation $consultation, bool $isAttendant, int $chargedCents): array
    {
        if (! $isAttendant || $consultation->commission_rate_bps === null) {
            return [
                'cents' => null,
                'label' => null,
                'is_estimate' => false,
                'explanation' => null,
                'rate_label' => null,
            ];
        }

        $rateLabel = Rate::formatBps($consultation->commission_rate_bps);

        $consultation->loadMissing('commissionEntries');

        if ($consultation->commissionEntries->isNotEmpty()) {
            $creditedCents = $consultation->commissionNetCents();

            return [
                'cents' => $creditedCents,
                'label' => Money::format($creditedCents),
                'is_estimate' => false,
                'explanation' => $rateLabel.' — valor consolidado no encerramento.',
                'rate_label' => $rateLabel,
            ];
        }

        $estimatedCents = (new CommissionCalculator)->calculate(
            $chargedCents,
            $consultation->commission_rate_bps,
        );

        return [
            'cents' => $estimatedCents,
            'label' => Money::format($estimatedCents),
            'is_estimate' => true,
            'explanation' => $rateLabel.' de '.Money::format($chargedCents).' — estimativa até o encerramento.',
            'rate_label' => $rateLabel,
        ];
    }

    /**
     * Quanto tempo de atendimento o saldo atual do cliente ainda cobre.
     *
     * É uma estimativa para a tela: usa o preço por minuto gravado no aceite e
     * o saldo da carteira no momento da consulta. A cobrança real continua
     * sendo feita pelo servidor, minuto a minuto, debitando a carteira.
     *
     * @return array{minutes: int|null, label: string|null, explanation: string|null}
     */
    public static function availableTime(Consultation $consultation): array
    {
        $pricePerMinuteCents = $consultation->pricePerMinuteCents();

        if ($pricePerMinuteCents === null || $pricePerMinuteCents <= 0) {
            return ['minutes' => null, 'label' => null, 'explanation' => null];
        }

        $consultation->loadMissing('client.wallet');
        $balanceCents = max(0, (int) ($consultation->client?->wallet?->balance_cents ?? 0));
        $minutes = intdiv($balanceCents, $pricePerMinuteCents);

        return [
            'minutes' => $minutes,
            'label' => $minutes.' '.($minutes === 1 ? 'minuto' : 'minutos'),
            'explanation' => 'Saldo de '.Money::format($balanceCents).' ÷ '.Money::format($pricePerMinuteCents).' por minuto.',
        ];
    }

    /**
     * Saldo de créditos exibido na tela, já abatido o minuto em andamento.
     *
     * O indicador do topo acompanha o dinheiro em vez do relógio: mostra quanto
     * ainda resta na carteira do cliente e cai conforme o tempo percorrido.
     * Como a cobrança é por minuto iniciado, o valor desconta os
     * `pending_minutes` — assim ele anda junto com o relógio sem esperar o
     * comando de cobrança e não volta a subir quando o débito é registrado.
     *
     * @return array{cents: int|null, label: string|null, explanation: string|null}
     */
    private static function balance(Consultation $consultation, int $pendingMinutes, ?int $pricePerMinuteCents): array
    {
        $consultation->loadMissing('client.wallet');

        $wallet = $consultation->client?->wallet;

        if ($wallet === null) {
            return ['cents' => null, 'label' => null, 'explanation' => null];
        }

        $pendingCents = max(0, $pendingMinutes) * max(0, (int) $pricePerMinuteCents);
        $cents = max(0, (int) $wallet->balance_cents - $pendingCents);
        $priceLabel = $pricePerMinuteCents === null || $pricePerMinuteCents <= 0
            ? null
            : Money::format($pricePerMinuteCents).' por minuto';

        return [
            'cents' => $cents,
            'label' => Money::format($cents),
            'explanation' => match (true) {
                $priceLabel === null => 'Saldo da carteira.',
                $pendingMinutes > 0 => 'Já descontado o minuto em andamento · '.$priceLabel.'.',
                default => 'Saldo da carteira · '.$priceLabel.'.',
            },
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
