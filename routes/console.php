<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Snapshot quantity dari production_data ke production_data_hourly setiap jam pada menit 58
Schedule::command('production:hourly-snapshot')
    ->hourlyAt(59)
    ->timezone(config('app.timezone', 'Asia/Jakarta'))
    ->withoutOverlapping()
    ->runInBackground();

// Reset / nonaktifkan OT setiap hari jam 07:00
Schedule::command('production:reset-ot-daily')
    ->dailyAt('07:00')
    ->timezone(config('app.timezone', 'Asia/Jakarta'))
    ->withoutOverlapping()
    ->runInBackground();

