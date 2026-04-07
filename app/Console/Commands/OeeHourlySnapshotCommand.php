<?php

namespace App\Console\Commands;

use App\Http\Controllers\DashboardController;
use Illuminate\Console\Command;

class OeeHourlySnapshotCommand extends Command
{
    protected $signature = 'oee:hourly-snapshot';

    protected $description = 'Snapshot OEE per mesin ke oee_records_hourly dan upsert oee_records (jalankan tiap jam bersama production snapshot)';

    public function handle(DashboardController $dashboard): int
    {
        try {
            $n = $dashboard->captureOeeHourlySnapshot();
            $this->info("OEE snapshot selesai: {$n} mesin.");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('OEE snapshot gagal: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
