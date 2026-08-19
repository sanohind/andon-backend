<?php

namespace App\Console\Commands;

use App\Models\InspectionTable;
use App\Models\ProductionData;
use App\Models\ProductionDataHourly;
use App\Support\ProductionShiftInfo;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncOdooProductionHourlyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'odoo:sync-production-hourly 
                            {--date= : Tanggal spesifik YYYY-MM-DD (default: shift berjalan)}
                            {--full-day : Gunakan rentang 1 hari penuh (00:00:00 - 23:59:59) bukan shift}
                            {--dry-run : Menampilkan hasil query tanpa menyimpan ke database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sinkronisasi output produksi per jam dari database Odoo (pgsql2) untuk mesin NIP2 (262, 263, 264)';

    /**
     * Pemetaan Line ID Odoo ke Identitas Mesin di Sistem Andon
     */
    protected const LINE_MAPPING = [
        262 => 'NIP2 01',
        263 => 'NIP2 02',
        264 => 'NIP2 03',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $appTimezone = config('app.timezone', 'Asia/Jakarta');
        $now = Carbon::now($appTimezone);
        $connectionName = config('database.connections.pgsql2') ? 'pgsql2' : env('DB_CONNECTION2', 'pgsql2');

        $this->info("Memulai sinkronisasi data produksi Odoo [Connection: {$connectionName}]...");

        // 1. Tentukan rentang waktu query (UTC untuk Odoo actual_date)
        $specificDate = $this->option('date');
        $isFullDay = (bool) $this->option('full-day');
        $isDryRun = (bool) $this->option('dry-run');

        if ($specificDate) {
            $parsedDate = Carbon::parse($specificDate, $appTimezone);
            $startUtc = $parsedDate->copy()->startOfDay()->utc();
            $endUtc = $parsedDate->copy()->endOfDay()->utc();
            $this->info("Mode Tanggal Spesifik: {$parsedDate->format('Y-m-d')} (UTC: {$startUtc} s/d {$endUtc})");
        } elseif ($isFullDay) {
            $startUtc = $now->copy()->startOfDay()->utc();
            $endUtc = $now->copy()->endOfDay()->utc();
            $this->info("Mode 1 Hari Penuh: {$now->format('Y-m-d')} (UTC: {$startUtc} s/d {$endUtc})");
        } else {
            // Mode Default: Mengikuti pola Shift Aktif Andon
            $shiftInfo = ProductionShiftInfo::resolve($now, $appTimezone);
            $shiftStartWib = $shiftInfo['shiftStart'];
            $startUtc = $shiftStartWib->copy()->utc();
            $endUtc = $now->copy()->utc();
            $this->info("Mode Shift [{$shiftInfo['shift']}]: {$shiftStartWib->format('Y-m-d H:i:s')} WIB s/d {$now->format('Y-m-d H:i:s')} WIB (UTC: {$startUtc} s/d {$endUtc})");
        }

        // 2. Query data dari Odoo
        $lineIds = array_keys(self::LINE_MAPPING);
        $lineIdsPlaceholder = implode(',', $lineIds);

        try {
            $query = "
                SELECT 
                    pr.line_id, 
                    COALESCE(SUM(rl.qty_finish), 0) AS total_output
                FROM public.rail_line rl
                JOIN public.pramadya_rail pr ON pr.id = rl.chutter_id
                WHERE rl.actual_date BETWEEN :startDate AND :endDate
                  AND pr.line_id IN ({$lineIdsPlaceholder})
                GROUP BY pr.line_id
            ";

            $results = DB::connection($connectionName)->select($query, [
                'startDate' => $startUtc->format('Y-m-d H:i:s'),
                'endDate' => $endUtc->format('Y-m-d H:i:s'),
            ]);

            // Index hasil berdasarkan line_id
            $outputByLine = [];
            foreach ($results as $row) {
                $outputByLine[(int) $row->line_id] = (int) $row->total_output;
            }
        } catch (\Throwable $e) {
            $this->error("Gagal terhubung atau query ke database Odoo ({$connectionName}): " . $e->getMessage());
            Log::error("SyncOdooProductionHourlyCommand: Connection/Query failed: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }

        // 3. Simpan data ke sistem Andon
        $snapshotAt = $now->copy();
        $syncedCount = 0;

        foreach (self::LINE_MAPPING as $lineId => $machineIdentifier) {
            $totalOutput = $outputByLine[$lineId] ?? 0;

            // Cari data master mesin di inspection_tables
            $table = InspectionTable::where('name', $machineIdentifier)
                ->orWhere('machine_id', $machineIdentifier)
                ->first();

            if (!$table) {
                $this->warn("Mesin {$machineIdentifier} (Line ID {$lineId}) belum terdaftar di inspection_tables. Dilewati.");
                continue;
            }

            $machineAddress = $table->address ?: $machineIdentifier;
            $lineName = $table->line_name;

            $this->line("-> Mesin: {$machineIdentifier} [Address: {$machineAddress}, Line: {$lineName}] | Total Output: {$totalOutput}");

            if ($isDryRun) {
                $this->comment("   [Dry-Run] Lewati penulisan ke database.");
                continue;
            }

            try {
                // A. Simpan ke production_data (data real-time untuk dashboard status dan perhitungan OEE)
                ProductionData::create([
                    'timestamp' => $snapshotAt,
                    'machine_name' => $machineAddress,
                    'line_name' => $lineName,
                    'quantity' => $totalOutput,
                ]);

                // B. Simpan / Update ke production_data_hourly (snapshot analitik per jam)
                ProductionDataHourly::updateOrCreate(
                    [
                        'snapshot_at' => $snapshotAt->format('Y-m-d H:00:00'),
                        'machine_name' => $machineAddress,
                    ],
                    [
                        'line_name' => $lineName,
                        'quantity' => $totalOutput,
                    ]
                );

                $syncedCount++;
            } catch (\Throwable $e) {
                $this->error("Gagal menyimpan data mesin {$machineIdentifier}: " . $e->getMessage());
                Log::error("SyncOdooProductionHourlyCommand: Save failed for {$machineIdentifier}: " . $e->getMessage());
            }
        }

        $this->info("Sinkronisasi selesai. {$syncedCount} mesin berhasil disinkronkan.");
        return self::SUCCESS;
    }
}
