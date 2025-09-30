<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Set timezone global untuk Carbon
        Carbon::setLocale('id');
        date_default_timezone_set('Asia/Jakarta');
        
        // Set timezone untuk database connection
        if (config('database.default') === 'pgsql') {
            \DB::statement("SET timezone = 'Asia/Jakarta'");
        }
    }
}
