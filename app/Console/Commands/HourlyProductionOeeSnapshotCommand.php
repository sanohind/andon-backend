<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class HourlyProductionOeeSnapshotCommand extends Command
{
    protected $signature = 'hourly:production-oee-snapshot';

    protected $description = 'Snapshot produksi per jam lalu OEE per jam (urutan tetap: produksi dulu)';

    public function handle(): int
    {
        // 1. Sinkronisasi data produksi dari Odoo untuk mesin NIP2
        $this->info("Menjalankan sinkronisasi Odoo...");
        Artisan::call('odoo:sync-production-hourly');
        $this->output->write(Artisan::output());

        // 1.5. Sinkronisasi data NG dari MongoDB untuk mesin NIP2
        $this->info("Menjalankan sinkronisasi NG dari MongoDB...");
        Artisan::call('mongo:sync-ng-hourly');
        $this->output->write(Artisan::output());

        // 1.6. Sinkronisasi data Downtime dari MongoDB untuk mesin NIP2
        $this->info("Menjalankan sinkronisasi Downtime dari MongoDB...");
        Artisan::call('mongo:sync-downtime-hourly');
        $this->output->write(Artisan::output());

        // 2. Snapshot produksi per jam
        $code = Artisan::call('production:hourly-snapshot');
        $this->output->write(Artisan::output());
        if ($code !== 0) {
            return self::FAILURE;
        }

        // 3. Snapshot OEE per jam
        $code = Artisan::call('oee:hourly-snapshot');
        $this->output->write(Artisan::output());

        return $code === 0 ? self::SUCCESS : self::FAILURE;
    }
}
