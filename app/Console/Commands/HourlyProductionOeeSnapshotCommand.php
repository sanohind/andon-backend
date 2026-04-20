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
        $code = Artisan::call('production:hourly-snapshot');
        $this->output->write(Artisan::output());
        if ($code !== 0) {
            return self::FAILURE;
        }

        $code = Artisan::call('oee:hourly-snapshot');
        $this->output->write(Artisan::output());

        return $code === 0 ? self::SUCCESS : self::FAILURE;
    }
}
