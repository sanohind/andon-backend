<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Snapshot quantity dari production_data ke production_data_hourly setiap jam pada menit 58
// (Cron tidak mendukung detik; berjalan pada XX:58:00)
Schedule::command('production:hourly-snapshot')->hourlyAt(58);
