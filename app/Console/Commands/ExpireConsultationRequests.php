<?php

namespace App\Console\Commands;

use App\Support\ConsultationLifecycleManager;
use Illuminate\Console\Command;

class ExpireConsultationRequests extends Command
{
    protected $signature = 'consultations:expire-requests';

    protected $description = 'Expira solicitações de consulta que passaram do prazo sem aceite';

    public function handle(ConsultationLifecycleManager $lifecycle): int
    {
        $expired = $lifecycle->expireStaleRequests();

        $this->info("Solicitações expiradas: {$expired}.");

        return self::SUCCESS;
    }
}
