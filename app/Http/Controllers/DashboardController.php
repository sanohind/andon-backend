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

        // Ambil semua data log problem dan production dalam satu query yang efisien
        // Ini menghindari query N+1 di dalam loop
        $machineNames = $allInspectionTables->pluck('name')->toArray();

        $activeProblems = DB::table('log')
            ->whereIn('tipe_mesin', $machineNames)
            ->where('status', 'ON')
            ->get()
            ->keyBy('tipe_mesin'); // Kelompokkan berdasarkan nama mesin

        $latestProductions = ProductionData::whereIn('machine_name', $machineNames)
            ->orderBy('timestamp', 'desc')
            ->get()
            ->unique('machine_name') // Ambil yang paling baru untuk setiap mesin
            ->keyBy('machine_name'); // Kelompokkan berdasarkan nama mesin

        foreach ($allInspectionTables as $table) {
            $machineName = $table->name;
            $lineNumber = $table->line_number;

            $activeProblem = $activeProblems->get($machineName);
            $latestProduction = $latestProductions->get($machineName);

            $statusData = [
                'name' => $machineName,
                'status' => $activeProblem ? 'problem' : 'normal',
                'color' => $activeProblem ? 'red' : 'green', // Ini mungkin tidak lagi dipakai langsung di EJS
                'problem_type' => $activeProblem ? $activeProblem->tipe_problem : null,
                'timestamp' => $activeProblem ? $activeProblem->timestamp : null,
                'last_check' => Carbon::now()->format('Y-m-d H:i:s'),
                'quantity' => $latestProduction ? $latestProduction->quantity : 0,
                'id' => $table->id // Tambahkan ID meja untuk keperluan frontend
            ];

            // Kelompokkan berdasarkan line_number
            if (!isset($groupedStatuses[$lineNumber])) {
                $groupedStatuses[$lineNumber] = [];
            }
            $groupedStatuses[$lineNumber][] = $statusData;
        }
        
        return $groupedStatuses; // Laravel sekarang akan mengembalikan data terkelompok
    }
    /**
     * Get daftar problem yang sedang aktif
     */
    public function getActiveProblems()
    {
        return DB::table('log')
            ->where('status', 'ON')
            ->orderBy('timestamp', 'desc')
            ->get()
            ->map(function($problem) {
                $problemTimestamp = Carbon::createFromFormat('Y-m-d H:i:s', $problem->timestamp, 'UTC');
                return [
                    'id' => $problem->id,
                    'machine' => $problem->tipe_mesin,
                    'problem_type' => $problem->tipe_problem,
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
        
        return DB::table('log')
            ->where('status', 'ON')
            ->where('timestamp', '>=', $tenSecondsAgo)
            ->orderBy('timestamp', 'desc')
            ->get()
            ->map(function($problem) {
                return [
                    'id' => $problem->id,
                    'machine' => $problem->tipe_mesin,
                    'problem_type' => $problem->tipe_problem,
                    'timestamp' => Carbon::parse($problem->timestamp)->format('H:i:s'),
                    'message' => "ALERT: {$problem->tipe_mesin} mengalami masalah {$problem->tipe_problem}!",
                    'severity' => $this->getProblemSeverity($problem->tipe_problem)
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
    public function getDashboardStats()
    {
        $stats = [
            'total_machines' => InspectionTable::count(), 
            'active_problems' => DB::table('log')->where('status', 'ON')->count(),
            'resolved_today' => DB::table('log')
                ->where('status', 'OFF')
                ->whereDate('resolved_at', Carbon::today())
                ->count(),
            'critical_problems' => DB::table('log')
                ->where('status', 'ON')
                ->where('tipe_problem', 'Safety')
                ->count()
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