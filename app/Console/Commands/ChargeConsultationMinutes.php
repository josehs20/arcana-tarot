<?php

namespace App\Console\Commands;

use App\Enums\ConsultationEndReason;
use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Support\ConsultationBillingManager;
use App\Support\ConsultationLifecycleManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cobra minutos vencidos de consultas ativas.
 *
 * A rotina é idempotente e pode atrasar ou repetir sem cobrar duas vezes o
 * mesmo intervalo. Quando não há saldo, a consulta é encerrada com motivo
 * `out_of_credits` e não ocorre débito parcial.
 */
class ChargeConsultationMinutes extends Command
{
    protected $signature = 'consultations:charge-minutes';

    protected $description = 'Cobra os minutos iniciados das consultas ativas e encerra as sem saldo';

    public function handle(
        ConsultationBillingManager $billing,
        ConsultationLifecycleManager $lifecycle,
    ): int {
        $ids = Consultation::query()
            ->where('status', ConsultationStatus::Active->value)
            ->whereNotNull('started_at')
            ->orderBy('id')
            ->pluck('id');

        $chargedMinutes = 0;
        $closed = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $consultation = Consultation::query()->find($id);

            if ($consultation === null || $consultation->status !== ConsultationStatus::Active) {
                continue;
            }

            try {
                $result = $billing->billDue($consultation);
                $chargedMinutes += $result->chargedMinutes;

                if ($result->hasUnpaidMinute()) {
                    $ended = $lifecycle->endBySystem($consultation, ConsultationEndReason::OutOfCredits);

                    if ($ended->status === ConsultationStatus::Ended) {
                        $closed++;
                    }
                }
            } catch (Throwable $exception) {
                $failed++;
                report($exception);

                $this->warn("Falha ao cobrar a consulta #{$id}: {$exception->getMessage()}");
            }
        }

        $this->info(sprintf(
            'Cobrança por minuto: %d minuto(s) cobrado(s), %d consulta(s) encerrada(s), %d falha(s).',
            $chargedMinutes,
            $closed,
            $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
