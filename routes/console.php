<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Carbon\Carbon;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Snapshot quantity dari production_data ke production_data_hourly setiap jam pada menit 00
Schedule::command('production:hourly-snapshot')
    ->hourlyAt(0)
    ->timezone(config('app.timezone', 'Asia/Jakarta'))
    ->withoutOverlapping()
    ->runInBackground();

// Terapkan schedule ke inspection_tables:
// - Shift pagi: jalan tiap jam dalam window jam 07:00–19:59
// - Shift malam: jalan tiap jam dalam window jam 21:00–06:59
Schedule::command('schedule:apply-daily', ['--shift' => 'pagi'])
    ->hourly()
    ->timezone(config('app.timezone', 'Asia/Jakarta'))
    ->when(function () {
        $now = Carbon::now(config('app.timezone', 'Asia/Jakarta'));
        $hour = (int) $now->format('H');
        // Window shift pagi: 07:00–19:59
        return $hour >= 7 && $hour < 20;
    })
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('schedule:apply-daily', ['--shift' => 'malam'])
    ->hourly()
    ->timezone(config('app.timezone', 'Asia/Jakarta'))
    ->when(function () {
        $now = Carbon::now(config('app.timezone', 'Asia/Jakarta'));
        $hour = (int) $now->format('H');
        // Window shift malam: 21:00–06:59
        return $hour >= 21 || $hour < 7;
    })
    ->withoutOverlapping()
    ->runInBackground();

