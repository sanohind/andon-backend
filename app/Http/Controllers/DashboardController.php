<?php

namespace App\Http\Controllers;

use App\Models\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Daftar mesin yang akan dimonitor
     */
    private $machines = [
        'Mesin 1',
        'Mesin 2', 
        'Mesin 3',
        'Mesin 4',
        'Mesin 5'
    ];

    /**
     * Tampilkan dashboard monitoring
     */
    public function index()
    {
        $machineStatuses = $this->getMachineStatuses();
        $activeProblems = $this->getActiveProblems();
        
        return view('dashboard.monitoring', compact('machineStatuses', 'activeProblems'));
    }

    /**
     * Get status semua mesin (untuk display lampu indikator)
     */
    public function getMachineStatuses()
    {
        $statuses = [];
        
        foreach ($this->machines as $machine) {
            // Cek apakah ada problem aktif untuk mesin ini
            $activeProblem = DB::table('log')
                ->where('tipe_mesin', $machine)
                ->where('status', 'ON')
                ->orderBy('timestamp', 'desc')
                ->first();
            
            $statuses[$machine] = [
                'name' => $machine,
                'status' => $activeProblem ? 'problem' : 'normal',
                'color' => $activeProblem ? 'red' : 'green',
                'problem_type' => $activeProblem ? $activeProblem->tipe_problem : null,
                'timestamp' => $activeProblem ? $activeProblem->timestamp : null,
                'last_check' => Carbon::now()->format('Y-m-d H:i:s')
            ];
        }
        
        return $statuses;
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
    public function getStatusApi()
    {
        $machineStatuses = $this->getMachineStatuses();
        $activeProblems = $this->getActiveProblems();
        $newProblems = $this->getNewProblems();
        
        return response()->json([
            'success' => true,
            'data' => [
                'machine_statuses' => $machineStatuses,
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
        $problem = Log::find($id);

        if (!$problem) {
            return response()->json(['success' => false, 'message' => 'Problem tidak ditemukan'], 404);
        }

        $newStatus = $request->input('status', 'OFF');

        // Jika status diubah menjadi OFF, hitung durasi dan catat waktu selesai
        if ($newStatus === 'OFF' && $problem->status === 'ON') {
            $problem->status = $newStatus;
            $problem->resolved_at = Carbon::now();

            // Hitung selisih waktu
            $duration = $problem->resolved_at->diffInSeconds($problem->timestamp);
            
            // Pastikan durasi selalu positif (abs) dan merupakan bilangan bulat (round/int)
            $problem->duration_in_seconds = (int) round(abs($duration));
        } else {
            $problem->status = $newStatus;
        }
        
        $problem->save();

        return response()->json([
            'success' => true,
            'message' => 'Status problem berhasil diupdate'
        ]);
    }


    /**
     * Get statistik dashboard
     */
    public function getDashboardStats()
    {
        $stats = [
            'total_machines' => count($this->machines),
            'active_problems' => DB::table('log')->where('status', 'ON')->count(),
            'resolved_today' => DB::table('log')
                ->where('status', 'OFF')
                ->whereDate('timestamp', Carbon::today())
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
}