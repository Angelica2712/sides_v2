<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sides:sincronizar-rutas')->hourly()->withoutOverlapping();
Schedule::command('sides:enviar-webhooks')->everyMinute()->withoutOverlapping();
Schedule::command('sides:depurar-auditoria')->dailyAt('03:10')->withoutOverlapping();
