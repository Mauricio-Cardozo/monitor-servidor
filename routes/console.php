<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Envía las métricas por WebSocket cada 5 segundos (correr: php artisan schedule:work)
Schedule::command('metrics:broadcast')->everyFiveSeconds();
