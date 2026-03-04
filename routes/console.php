<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Snapshot quantity dari production_data ke production_data_hourly setiap jam pada menit 00
Schedule::command('production:hourly-snapshot')
    ->hourlyAt(0)
    ->timezone(config('app.timezone', 'Asia/Jakarta'))
    ->withoutOverlapping()
    ->runInBackground();

// Terapkan schedule ke inspection_tables: shift pagi jam 00:05, shift malam jam 16:05
Schedule::command('schedule:apply-daily', ['--shift' => 'pagi'])
    ->dailyAt('07:00')
    ->timezone(config('app.timezone', 'Asia/Jakarta'))
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('schedule:apply-daily', ['--shift' => 'malam'])
    ->dailyAt('21:00')
    ->timezone(config('app.timezone', 'Asia/Jakarta'))
    ->withoutOverlapping()
    ->runInBackground();

