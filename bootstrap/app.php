<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        // Snapshot quantity dari production_data ke production_data_hourly setiap jam pada menit 58
        // (Cron tidak mendukung detik; berjalan pada XX:58:00)
        $schedule->command('production:hourly-snapshot')
            ->hourlyAt(58)
            ->timezone(config('app.timezone', 'Asia/Jakarta'))
            ->withoutOverlapping()
            ->runInBackground();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // Register CORS middleware untuk semua API routes
        $middleware->api(prepend: [
            \App\Http\Middleware\CorsMiddleware::class,
        ]);
        
        // Handle preflight OPTIONS requests
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\CorsMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();