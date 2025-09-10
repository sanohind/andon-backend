<?php

namespace App\Http\Controllers;

use App\Models\Log;
use App\Models\ProductionData;
use App\Models\InspectionTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class DashboardController extends Controller
{
    private function getAllMachineNames()
    {
        // Mengambil semua record dari tabel inspection_tables, 
        // diurutkan berdasarkan nama, dan hanya mengambil kolom 'name'.
        return InspectionTable::orderBy('name', 'asc')->pluck('name');
    }
    /**
     * Tampilkan dashboard monitoring
     */
    public function index()
    {
        $machines = $this->getAllMachineNames();
        $machineStatuses = $this->getMachineStatuses();
        $activeProblems = $this->getActiveProblems();
        
        return view('dashboard.monitoring', compact('machines', 'machineStatuses', 'activeProblems'));
    }

    /**
     * Get status semua mesin (untuk display lampu indikator)
     */
    public function getMachineStatuses(Request $request)
    {
        // Ambil SEMUA meja, karena filter akan dilakukan di frontend Node.js/EJS
        $allInspectionTables = InspectionTable::orderBy('line_number')->orderBy('name')->get();
        
        // Siapkan struktur data yang dikelompokkan per line
        $groupedStatuses = [];

        // Ambil semua data log problem dengan kombinasi tipe_mesin DAN line_number
        $activeProblems = DB::table('log')
            ->where('status', 'ON')
            ->get()
            ->keyBy(function($item) {
                // PERBAIKAN: Gunakan kombinasi tipe_mesin dan line_number sebagai key
                return $item->tipe_mesin . '_line_' . $item->line_number;
            });

        // Ambil data produksi terbaru dengan kombinasi machine_name dan line_number
        $machineNames = $allInspectionTables->pluck('name')->toArray();
        $latestProductions = ProductionData::whereIn('machine_name', $machineNames)
            ->orderBy('timestamp', 'desc')
            ->get()
            ->unique(function($item) {
                // PERBAIKAN: Gunakan kombinasi machine_name dan line_number untuk uniqueness
                return $item->machine_name . '_line_' . ($item->line_number ?? 'default');
            })
            ->keyBy(function($item) {
                return $item->machine_name . '_line_' . ($item->line_number ?? 'default');
            });

        foreach ($allInspectionTables as $table) {
            $machineName = $table->name;
            $lineNumber = $table->line_number;

            // PERBAIKAN: Cari active problem berdasarkan kombinasi nama mesin DAN line number
            $problemKey = $machineName . '_line_' . $lineNumber;
            $activeProblem = $activeProblems->get($problemKey);

            // PERBAIKAN: Cari production data berdasarkan kombinasi nama mesin DAN line number
            $productionKey = $machineName . '_line_' . $lineNumber;
            $latestProduction = $latestProductions->get($productionKey);

            $statusData = [
                'name' => $machineName,
                'line_number' => $lineNumber, // TAMBAHAN: Sertakan line_number dalam response
                'status' => $activeProblem ? 'problem' : 'normal',
                'color' => $activeProblem ? 'red' : 'green',
                'problem_type' => $activeProblem ? $activeProblem->tipe_problem : null,
                'timestamp' => $activeProblem ? $activeProblem->timestamp : null,
                'last_check' => Carbon::now()->format('Y-m-d H:i:s'),
                'quantity' => $latestProduction ? $latestProduction->quantity : 0,
                'id' => $table->id
            ];

            // Kelompokkan berdasarkan line_number
            if (!isset($groupedStatuses[$lineNumber])) {
                $groupedStatuses[$lineNumber] = [];
            }
            $groupedStatuses[$lineNumber][] = $statusData;
        }
        
        return $groupedStatuses;
    }
    /**
     * Get daftar problem yang sedang aktif
     */
    public function getActiveProblems()
    {
        // Query sekarang melakukan JOIN untuk mengambil line_number
        return DB::table('log')
            ->join('inspection_tables', function ($join) {
                // Mencocokkan berdasarkan nama meja DAN nomor line untuk akurasi
                $join->on('log.tipe_mesin', '=', 'inspection_tables.name')
                    ->on('log.line_number', '=', 'inspection_tables.line_number');
            })
            ->where('log.status', 'ON')
            // Pilih semua kolom dari log dan tambahkan line_number
            ->select('log.*', 'inspection_tables.line_number')
            ->orderBy('log.timestamp', 'desc')
            ->get()
            ->map(function($problem) {
                $problemTimestamp = Carbon::createFromFormat('Y-m-d H:i:s', $problem->timestamp, 'UTC');
                return [
                    'id' => $problem->id,
                    'machine' => $problem->tipe_mesin,
                    'problem_type' => $problem->tipe_problem,
                    'line_number' => $problem->line_number, // <-- SEKARANG line_number ADA DI SINI
                    'timestamp' => Carbon::parse($problem->timestamp)->format('d/m/Y H:i:s'),
                    'duration' => $problemTimestamp->diffForHumans(),
                    'severity' => $this->getProblemSeverity($problem->tipe_problem)
                ];
            });
    }

    /**
     * API endpoint untuk real-time monitoring (AJAX)
     */
    public function getStatusApi(Request $request)
    {
        $machineStatusesGroupedByLine = $this->getMachineStatuses($request); 
        $activeProblems = $this->getActiveProblems();
        $newProblems = $this->getNewProblems();
        
        return response()->json([
            'success' => true,
            'data' => [
                'machine_statuses_by_line' => $machineStatusesGroupedByLine, // <-- NAMA KUNCI BARU
                'active_problems' => $activeProblems,
                'new_problems' => $newProblems, // untuk trigger notifikasi
                'timestamp' => Carbon::now()->format('Y-m-d H:i:s')
            ]
        ]);
    }

    /**
     * Get problem baru dalam 10 detik terakhir (untuk notifikasi)
     */
    public function getNewProblems()
    {
        $tenSecondsAgo = Carbon::now()->subSeconds(10);
        
        // PERBAIKAN: JOIN dengan inspection_tables untuk mendapatkan line_number
        return DB::table('log')
            ->join('inspection_tables', function ($join) {
                $join->on('log.tipe_mesin', '=', 'inspection_tables.name')
                    ->on('log.line_number', '=', 'inspection_tables.line_number');
            })
            ->where('log.status', 'ON')
            ->where('log.timestamp', '>=', $tenSecondsAgo)
            ->select('log.*', 'inspection_tables.line_number as verified_line_number')
            ->orderBy('log.timestamp', 'desc')
            ->get()
            ->map(function($problem) {
                return [
                    'id' => $problem->id,
                    'machine' => $problem->tipe_mesin,
                    'machine_name' => $problem->tipe_mesin,
                    'line_number' => $problem->verified_line_number, // Gunakan line_number yang sudah diverifikasi
                    'problem_type' => $problem->tipe_problem,
                    'problemType' => $problem->tipe_problem,
                    'timestamp' => Carbon::parse($problem->timestamp)->format('H:i:s'),
                    'message' => "ALERT: {$problem->tipe_mesin} mengalami masalah {$problem->tipe_problem}!",
                    'severity' => $this->getProblemSeverity($problem->tipe_problem),
                    'description' => $this->getProblemDescription($problem->tipe_problem),
                    'recommended_action' => $this->getRecommendedAction($problem->tipe_problem)
                ];
            });
    }

    /**
     * Get detail problem untuk popup
     */
    public function getProblemDetail($id)
    {
        $problem = DB::table('log')->where('id', $id)->first();
        
        if (!$problem) {
            return response()->json([
                'success' => false,
                'message' => 'Problem tidak ditemukan'
            ], 404);
        }
        $problemTimestamp = Carbon::createFromFormat('Y-m-d H:i:s', $problem->timestamp, 'UTC');
        $detail = [
            'id' => $problem->id,
            'machine' => $problem->tipe_mesin,
            'problem_type' => $problem->tipe_problem,
            'status' => $problem->status,
            'timestamp' => Carbon::parse($problem->timestamp)->format('d/m/Y H:i:s'),
            'duration' => $problemTimestamp->diffForHumans(),
            'severity' => $this->getProblemSeverity($problem->tipe_problem),
            'recommended_action' => $this->getRecommendedAction($problem->tipe_problem),
            'description' => $this->getProblemDescription($problem->tipe_problem)
        ];

        return response()->json([
            'success' => true,
            'data' => $detail
        ]);
    }

    /**
     * Get tingkat severity problem
     */
    private function getProblemSeverity($problemType)
    {
        $severityMap = [
            'Quality' => 'high',
            'Material' => 'medium',
            'Machine' => 'critical'
        ];

        return $severityMap[$problemType] ?? 'medium';
    }

    /**
     * Get deskripsi problem
     */
    private function getProblemDescription($problemType)
    {
        $descriptions = [
            'Quality' => 'Terdeteksi penurunan kualitas produk atau hasil produksi tidak sesuai standar',
            'Material' => 'Terdeteksi kekurangan material yang dapat menyebabkan efisiensi produksi',
            'Machine' => 'Mesin mengalami kondisi abnormal'
        ];

        return $descriptions[$problemType] ?? 'Problem tidak diketahui pada sistem';
    }

    /**
     * Get rekomendasi tindakan
     */
    private function getRecommendedAction($problemType)
    {
        $actions = [
            'Quality' => 'Periksa parameter produksi dan kalibrasi sensor quality control',
            'Material' => 'Hentikan mesin dan isi ulang material sesegera mungkin',
            'Machine' => 'Periksa kondisi mesin, pastikan tidak ada komponen yang aus atau rusak'
        ];

        return $actions[$problemType] ?? 'Hubungi teknisi untuk inspeksi lebih lanjut';
    }

    /**
     * Update status problem (misal dari ON ke OFF)
     */
    public function updateProblemStatus(Request $request, $id)
    {
        // Cari masalah yang statusnya masih 'ON' berdasarkan ID
        $problem = Log::where('id', $id)->where('status', 'ON')->first();

        // Jika tidak ditemukan, kirim pesan error
        if (!$problem) {
            return response()->json([
                'success' => false, 
                'message' => 'Problem tidak ditemukan atau sudah diselesaikan sebelumnya.'
            ], 404);
        }

        // Pastikan permintaan ini adalah untuk menyelesaikan masalah
        if ($request->input('status') === 'OFF') {
            
            $problem->status = 'OFF';
            $problem->resolved_at = now(); // Mengambil waktu saat ini (WIB)

            // =====================================================================
            // == PERHITUNGAN DURASI FINAL YANG AKURAT ==
            // =====================================================================
            
            // 1. Ambil timestamp mentah dari DB untuk menghindari salah interpretasi dari Laravel
            $timestampString = $problem->getRawOriginal('timestamp');

            // 2. Buat objek waktu dari string mentah, dan paksakan interpretasinya sebagai UTC
            $startTime = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $timestampString, 'UTC');
            
            // 3. Hitung selisihnya. Carbon akan menangani konversi antar timezone secara otomatis.
            $problem->duration_in_seconds = abs($problem->resolved_at->diffInSeconds($startTime));
            
            // Simpan semua perubahan ke database
            $problem->save();

            // Kirim respons sukses
            return response()->json([
                'success' => true,
                'message' => 'Status problem berhasil diupdate'
            ]);
        }
        
        // Jika ada permintaan status lain (bukan 'OFF'), kirim respons error
        return response()->json([
            'success' => false,
            'message' => 'Permintaan status tidak valid.'
        ], 400);
    }


    /**
     * Get statistik dashboard
     */
    // app/Http/Controllers/DashboardController.php

    public function getDashboardStats(Request $request)
    {
        // Ambil line_number dari permintaan. Jika tidak ada, nilainya akan null.
        $lineNumber = $request->input('line_number');
        $userTimezone = config('app.timezone'); // Mengambil timezone dari config

        // --- Query untuk Total Meja ---
        $totalMachinesQuery = \App\Models\InspectionTable::query();
        if ($lineNumber) {
            // Jika ada line_number, filter berdasarkan itu.
            $totalMachinesQuery->where('line_number', $lineNumber);
        }

        // --- Query untuk Log Problem ---
        $logQuery = \App\Models\Log::query();
        if ($lineNumber) {
            // Jika ada line_number, filter berdasarkan itu.
            $logQuery->where('line_number', $lineNumber);
        }
        
        // Buat klon query untuk penggunaan berulang agar lebih efisien
        $activeProblemsQuery = clone $logQuery;
        $resolvedTodayQuery = clone $logQuery;
        $criticalProblemsQuery = clone $logQuery;

        $stats = [
            'total_machines' => $totalMachinesQuery->count(),
            'active_problems' => $activeProblemsQuery->where('status', 'ON')->count(),
            'resolved_today' => $resolvedTodayQuery->where('status', 'OFF')
                                                ->whereDate('resolved_at', \Carbon\Carbon::today($userTimezone))
                                                ->count(),
            'critical_problems' => $criticalProblemsQuery->where('status', 'ON')
                                                        ->where('tipe_problem', 'Machine') // Pastikan ini tipe problem kritis Anda
                                                        ->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    public function getPlcStatus()
    {
        try {
            $nodeRedUrl = env('NODE_RED_URL', 'http://127.0.0.1:1880');
        
            $response = Http::timeout(5)->get($nodeRedUrl . '/plc-status');

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'data' => $response->json()
                ]);
            }
        } catch (\Exception $e) {
            // Menangani kasus jika Node-RED tidak bisa dihubungi
            return response()->json([
                'success' => false,
                'data' => [
                    'status' => 'Unreachable',
                    'last_error' => 'Could not connect to Node-RED service.',
                ]
            ], 500);
        }

        // Jika response gagal tapi tidak ada exception
        return response()->json([
            'success' => false,
            'data' => [
                'status' => 'Error',
                'last_error' => 'Received a non-successful response from Node-RED.',
            ]
        ], 502);
    }
}