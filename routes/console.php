<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * As quatro rotinas são de minuto em minuto e terminam em segundos. O mutex do
 * `withoutOverlapping` vale 10 minutos, não as 24 h do padrão: hospedagem
 * compartilhada mata processo por `max_execution_time`, e um mutex órfão deixaria
 * a cobrança por minuto e a interrupção por inatividade paradas por um dia.
 * A garantia de não duplicar dinheiro continua em `ConsultationBillingManager`
 * (chave idempotente por minuto).
 */
Schedule::command('consultations:expire-requests')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command('consultations:interrupt-disconnected')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command('consultations:charge-minutes')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command('consultations:close-stale-calls')
    ->everyMinute()
    ->withoutOverlapping(10);

/*
 * Tokens Bearer expirados (config sanctum.expiration, padrão 30 dias) ficam na
 * tabela até serem removidos. A limpeza diária evita crescimento indefinido;
 * o comando é idempotente e pode rodar junto do cron já necessário na Hostoo.
 */
Schedule::command('sanctum:prune-expired --hours=24')
    ->daily()
    ->withoutOverlapping(10);

/*
 * Acessos: encerra sessões sem heartbeat recente e apaga o que passou da
 * retenção (`access_log.retention_days`). Roda de madrugada, quando ninguém
 * está navegando, para não competir com as rotinas de consulta.
 */
Schedule::command('arcana:prune-site-visits')
    ->dailyAt('04:15')
    ->withoutOverlapping(60);

/*
 * Backup do banco e do storage a cada seis horas, mantendo um único conjunto
 * (`backup.keep`): quatro execuções por dia, com no máximo um diretório em
 * `storage/backups`. O mutex vale 2 h — folga para um backup grande terminar e
 * curto o bastante para um processo morto não bloquear a execução seguinte.
 * Falha de uma execução não apaga o backup anterior, mas o comando retorna
 * código 1 e o erro aparece no log do scheduler.
 */
Schedule::command('arcana:backup')
    ->everySixHours()
    ->withoutOverlapping(120);
