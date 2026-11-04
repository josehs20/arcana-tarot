<?php

namespace App\Console\Commands;

use App\Support\ConsultationLifecycleManager;
use Illuminate\Console\Command;

class InterruptDisconnectedConsultations extends Command
{
    protected $signature = 'consultations:interrupt-disconnected';

    protected $description = 'Interrompe consultas ativas sem interação dentro do limite configurado';

    public function handle(ConsultationLifecycleManager $lifecycle): int
    {
        $interrupted = $lifecycle->interruptDisconnectedSessions();

        $this->info("Consultas interrompidas: {$interrupted}.");

        return self::SUCCESS;
    }
}
