<?php

namespace App\Console\Commands;

use App\Models\SiteVisit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Higiene da tabela de acessos.
 *
 * Duas tarefas independentes:
 * 1. encerra visitas sem sinal de atividade há mais que `inactivity_minutes`,
 *    gravando em `ended_at` o último instante conhecido (nunca "agora", que
 *    inflaria a duração de quem apenas fechou o navegador);
 * 2. apaga visitas fora da janela de retenção — a tela "Acessos" existe para
 *    acompanhar tráfego recente, não para manter histórico permanente.
 *
 * O comando é idempotente e pode ser executado manualmente sem risco.
 */
class PruneSiteVisits extends Command
{
    protected $signature = 'arcana:prune-site-visits';

    protected $description = 'Encerra visitas abandonadas e apaga acessos além do prazo de retenção';

    public function handle(): int
    {
        $inactivityMinutes = max(1, (int) config('access_log.inactivity_minutes', 30));
        $retentionDays = max(1, (int) config('access_log.retention_days', 90));

        $closed = SiteVisit::query()
            ->whereNull('ended_at')
            ->where('last_activity_at', '<', now()->subMinutes($inactivityMinutes))
            ->update(['ended_at' => DB::raw('last_activity_at')]);

        $purged = SiteVisit::query()
            ->where('last_activity_at', '<', now()->subDays($retentionDays))
            ->delete();

        $this->info(sprintf(
            'Visitas encerradas: %d. Visitas expurgadas (%d dias): %d.',
            $closed,
            $retentionDays,
            $purged,
        ));

        return self::SUCCESS;
    }
}
