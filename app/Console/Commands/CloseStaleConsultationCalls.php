<?php

namespace App\Console\Commands;

use App\Support\ConsultationCallManager;
use Illuminate\Console\Command;

/**
 * Encerra chamadas que ficaram tocando sem aceite.
 *
 * A consulta correspondente e finalizada como `call_failed`, impedindo que
 * uma chamada abandonada continue gerando cobranca por minuto.
 */
class CloseStaleConsultationCalls extends Command
{
    protected $signature = 'consultations:close-stale-calls';

    protected $description = 'Encerra chamadas de audio/video sem resposta e finaliza as consultas';

    public function handle(ConsultationCallManager $calls): int
    {
        $closed = $calls->expireStaleCalls();

        $this->info(sprintf('Chamadas sem resposta encerradas: %d.', $closed));

        return self::SUCCESS;
    }
}
