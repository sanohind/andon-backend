<?php

namespace App\Console\Commands;

use App\Models\InspectionTable;
use App\Models\ProductionNgData;
use App\Support\ProductionShiftInfo;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Command as MongoCommand;
use MongoDB\Driver\Manager as MongoManager;

class SyncMongoNgHourlyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mongo:sync-ng-hourly
                            {--date= : Tanggal spesifik YYYY-MM-DD (default: shift berjalan)}
                            {--full-day : Gunakan rentang 1 hari penuh (00:00:00 - 23:59:59) bukan shift}
                            {--dry-run : Menampilkan hasil query tanpa menyimpan ke database}';

    /**
     * The console command description.c
     *
     * @var string
     */
    protected $description = 'Sinkronisasi data NG per jam dari database MongoDB (connection4) untuk mesin NIP2 (NIP2 01, NIP2 02, NIP2 03)';

    /**
     * Pemetaan Mesin NIP2 ke konfigurasi line_process_id MongoDB dan alamat target di Andon.
     * Catatan: Alamat server production: NIP2 01 = 116-03, NIP2 02 = 116-04, NIP2 03 = 117-01.
     * Alamat lokal: 104-01, 104-02, 104-03.
     */
    protected const MACHINE_MAPPING = [
        'NIP2 01' => [
            'name' => 'NIP2 01',
            'line_process_ids' => ['613980680bab5c69026e92e7'],
            'fallback_address' => '104-01',
        ],
        'NIP2 02' => [
            'name' => 'NIP2 02',
            'line_process_ids' => ['6139634734cfbb67b86a74c7', '6139634734cfbb67b86a74c6'],
            'fallback_address' => '104-02',
        ],
        'NIP2 03' => [
            'name' => 'NIP2 03',
            'line_process_ids' => ['6139634734cfbb67b86a74c4'],
            'fallback_address' => '104-03',
        ],
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $appTimezone = config('app.timezone', 'Asia/Jakarta');
        $now = Carbon::now($appTimezone);

        $host = config('database.connections.mongodb.host', env('DB_HOST4', '10.1.10.39'));
        $port = config('database.connections.mongodb.port', env('DB_PORT4', '27017'));
        $database = config('database.connections.mongodb.database', env('DB_DATABASE4', 'production'));
        $username = config('database.connections.mongodb.username', env('DB_USERNAME4', ''));
        $password = config('database.connections.mongodb.password', env('DB_PASSWORD4', ''));

        $uri = 'mongodb://';
        if (!empty($username) && !empty($password)) {
            $uri .= "{$username}:{$password}@";
        }
        $uri .= "{$host}:{$port}";

        $this->info("Memulai sinkronisasi data NG MongoDB [URI: {$uri}, Database: {$database}]...");

        // 1. Tentukan rentang waktu query (UTC untuk MongoDB production_time)
        $specificDate = $this->option('date');
        $isFullDay = (bool) $this->option('full-day');
        $isDryRun = (bool) $this->option('dry-run');

        if ($specificDate) {
            $parsedDate = Carbon::parse($specificDate, $appTimezone);
            $startUtc = $parsedDate->copy()->startOfDay()->utc();
            $endUtc = $parsedDate->copy()->endOfDay()->utc();
            $shiftKey = $parsedDate->format('Y-m-d') . '_custom';
            $this->info("Mode Tanggal Spesifik: {$parsedDate->format('Y-m-d')} (UTC: {$startUtc} s/d {$endUtc})");
        } elseif ($isFullDay) {
            $startUtc = $now->copy()->startOfDay()->utc();
            $endUtc = $now->copy()->endOfDay()->utc();
            $shiftKey = $now->format('Y-m-d') . '_fullday';
            $this->info("Mode 1 Hari Penuh: {$now->format('Y-m-d')} (UTC: {$startUtc} s/d {$endUtc})");
        } else {
            // Mode Default: Mengikuti pola Shift Aktif Andon
            $shiftInfo = ProductionShiftInfo::resolve($now, $appTimezone);
            $shiftStartWib = $shiftInfo['shiftStart'];
            $startUtc = $shiftStartWib->copy()->utc();
            $endUtc = $now->copy()->utc();
            $shiftKey = $shiftInfo['shiftKey'];
            $this->info("Mode Shift [{$shiftInfo['shift']}]: {$shiftStartWib->format('Y-m-d H:i:s')} WIB s/d {$now->format('Y-m-d H:i:s')} WIB (UTC: {$startUtc} s/d {$endUtc})");
        }

        // 2. Kumpulkan semua line_process_id dari pemetaan
        $allLineProcessIds = [];
        foreach (self::MACHINE_MAPPING as $config) {
            foreach ($config['line_process_ids'] as $id) {
                $allLineProcessIds[] = (string) $id;
            }
        }

        // 3. Eksekusi aggregation query ke MongoDB
        $ngByLineProcessId = [];
        try {
            $manager = new MongoManager($uri);
            $startBson = new UTCDateTime($startUtc->getTimestamp() * 1000);
            $endBson = new UTCDateTime($endUtc->getTimestamp() * 1000);

            $pipeline = [
                [
                    '$match' => [
                        'line_process_id' => ['$in' => $allLineProcessIds],
                        'status' => 'ng',
                        'production_time' => [
                            '$gte' => $startBson,
                            '$lte' => $endBson,
                        ],
                    ],
                ],
                [
                    '$group' => [
                        '_id' => '$line_process_id',
                        'total_ng' => ['$sum' => '$qty'],
                    ],
                ],
            ];

            $command = new MongoCommand([
                'aggregate' => 'productions',
                'pipeline' => $pipeline,
                'cursor' => new \stdClass(),
            ]);

            $cursor = $manager->executeCommand($database, $command);
            foreach ($cursor as $row) {
                $lineProcId = (string) $row->_id;
                $ngByLineProcessId[$lineProcId] = (int) ($row->total_ng ?? 0);
            }
        } catch (\Throwable $e) {
            $this->error("Gagal terhubung atau query ke MongoDB: " . $e->getMessage());
            Log::error("SyncMongoNgHourlyCommand: Connection/Query failed: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }

        // 4. Simpan data NG ke sistem Andon (tabel production_ng_data)
        $snapshotAt = $now->copy();
        $syncedCount = 0;

        foreach (self::MACHINE_MAPPING as $machineIdentifier => $config) {
            // Akumulasikan total NG dari semua line_process_id milik mesin ini
            $totalNg = 0;
            foreach ($config['line_process_ids'] as $lineProcId) {
                $totalNg += ($ngByLineProcessId[$lineProcId] ?? 0);
            }

            // Cari master mesin di inspection_tables
            $table = InspectionTable::where('name', $machineIdentifier)
                ->orWhere('machine_id', $machineIdentifier)
                ->first();

            $machineAddress = $table?->address ?: $config['fallback_address'];
            $lineName = $table?->line_name ?: 'Roda Dua';

            $this->line("-> Mesin: {$machineIdentifier} [Address: {$machineAddress}, Line: {$lineName}] | Total NG: {$totalNg}");

            if ($isDryRun) {
                $this->comment("   [Dry-Run] Lewati penulisan ke database.");
                continue;
            }

            try {
                // Simpan ke production_ng_data (upsert berdasarkan machine_name dan shift_key)
                ProductionNgData::updateOrCreate(
                    [
                        'machine_name' => $machineAddress,
                        'shift_key' => $shiftKey,
                    ],
                    [
                        'line_name' => $lineName,
                        'ng_qty' => $totalNg,
                        'snapshot_at' => $snapshotAt,
                    ]
                );

                // Sinkronkan juga untuk alias address lain (lokal vs production)
                $allKnownAddresses = array_unique(array_filter([$machineAddress, $config['fallback_address'], $table?->address]));
                foreach ($allKnownAddresses as $altAddress) {
                    if ($altAddress !== $machineAddress) {
                        ProductionNgData::updateOrCreate(
                            [
                                'machine_name' => $altAddress,
                                'shift_key' => $shiftKey,
                            ],
                            [
                                'line_name' => $lineName,
                                'ng_qty' => $totalNg,
                                'snapshot_at' => $snapshotAt,
                            ]
                        );
                    }
                }

                $syncedCount++;
            } catch (\Throwable $e) {
                $this->error("Gagal menyimpan data NG mesin {$machineIdentifier}: " . $e->getMessage());
                Log::error("SyncMongoNgHourlyCommand: Save failed for {$machineIdentifier}: " . $e->getMessage());
            }
        }

        $this->info("Sinkronisasi NG selesai. {$syncedCount} mesin berhasil disinkronkan.");
        return self::SUCCESS;
    }
}
