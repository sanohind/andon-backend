<?php

namespace App\Console\Commands;

use App\Models\InspectionTable;
use App\Models\ProductionDowntimeData;
use App\Models\ProductionDowntimeRecord;
use App\Support\ProductionShiftInfo;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Manager as MongoManager;
use MongoDB\Driver\Query as MongoQuery;

class SyncMongoDowntimeHourlyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mongo:sync-downtime-hourly
                            {--date= : Tanggal spesifik YYYY-MM-DD (default: shift berjalan)}
                            {--full-day : Gunakan rentang 1 hari penuh (00:00:00 - 23:59:59) bukan shift}
                            {--dry-run : Menampilkan hasil query tanpa menyimpan ke database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sinkronisasi data downtime per jam dari database MongoDB (connection4) untuk mesin NIP2 (01, 02, 03)';

    /**
     * Pemetaan Mesin NIP2 ke line_process_ids di MongoDB dan fallback address di Andon.
     * - NIP2 01: INSPECTION K2FA MEJA 2 & INSPECTION K2FA
     * - NIP2 02: INSPECTION K90 & INSPECTION K58
     * - NIP2 03: INSPECTION K60 & INSPECTION K60 EXPORT
     */
    protected const MACHINE_MAPPING = [
        'NIP2 01' => [
            'name' => 'NIP2 01',
            'line_process_ids' => [
                '613980680bab5c69026e92e7', // INSPECTION K2FA MEJA 2
                '6192ef5441409826376d1468', // INSPECTION K2FA
            ],
            'fallback_address' => '116-03',
        ],
        'NIP2 02' => [
            'name' => 'NIP2 02',
            'line_process_ids' => [
                '6139634734cfbb67b86a74c7', // INSPECTION K90
                '6139634734cfbb67b86a74c6', // INSPECTION K58
            ],
            'fallback_address' => '116-04',
        ],
        'NIP2 03' => [
            'name' => 'NIP2 03',
            'line_process_ids' => [
                '6139634734cfbb67b86a74c4', // INSPECTION K60
                '656ee340b4f73ffdd807cba2', // INSPECTION K60 EXPORT
            ],
            'fallback_address' => '117-01',
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

        $this->info("Memulai sinkronisasi data Downtime MongoDB [URI: {$uri}, Database: {$database}]...");

        // 1. Tentukan rentang waktu query (UTC untuk MongoDB start time)
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
            // Mode Default: Mengikuti rentang shift aktif
            $shiftInfo = ProductionShiftInfo::resolve($now, $appTimezone);
            $shiftStartWib = $shiftInfo['shiftStart'];
            $startUtc = $shiftStartWib->copy()->utc();
            $endUtc = $now->copy()->utc();
            $shiftKey = $shiftInfo['shiftKey'];
            $this->info("Mode Shift [{$shiftInfo['shift']}]: {$shiftStartWib->format('Y-m-d H:i:s')} WIB s/d {$now->format('Y-m-d H:i:s')} WIB (UTC: {$startUtc} s/d {$endUtc})");
        }

        // 2. Kumpulkan semua line_process_id
        $allLineProcessIds = [];
        $lpToMachineMap = [];
        foreach (self::MACHINE_MAPPING as $machineIdentifier => $config) {
            foreach ($config['line_process_ids'] as $id) {
                $strId = (string) $id;
                $allLineProcessIds[] = $strId;
                $lpToMachineMap[$strId] = $machineIdentifier;
            }
        }

        // 3. Eksekusi query ke collection breakdown_productions di MongoDB
        $recordsByMachine = [];
        foreach (self::MACHINE_MAPPING as $machineIdentifier => $config) {
            $recordsByMachine[$machineIdentifier] = [
                'closed_downtime_seconds' => 0,
                'open_records_count' => 0,
                'records' => [],
            ];
        }

        try {
            $manager = new MongoManager($uri);
            $startBson = new UTCDateTime($startUtc->getTimestamp() * 1000);
            $endBson = new UTCDateTime($endUtc->getTimestamp() * 1000);

            $filter = [
                'line_process_id' => ['$in' => $allLineProcessIds],
                'start' => [
                    '$gte' => $startBson,
                    '$lte' => $endBson,
                ],
            ];

            $options = [
                'sort' => ['start' => 1],
            ];

            $query = new MongoQuery($filter, $options);
            $cursor = $manager->executeQuery("{$database}.breakdown_productions", $query);

            foreach ($cursor as $doc) {
                $lpId = (string) ($doc->line_process_id ?? '');
                $machineKey = $lpToMachineMap[$lpId] ?? null;
                if (!$machineKey) {
                    continue;
                }

                $mongoId = (string) ($doc->_id ?? '');
                $startAt = isset($doc->start)
                    ? Carbon::createFromTimestampMs($doc->start->toDateTime()->getTimestamp() * 1000, 'UTC')->setTimezone($appTimezone)
                    : null;
                $endAt = isset($doc->end)
                    ? Carbon::createFromTimestampMs($doc->end->toDateTime()->getTimestamp() * 1000, 'UTC')->setTimezone($appTimezone)
                    : null;

                $isClosed = ($endAt !== null);
                $durationSec = 0;

                if ($isClosed && $startAt) {
                    $durationSec = max(0, $endAt->getTimestamp() - $startAt->getTimestamp());
                    $recordsByMachine[$machineKey]['closed_downtime_seconds'] += $durationSec;
                } else {
                    // Masih open: tetap dicatat tetapi TIDAK masuk perhitungan durasi
                    $recordsByMachine[$machineKey]['open_records_count']++;
                }

                $recordsByMachine[$machineKey]['records'][] = [
                    'mongo_id' => $mongoId,
                    'line_process_id' => $lpId,
                    'line_process_code' => (string) ($doc->line_process_code ?? ''),
                    'line_process_name' => (string) ($doc->line_process_name ?? ''),
                    'cause' => (string) ($doc->cause ?? ''),
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'duration_seconds' => $durationSec,
                    'is_closed' => $isClosed,
                    'shift_key' => $shiftKey,
                ];
            }
        } catch (\Throwable $e) {
            $this->error("Gagal terhubung atau query ke MongoDB: " . $e->getMessage());
            Log::error("SyncMongoDowntimeHourlyCommand: Connection/Query failed: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }

        // 4. Simpan ke database PostgreSQL Andon
        $snapshotAt = $now->copy();
        $hourlySnapshotAt = $snapshotAt->copy()->format('Y-m-d H:00:00');
        $syncedCount = 0;

        foreach (self::MACHINE_MAPPING as $machineIdentifier => $config) {
            $data = $recordsByMachine[$machineIdentifier];
            $closedSec = $data['closed_downtime_seconds'];
            $openCount = $data['open_records_count'];
            $totalRecords = count($data['records']);

            // Cari master mesin di inspection_tables
            $table = InspectionTable::where('name', $machineIdentifier)
                ->orWhere('machine_id', $machineIdentifier)
                ->first();

            $machineAddress = $table?->address ?: $config['fallback_address'];
            $lineName = $table?->line_name ?: 'Roda Dua';

            $minutes = round($closedSec / 60, 2);
            $this->line("-> Mesin: {$machineIdentifier} [Address: {$machineAddress}, Line: {$lineName}] | Closed Downtime: {$closedSec}s ({$minutes}m) | Open: {$openCount} | Total Rec: {$totalRecords}");

            if ($isDryRun) {
                $this->comment("   [Dry-Run] Lewati penulisan ke database.");
                continue;
            }

            try {
                // Simpan/update setiap log transaksi ke production_downtime_records
                foreach ($data['records'] as $rec) {
                    \App\Models\ProductionDowntimeRecord::updateOrCreate(
                        ['mongo_id' => $rec['mongo_id']],
                        [
                            'machine_name' => $machineAddress,
                            'line_process_id' => $rec['line_process_id'],
                            'line_process_code' => $rec['line_process_code'],
                            'line_process_name' => $rec['line_process_name'],
                            'cause' => $rec['cause'],
                            'start_at' => $rec['start_at'],
                            'end_at' => $rec['end_at'],
                            'duration_seconds' => $rec['duration_seconds'],
                            'is_closed' => \Illuminate\Support\Facades\DB::raw($rec['is_closed'] ? 'true' : 'false'),
                            'shift_key' => $rec['shift_key'],
                        ]
                    );
                }

                // Simpan agregasi per jam per shift ke production_downtime_data
                ProductionDowntimeData::updateOrCreate(
                    [
                        'machine_name' => $machineAddress,
                        'shift_key' => $shiftKey,
                        'snapshot_at' => $hourlySnapshotAt,
                    ],
                    [
                        'line_name' => $lineName,
                        'downtime_seconds' => $closedSec,
                        'open_records_count' => $openCount,
                    ]
                );

                $syncedCount++;
            } catch (\Throwable $e) {
                $this->error("Gagal menyimpan data downtime mesin {$machineIdentifier}: " . $e->getMessage());
                Log::error("SyncMongoDowntimeHourlyCommand: Save failed for {$machineIdentifier}: " . $e->getMessage());
            }
        }

        $this->info("Sinkronisasi Downtime selesai. {$syncedCount} mesin berhasil disinkronkan.");
        return self::SUCCESS;
    }
}
