<?php

namespace App\Console\Commands;

use App\Http\Controllers\DashboardController;
use Illuminate\Console\Command;

class FiveMinuteProductionOeeSnapshotCommand extends Command
{
    protected $signature = 'production-oee:five-minute-snapshot';

    protected $description = 'Snapshot produksi (pcs), ideal qty dashboard, dan OEE per mesin setiap 5 menit ke production_oee_snapshots_five_minute (terpisah dari snapshot per jam)';

    public function handle(DashboardController $dashboard): int
    {
        try {
            $n = $dashboard->captureProductionOeeFiveMinuteSnapshot();
            $this->info("Snapshot 5 menit selesai: {$n} mesin.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Snapshot 5 menit gagal: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
