<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('consultations:expire-requests')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('consultations:interrupt-disconnected')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('consultations:charge-minutes')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('consultations:close-stale-calls')
    ->everyMinute()
    ->withoutOverlapping();
