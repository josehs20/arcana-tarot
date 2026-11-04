<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Cobrança por minuto iniciado de uma consulta.
 *
 * Regras (ver docs/TIME_BILLING.md):
 * - o relógio é `consultations.started_at`, sempre do servidor;
 * - cada minuto iniciado custa `rate_credits_per_minute × credit_unit_price_cents`
 *   usando os snapshots gravados no aceite;
 * - o primeiro minuto é cobrado no aceite e o último no encerramento;
 * - cada intervalo possui uma chave idempotente `consultation:{id}:minute:{n}`;
 * - não há débito parcial nem saldo negativo: o primeiro minuto sem saldo
 *   interrompe a cobrança e sinaliza `unpaidMinute` para encerramento.
 */
final class ConsultationBillingManager
{
    public function __construct(private readonly CreditWalletManager $wallets) {}

    /**
     * Cobra todos os minutos vencidos ainda não registrados.
     *
     * A consulta é travada e o cursor é atualizado na mesma transação dos
     * débitos. Reexecutar a rotina não duplica lançamentos.
     */
    public function billDue(Consultation $consultation, ?CarbonInterface $now = null): ConsultationBillingResult
    {
        return DB::transaction(function () use ($consultation, $now): ConsultationBillingResult {
            $locked = Consultation::query()
                ->lockForUpdate()
                ->findOrFail($consultation->getKey());

            $billedMinutes = max(0, (int) $locked->billed_minutes);
            $startedAt = $locked->started_at;
            $rate = $locked->rate_credits_per_minute;
            $unitPriceCents = $locked->credit_unit_price_cents;

            /*
             * O preco congelado no aceite e a fonte da cobranca. Consultas
             * anteriores a modalidade nao possuem a coluna preenchida e usam
             * o mesmo snapshot em creditos x valor do credito.
             */
            $pricePerMinuteCents = $locked->price_per_minute_cents;

            if ($pricePerMinuteCents === null && $rate !== null && $unitPriceCents !== null) {
                $pricePerMinuteCents = $rate * $unitPriceCents;
            }

            if ($startedAt === null || $pricePerMinuteCents === null || $pricePerMinuteCents <= 0) {
                return new ConsultationBillingResult(
                    dueMinutes: $billedMinutes,
                    billedMinutes: $billedMinutes,
                    chargedMinutes: 0,
                    chargedCents: 0,
                    unpaidMinute: null,
                    balanceAfterCents: null,
                );
            }

            $effectiveEnd = $locked->ended_at ?? $now ?? now();
            $elapsedSeconds = max(0, $effectiveEnd->getTimestamp() - $startedAt->getTimestamp());
            $dueMinutes = max(1, (int) ceil($elapsedSeconds / 60));

            if ($dueMinutes <= $billedMinutes) {
                return new ConsultationBillingResult(
                    dueMinutes: $dueMinutes,
                    billedMinutes: $billedMinutes,
                    chargedMinutes: 0,
                    chargedCents: 0,
                    unpaidMinute: null,
                    balanceAfterCents: null,
                );
            }

            $client = User::query()->findOrFail($locked->client_id);
            $chargedMinutes = 0;
            $unpaidMinute = null;
            $balanceAfterCents = null;

            for ($minute = $billedMinutes + 1; $minute <= $dueMinutes; $minute++) {
                try {
                    $entry = $this->wallets->debit(
                        user: $client,
                        amountCents: $pricePerMinuteCents,
                        idempotencyKey: $this->idempotencyKey($locked, $minute),
                        reference: $locked,
                        reason: 'Consulta #'.$locked->getKey().' — minuto '.$minute,
                    );
                } catch (InsufficientCreditBalanceException $exception) {
                    $unpaidMinute = $minute;
                    $balanceAfterCents = $exception->availableCents;

                    break;
                }

                $chargedMinutes++;
                $balanceAfterCents = $entry->balance_after_cents;
            }

            $newBilledMinutes = $billedMinutes + $chargedMinutes;

            if ($chargedMinutes > 0) {
                // Query Builder evita alterar `updated_at` e disparar eventos
                // de model a cada minuto; o cursor é um dado operacional.
                DB::table('consultations')
                    ->where('id', $locked->getKey())
                    ->update(['billed_minutes' => $newBilledMinutes]);

                $locked->setAttribute('billed_minutes', $newBilledMinutes);
            }

            return new ConsultationBillingResult(
                dueMinutes: $dueMinutes,
                billedMinutes: $newBilledMinutes,
                chargedMinutes: $chargedMinutes,
                chargedCents: $chargedMinutes * $pricePerMinuteCents,
                unpaidMinute: $unpaidMinute,
                balanceAfterCents: $balanceAfterCents,
            );
        });
    }

    /**
     * Chave determinística por consulta + intervalo. Reutiliza exatamente a
     * convenção documentada em docs/WALLET_LEDGER.md.
     */
    public function idempotencyKey(Consultation $consultation, int $minute): string
    {
        return 'consultation:'.$consultation->getKey().':minute:'.$minute;
    }
}
