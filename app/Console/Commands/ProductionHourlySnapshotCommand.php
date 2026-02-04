<?php

namespace App\Console\Commands;

use App\Models\ProductionData;
use App\Models\ProductionDataHourly;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProductionHourlySnapshotCommand extends Command
{
    protected $signature = 'production:hourly-snapshot';

    protected $description = 'Snapshot quantity dari production_data ke production_data_hourly (dijalankan setiap jam pada menit 58)';

    public function handle(): int
    {
        $snapshotAt = Carbon::now();

        // Ambil mesin unik dari production_data; untuk tiap mesin ambil record terbaru (quantity terakhir)
        $machineNames = ProductionData::query()
            ->select('machine_name')
            ->distinct()
            ->pluck('machine_name');

        $inserted = 0;
        foreach ($machineNames as $machineName) {
            $latest = ProductionData::query()
                ->where('machine_name', $machineName)
                ->orderBy('timestamp', 'desc')
                ->first();

            if (!$latest) {
                continue;
            }

            ProductionDataHourly::create([
                'snapshot_at' => $snapshotAt,
                'machine_name' => $latest->machine_name,
                'line_name' => $latest->line_name ?? null,
                'quantity' => (int) $latest->quantity,
            ]);
            $inserted++;
        }

        $this->info("Snapshot selesai: {$inserted} mesin disimpan untuk {$snapshotAt->format('Y-m-d H:i:s')}.");
        return self::SUCCESS;
    }
}
