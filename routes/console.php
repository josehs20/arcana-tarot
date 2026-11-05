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
