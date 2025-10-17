<?php

namespace App\Http\Controllers;

use App\Models\Log;
use App\Models\ProductionData;
use App\Models\InspectionTable;
use App\Models\ForwardProblemLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class DashboardController extends Controller
{
    private function getAllMachineNames()
    {
        // Mengambil semua record dari tabel inspection_tables
        $tables = InspectionTable::all();
        
        // Sort using natural order (handles numbers correctly)
        $tables = $tables->sort(function($a, $b) {
            return strnatcasecmp($a->name, $b->name);
        });
        
        return $tables->pluck('name');
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
     * Get division and line mapping for manager filtering
     */
    private function getDivisionLineMapping()
    {
        return [
            'Brazing' => [
                'Leak Test Inspection',
                'Support',
                'Hand Bending',
                'Welding'
            ],
            'Chassis' => [
                'Cutting',
                'Flaring',
                'MF/TK',
                'LRFD',
                'Assy'
            ],
            'Nylon' => [
                'Injection/Extrude',
                'Roda Dua',
                'Roda Empat'
            ]
        ];
    }

    /**
     * Check if manager can see specific line based on their division
     */
    private function canManagerSeeLine($managerDivision, $lineName)
    {
        $mapping = $this->getDivisionLineMapping();
        
        if (!isset($mapping[$managerDivision])) {
            return false;
        }
        
        return in_array($lineName, $mapping[$managerDivision]);
    }

    /**
     * Get status semua mesin (untuk display lampu indikator)
     */
    public function getMachineStatuses(Request $request)
    {
        // Get user role and division from request or session
        $userRole = $request->input('user_role') ?? $request->header('X-User-Role');
        $userDivision = $request->input('user_division') ?? $request->header('X-User-Division');
        
        // Ambil SEMUA meja, karena filter akan dilakukan di frontend Node.js/EJS
        $allInspectionTables = InspectionTable::all();
        
        // Filter tables based on user role and division
        if ($userRole === 'manager' && $userDivision) {
            $mapping = $this->getDivisionLineMapping();
            $allowedLines = $mapping[$userDivision] ?? [];
            
            $allInspectionTables = $allInspectionTables->filter(function($table) use ($allowedLines) {
                return in_array($table->line_name, $allowedLines);
            });
        }
        
        // Sort using natural order (handles numbers correctly)
        $allInspectionTables = $allInspectionTables->sort(function($a, $b) {
            // First sort by line_name, then by name
            $lineCompare = strnatcasecmp($a->line_name, $b->line_name);
            if ($lineCompare !== 0) {
                return $lineCompare;
            }
            return strnatcasecmp($a->name, $b->name);
        });
        
        // Siapkan struktur data yang dikelompokkan per line
        $groupedStatuses = [];

        // Ambil semua data log problem dengan JOIN ke inspection_tables untuk mendapatkan nama mesin
        // PERBAIKAN: Tambahkan filtering berdasarkan line_name untuk mencegah cross-line problem
        $activeProblems = DB::table('log')
            ->select([
                'log.*',
                'inspection_tables.name as machine_name',
                'inspection_tables.line_name as table_line_name'
            ])
            ->join('inspection_tables', function($join) {
                $join->on('log.tipe_mesin', '=', 'inspection_tables.address')
                     ->on('log.line_name', '=', 'inspection_tables.line_name');
            })
            ->where('log.status', 'ON')
            ->get()
            ->keyBy(function($item) {
                // Gunakan kombinasi nama mesin dan line_name sebagai key
                return $item->machine_name . '_line_' . $item->table_line_name;
            });

        // Ambil data produksi terbaru dengan kombinasi machine_name dan line_name
        $machineNames = $allInspectionTables->pluck('name')->toArray();
        $latestProductions = ProductionData::whereIn('machine_name', $machineNames)
            ->orderBy('timestamp', 'desc')
            ->get()
            ->unique(function($item) {
                // PERBAIKAN: Gunakan kombinasi machine_name dan line_name untuk uniqueness
                return $item->machine_name . '_line_' . ($item->line_name ?? 'default');
            })
            ->keyBy(function($item) {
                return $item->machine_name . '_line_' . ($item->line_name ?? 'default');
            });

        foreach ($allInspectionTables as $table) {
            $machineName = $table->name;
            $lineName = $table->line_name;

            // PERBAIKAN: Cari active problem berdasarkan kombinasi nama mesin DAN line number
            $problemKey = $machineName . '_line_' . $lineName;
            $activeProblem = $activeProblems->get($problemKey);

            // PERBAIKAN: Cari production data berdasarkan kombinasi nama mesin DAN line number
            $productionKey = $machineName . '_line_' . $lineName;
            $latestProduction = $latestProductions->get($productionKey);

            $statusData = [
                'name' => $machineName,
                'line_name' => $lineName, // TAMBAHAN: Sertakan line_name dalam response
                'status' => $activeProblem ? 'problem' : 'normal',
                'color' => $activeProblem ? 'red' : 'green',
                'problem_type' => $activeProblem ? $activeProblem->tipe_problem : null,
                'timestamp' => $activeProblem ? $activeProblem->timestamp : null,
                'last_check' => Carbon::now(config('app.timezone'))->format('Y-m-d H:i:s'),
                'quantity' => $latestProduction ? $latestProduction->quantity : 0,
                'id' => $table->id
            ];

            // Kelompokkan berdasarkan line_name
            if (!isset($groupedStatuses[$lineName])) {
                $groupedStatuses[$lineName] = [];
            }
            $groupedStatuses[$lineName][] = $statusData;
        }
        
        return $groupedStatuses;
    }

    /**
     * Get status semua mesin dengan role-based filtering
     */
    public function getMachineStatusesWithRoleFilter(Request $request, $userRole = null, $userLineName = null, $userDivision = null)
    {
        // Ambil SEMUA meja, karena filter akan dilakukan di frontend Node.js/EJS
        $allInspectionTables = InspectionTable::all();
        
        // Sort using natural order (handles numbers correctly)
        $allInspectionTables = $allInspectionTables->sort(function($a, $b) {
            // First sort by line_name, then by name
            $lineCompare = strnatcasecmp($a->line_name, $b->line_name);
            if ($lineCompare !== 0) {
                return $lineCompare;
            }
            return strnatcasecmp($a->name, $b->name);
        });
        
        // Siapkan struktur data yang dikelompokkan per line
        $groupedStatuses = [];

        // Ambil semua data log problem dengan kombinasi tipe_mesin DAN line_name
        $activeProblems = DB::table('log')
            ->where('status', 'ON')
            ->get()
            ->keyBy(function($item) {
                // PERBAIKAN: Gunakan kombinasi tipe_mesin dan line_name sebagai key
                return $item->tipe_mesin . '_line_' . $item->line_name;
            });

        // Ambil data produksi terbaru dengan kombinasi machine_name dan line_name
        $machineNames = $allInspectionTables->pluck('name')->toArray();
        $latestProductions = ProductionData::whereIn('machine_name', $machineNames)
            ->orderBy('timestamp', 'desc')
            ->get()
            ->unique(function($item) {
                // PERBAIKAN: Gunakan kombinasi machine_name dan line_name untuk uniqueness
                return $item->machine_name . '_line_' . ($item->line_name ?? 'default');
            })
            ->keyBy(function($item) {
                return $item->machine_name . '_line_' . ($item->line_name ?? 'default');
            });

        foreach ($allInspectionTables as $table) {
            $machineName = $table->name;
            $lineName = $table->line_name;

            // PERBAIKAN: Cari active problem berdasarkan kombinasi nama mesin DAN line number
            $problemKey = $machineName . '_line_' . $lineName;
            $activeProblem = $activeProblems->get($problemKey);

            // PERBAIKAN: Cari production data berdasarkan kombinasi nama mesin DAN line number
            $productionKey = $machineName . '_line_' . $lineName;
            $latestProduction = $latestProductions->get($productionKey);

            // Tentukan status berdasarkan role user
            $machineStatus = 'normal';
            $problemType = null;
            $timestamp = null;

            if ($activeProblem) {
                // Cek apakah problem ini boleh ditampilkan untuk user role ini
                $shouldShowProblem = true;

                if (in_array($userRole, ['maintenance', 'quality', 'engineering'])) {
                    // Department users hanya melihat problem jika sudah di-forward ke mereka
                    $shouldShowProblem = $activeProblem->is_forwarded && $activeProblem->forwarded_to_role === $userRole;
                } elseif ($userRole === 'leader') {
                    // Leader hanya melihat problem dari line mereka
                    $shouldShowProblem = $activeProblem->line_name == $userLineName;
                } elseif ($userRole === 'manager') {
                    // Manager melihat semua problem dari divisi mereka
                    $shouldShowProblem = $activeProblem->division == $userDivision;
                } elseif (in_array($userRole, ['admin'])) {
                    // Admin melihat semua problem
                    $shouldShowProblem = true;
                }

                if ($shouldShowProblem) {
                    $machineStatus = 'problem';
                    $problemType = $activeProblem->tipe_problem;
                    $timestamp = $activeProblem->timestamp;
                }
            }

            $statusData = [
                'name' => $machineName,
                'line_name' => $lineName,
                'status' => $machineStatus,
                'color' => $machineStatus === 'problem' ? 'red' : 'green',
                'problem_type' => $problemType,
                'timestamp' => $timestamp,
                'last_check' => Carbon::now(config('app.timezone'))->format('Y-m-d H:i:s'),
                'quantity' => $latestProduction ? $latestProduction->quantity : 0,
                'id' => $table->id
            ];

            // Kelompokkan berdasarkan line_name
            if (!isset($groupedStatuses[$lineName])) {
                $groupedStatuses[$lineName] = [];
            }
            $groupedStatuses[$lineName][] = $statusData;
        }
        
        return $groupedStatuses;
    }

    /**
     * Get daftar problem yang sedang aktif dengan role-based visibility
     */
    public function getActiveProblems(Request $request = null, $userRole = null, $userLineName = null, $userDivision = null)
    {
        // Use JOIN to get machine names from inspection_tables
        // PERBAIKAN: Tambahkan filtering berdasarkan line_name untuk mencegah cross-line problem
        $query = DB::table('log')
            ->select([
                'log.*',
                'inspection_tables.name as machine_name',
                'inspection_tables.line_name as table_line_name'
            ])
            ->join('inspection_tables', function($join) {
                $join->on('log.tipe_mesin', '=', 'inspection_tables.address')
                     ->on('log.line_name', '=', 'inspection_tables.line_name');
            })
            ->where('log.status', 'ON');
            
        // Filter based on user role and division
        if ($userRole === 'manager' && $userDivision) {
            $mapping = $this->getDivisionLineMapping();
            $allowedLines = $mapping[$userDivision] ?? [];
            
            if (!empty($allowedLines)) {
                $query->whereIn('inspection_tables.line_name', $allowedLines);
            }
        }
        
        $problems = $query->orderBy('log.timestamp', 'desc')->get();

        return $problems->map(function($problem) {
            // PERBAIKAN: Gunakan timezone dari config untuk konsistensi
            $problemTimestamp = Carbon::createFromFormat('Y-m-d H:i:s', $problem->timestamp, config('app.timezone'));
            
            // Calculate problem status based on current state
            $problemStatus = 'active';
            if ($problem->status === 'OFF') {
                $problemStatus = 'resolved';
            } elseif ($problem->has_feedback_resolved) {
                $problemStatus = 'feedback_resolved';
            } elseif ($problem->is_received) {
                $problemStatus = 'received';
            } elseif ($problem->is_forwarded) {
                $problemStatus = 'forwarded';
            }
            
            return [
                'id' => $problem->id,
                'machine' => $problem->machine_name, // Use machine name instead of address
                'problem_type' => $problem->tipe_problem,
                'line_name' => $problem->table_line_name, // Use line name from inspection_tables
                'timestamp' => Carbon::parse($problem->timestamp)->format('d/m/Y H:i:s'),
                'duration' => $problemTimestamp->diffForHumans(),
                'severity' => $this->getProblemSeverity($problem->tipe_problem),
                'problem_status' => $problemStatus,
                'status' => $problem->status,
                'is_forwarded' => $problem->is_forwarded,
                'forwarded_to_role' => $problem->forwarded_to_role,
                'forwarded_by' => null, // Will be null since we're not loading relationships
                'forwarded_at' => $problem->forwarded_at ? Carbon::parse($problem->forwarded_at)->format('d/m/Y H:i:s') : null,
                'is_received' => $problem->is_received,
                'received_by' => null, // Will be null since we're not loading relationships
                'received_at' => $problem->received_at ? Carbon::parse($problem->received_at)->format('d/m/Y H:i:s') : null,
                'has_feedback_resolved' => $problem->has_feedback_resolved,
                'feedback_resolved_by' => null, // Will be null since we're not loading relationships
                'feedback_resolved_at' => $problem->feedback_resolved_at ? Carbon::parse($problem->feedback_resolved_at)->format('d/m/Y H:i:s') : null,
                'forward_message' => $problem->forward_message,
                'feedback_message' => $problem->feedback_message
            ];
        });
    }

    /**
     * API endpoint untuk real-time monitoring (AJAX) dengan role-based visibility
     */
    public function getStatusApi(Request $request)
    {
        // Get user info from token if available
        $userRole = null;
        $userLineName = null;
        $userDivision = null;
        
        // Also check headers for user info (for manager filtering)
        $userRole = $userRole ?? $request->header('X-User-Role');
        $userDivision = $userDivision ?? $request->header('X-User-Division');
        
        $token = $request->bearerToken() ?? $request->header('Authorization');
        if ($token) {
            try {
                $session = DB::table('user_sessions')
                    ->join('users', 'user_sessions.user_id', '=', 'users.id')
                    ->where('user_sessions.token', str_replace('Bearer ', '', $token))
                    ->where('users.active', 1)
                    ->where('user_sessions.expires_at', '>', Carbon::now(config('app.timezone')))
                    ->select('users.role', 'users.line_name', 'users.division')
                    ->first();

                if ($session) {
                    $userRole = $session->role;
                    $userLineName = $session->line_name;
                    $userDivision = $session->division;
                }
            } catch (\Exception $e) {
                // Continue without user info if token validation fails
                \Log::warning('Token validation failed in getStatusApi: ' . $e->getMessage());
            }
        }
        
        // Log request for debugging
        \Log::info('Dashboard status API called', [
            'has_token' => !empty($token),
            'user_role' => $userRole,
            'user_line' => $userLineName,
            'user_division' => $userDivision,
            'ip' => $request->ip()
        ]);

        $machineStatusesGroupedByLine = $this->getMachineStatuses($request); 
        $activeProblems = $this->getActiveProblems($request, $userRole, $userLineName, $userDivision);
        $newProblems = $this->getNewProblems($request, $userRole, $userLineName, $userDivision);
        
        return response()->json([
            'success' => true,
            'data' => [
                'machine_statuses_by_line' => $machineStatusesGroupedByLine,
                'active_problems' => $activeProblems,
                'new_problems' => $newProblems,
                'user_role' => $userRole,
                'user_line_name' => $userLineName,
                'user_division' => $userDivision,
                'timestamp' => Carbon::now(config('app.timezone'))->format('Y-m-d H:i:s')
            ]
        ]);
    }

    /**
     * Get problem baru dalam 10 detik terakhir (untuk notifikasi) dengan role-based visibility
     */
    public function getNewProblems(Request $request = null, $userRole = null, $userLineName = null, $userDivision = null)
    {
        $tenSecondsAgo = Carbon::now(config('app.timezone'))->subSeconds(10);
        
        $query = Log::active()
            ->where('timestamp', '>=', $tenSecondsAgo)
            ->orderBy('timestamp', 'desc');

        // PERBAIKAN: Tidak melakukan filtering di backend karena filtering dilakukan di Node.js
        // if ($userRole) {
        //     $query = $query->visibleToRole($userRole, $userLineName);
        // }

        // PERBAIKAN: Department users tidak boleh menerima notifikasi problem baru
        // Mereka hanya menerima notifikasi ketika problem di-forward ke mereka
        // if (in_array($userRole, ['maintenance', 'quality', 'engineering'])) {
        //     return collect([]); // Return empty collection untuk department users
        // }

        return $query->get()->map(function($problem) {
            return [
                'id' => $problem->id,
                'machine' => $problem->tipe_mesin,
                'machine_name' => $problem->tipe_mesin,
                'line_name' => $problem->line_name,
                'problem_type' => $problem->tipe_problem,
                'problemType' => $problem->tipe_problem,
                'timestamp' => Carbon::parse($problem->timestamp)->format('H:i:s'),
                'message' => "ALERT: {$problem->tipe_mesin} mengalami masalah {$problem->tipe_problem}!",
                'severity' => $this->getProblemSeverity($problem->tipe_problem),
                'description' => $this->getProblemDescription($problem->tipe_problem),
                'recommended_action' => $this->getRecommendedAction($problem->tipe_problem),
                'problem_status' => $problem->problem_status
            ];
        });
    }

    /**
     * Get problems that have been active for more than 15 minutes (for manager notifications)
     */
    public function getManagerUnresolvedProblems(Request $request = null)
    {
        try {
            $fifteenMinutesAgo = Carbon::now(config('app.timezone'))->subMinutes(15);
            
            // Get user division from request
            $userDivision = $request->input('user_division') ?? $request->header('X-User-Division');
            
            $query = DB::table('log')
                ->select([
                    'log.*',
                    'inspection_tables.name as machine_name',
                    'inspection_tables.line_name as table_line_name'
                ])
                ->join('inspection_tables', function($join) {
                    $join->on('log.tipe_mesin', '=', 'inspection_tables.address')
                         ->on('log.line_name', '=', 'inspection_tables.line_name');
                })
                ->where('log.status', 'ON')
                ->where('log.timestamp', '<=', $fifteenMinutesAgo)
                ->where('log.is_forwarded', false); // Belum di-forward
                
            // Filter based on manager division
            if ($userDivision) {
                $mapping = $this->getDivisionLineMapping();
                $allowedLines = $mapping[$userDivision] ?? [];
                
                if (!empty($allowedLines)) {
                    $query->whereIn('inspection_tables.line_name', $allowedLines);
                }
            }
            
            $unresolvedProblems = $query->orderBy('log.timestamp', 'asc')->get();

            $unresolvedProblemsData = $unresolvedProblems->map(function($problem) {
                $problemTimestamp = Carbon::createFromFormat('Y-m-d H:i:s', $problem->timestamp, config('app.timezone'));
                $duration = $problemTimestamp->diffInMinutes(Carbon::now(config('app.timezone')));
                
                return [
                    'id' => $problem->id,
                    'machine' => $problem->machine_name,
                    'machine_name' => $problem->machine_name,
                    'line_name' => $problem->table_line_name,
                    'problem_type' => $problem->tipe_problem,
                    'problemType' => $problem->tipe_problem,
                    'timestamp' => Carbon::parse($problem->timestamp)->format('d/m/Y H:i:s'),
                    'duration_minutes' => $duration,
                    'duration_text' => $duration . ' menit',
                    'message' => "ALERT MANAGER: {$problem->machine_name} mengalami masalah {$problem->tipe_problem} selama {$duration} menit tanpa penanganan!",
                    'severity' => 'critical',
                    'problem_status' => 'unresolved_long_time',
                    'is_manager_notification' => true
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $unresolvedProblemsData
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching unresolved problems for manager: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch unresolved problems for manager',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detail problem untuk popup
     */
    public function getProblemDetail($id)
    {
        $problem = Log::with(['forwardedByUser', 'receivedByUser', 'feedbackResolvedByUser'])->find($id);
        
        if (!$problem) {
            return response()->json([
                'success' => false,
                'message' => 'Problem tidak ditemukan'
            ], 404);
        }
        
        // PERBAIKAN: Gunakan timezone dari config untuk konsistensi
        $problemTimestamp = Carbon::createFromFormat('Y-m-d H:i:s', $problem->timestamp, config('app.timezone'));
        
        // Calculate problem status based on current state
        $problemStatus = 'active';
        if ($problem->status === 'OFF') {
            $problemStatus = 'resolved';
        } elseif ($problem->has_feedback_resolved) {
            $problemStatus = 'feedback_resolved';
        } elseif ($problem->is_received) {
            $problemStatus = 'received';
        } elseif ($problem->is_forwarded) {
            $problemStatus = 'forwarded';
        }
        
        $detail = [
            'id' => $problem->id,
            'machine' => $problem->tipe_mesin,
            'problem_type' => $problem->tipe_problem,
                'line_name' => $problem->line_name,
            'status' => $problem->status,
            'problem_status' => $problemStatus,
            'timestamp' => Carbon::parse($problem->timestamp)->format('d/m/Y H:i:s'),
            'duration' => $problemTimestamp->diffForHumans(),
            'severity' => $this->getProblemSeverity($problem->tipe_problem),
            'recommended_action' => $this->getRecommendedAction($problem->tipe_problem),
            'description' => $this->getProblemDescription($problem->tipe_problem),
            'is_forwarded' => $problem->is_forwarded,
            'forwarded_to_role' => $problem->forwarded_to_role,
            'forwarded_by' => $problem->forwardedByUser ? $problem->forwardedByUser->name : null,
            'forwarded_at' => $problem->forwarded_at ? Carbon::parse($problem->forwarded_at)->format('d/m/Y H:i:s') : null,
            'is_received' => $problem->is_received,
            'received_by' => $problem->receivedByUser ? $problem->receivedByUser->name : null,
            'received_at' => $problem->received_at ? Carbon::parse($problem->received_at)->format('d/m/Y H:i:s') : null,
            'has_feedback_resolved' => $problem->has_feedback_resolved,
            'feedback_resolved_by' => $problem->feedbackResolvedByUser ? $problem->feedbackResolvedByUser->name : null,
            'feedback_resolved_at' => $problem->feedback_resolved_at ? Carbon::parse($problem->feedback_resolved_at)->format('d/m/Y H:i:s') : null,
            'forward_message' => $problem->forward_message,
            'feedback_message' => $problem->feedback_message
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
            'Engineering' => 'medium',
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
            'Engineering' => 'Terdeteksi masalah engineering yang dapat menyebabkan efisiensi produksi',
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
            'Engineering' => 'Hentikan mesin dan hubungi tim engineering sesegera mungkin',
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
            $problem->resolved_at = Carbon::now(config('app.timezone')); // Mengambil waktu saat ini dengan timezone dari config

            // =====================================================================
            // == PERHITUNGAN DURASI FINAL YANG AKURAT ==
            // =====================================================================
            
            // 1. Ambil timestamp mentah dari DB untuk menghindari salah interpretasi dari Laravel
            $timestampString = $problem->getRawOriginal('timestamp');

            // 2. Buat objek waktu dari string mentah dengan timezone dari config
            $startTime = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $timestampString, config('app.timezone'));
            
            // 3. Hitung selisihnya dengan timezone yang sama (convert ke integer)
            $problem->duration_in_seconds = (int) abs($problem->resolved_at->diffInSeconds($startTime));
            
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
        // Ambil context untuk filtering berdasarkan role/division/line
        $lineName = $request->input('line_name');
        $division = $request->input('division') ?? $request->header('X-User-Division');
        $userRole = $request->input('user_role') ?? $request->header('X-User-Role');
        $userTimezone = config('app.timezone');

        // --- Query untuk Total Meja ---
        $totalMachinesQuery = \App\Models\InspectionTable::query();
        if ($lineName) {
            $totalMachinesQuery->where('line_name', $lineName);
        } elseif ($userRole === 'manager' && $division) {
            // Filter berdasar divisi manager jika dikirim
            $mapping = $this->getDivisionLineMapping();
            $allowedLines = $mapping[$division] ?? [];
            if (!empty($allowedLines)) {
                $totalMachinesQuery->whereIn('line_name', $allowedLines);
            }
        }

        // --- Query untuk Log Problem ---
        $logQuery = \App\Models\Log::query();
        if ($lineName) {
            $logQuery->where('line_name', $lineName);
        } elseif ($userRole === 'manager' && $division) {
            $mapping = $this->getDivisionLineMapping();
            $allowedLines = $mapping[$division] ?? [];
            if (!empty($allowedLines)) {
                $logQuery->whereIn('line_name', $allowedLines);
            }
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
                                                        ->where('tipe_problem', 'Machine')
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
            $nodeRedUrl = env('NODE_RED_URL', 'http://localhost:1880');
        
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

    public function forwardProblem(Request $request, $id)
    {
        // Ambil token dari request header
        $token = $request->bearerToken() ?? $request->header('Authorization');
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token required'
            ], 401);
        }

        // Validasi token dan ambil user data
        try {
            $session = DB::table('user_sessions')
                ->join('users', 'user_sessions.user_id', '=', 'users.id')
                ->where('user_sessions.token', str_replace('Bearer ', '', $token))
                ->where('users.active', 1)
                ->where('user_sessions.expires_at', '>', Carbon::now(config('app.timezone')))
                ->select('users.id', 'users.name', 'users.role', 'users.line_name')
                ->first();

            if (!$session) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Validasi bahwa yang melakukan forward adalah leader
            if ($session->role !== 'leader') {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya leader yang dapat melakukan forward problem.'
                ], 403);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error during authentication'
            ], 500);
        }

        // Cari problem yang statusnya masih 'ON' berdasarkan ID
        $problem = Log::where('id', $id)->where('status', 'ON')->first();

        if (!$problem) {
            return response()->json([
                'success' => false,
                'message' => 'Problem tidak ditemukan atau sudah diselesaikan.'
            ], 404);
        }

        // Cek apakah problem sudah pernah di-forward
        if ($problem->is_forwarded) {
            return response()->json([
                'success' => false,
                'message' => 'Problem ini sudah pernah diteruskan.'
            ], 400);
        }

        // Tentukan target user berdasarkan tipe problem
        $targetRole = null;
        switch (strtolower($problem->tipe_problem)) {
            case 'machine':
                $targetRole = 'maintenance';
                break;
            case 'quality':
                $targetRole = 'quality';
                break;
            case 'material':
                $targetRole = 'engineering';
                break;
            case 'engineering':
                $targetRole = 'engineering';
                break;
            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Tipe problem tidak dikenal untuk forward.'
                ], 400);
        }

        // Update problem di database
        $problem->update([
            'is_forwarded' => true,
            'forwarded_to_role' => $targetRole,
            'forwarded_by_user_id' => $session->id,
            'forwarded_at' => Carbon::now(config('app.timezone')),
            'forward_message' => $request->input('message', 'Problem telah diteruskan untuk penanganan.')
        ]);
        
        $forwardData = [
            'problem_id' => $problem->id,
            'machine_name' => $problem->tipe_mesin,
            'problem_type' => $problem->tipe_problem,
                'line_name' => $problem->line_name,
            'target_role' => $targetRole,
            'forwarded_by' => $session->name,
            'forwarded_by_id' => $session->id,
            'forwarded_at' => Carbon::now(config('app.timezone')),
            'message' => $request->input('message', 'Problem telah diteruskan untuk penanganan.')
        ];

        // Log forward event
        ForwardProblemLog::logEvent(
            $problem->id,
            ForwardProblemLog::EVENT_FORWARD,
            $session->id,
            $session->role,
            $targetRole,
            $request->input('message', 'Problem telah diteruskan untuk penanganan.'),
            [
                'machine_name' => $problem->tipe_mesin,
                'problem_type' => $problem->tipe_problem,
                'line_name' => $problem->line_name
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "Problem berhasil diteruskan ke tim {$targetRole}",
            'data' => $forwardData
        ]);
    }

    /**
     * Receive problem (untuk user department)
     */
    public function receiveProblem(Request $request, $id)
    {
        // Ambil token dari request header
        $token = $request->bearerToken() ?? $request->header('Authorization');
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token required'
            ], 401);
        }

        // Validasi token dan ambil user data
        try {
            $session = DB::table('user_sessions')
                ->join('users', 'user_sessions.user_id', '=', 'users.id')
                ->where('user_sessions.token', str_replace('Bearer ', '', $token))
                ->where('users.active', 1)
                ->where('user_sessions.expires_at', '>', Carbon::now(config('app.timezone')))
                ->select('users.id', 'users.name', 'users.role', 'users.line_name')
                ->first();

            if (!$session) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error during authentication'
            ], 500);
        }

        // Cari problem yang statusnya masih 'ON' berdasarkan ID
        $problem = Log::where('id', $id)->where('status', 'ON')->first();

        if (!$problem) {
            return response()->json([
                'success' => false,
                'message' => 'Problem tidak ditemukan atau sudah diselesaikan.'
            ], 404);
        }

        // Validasi bahwa problem bisa diterima oleh user ini
        if (!$problem->canBeReceivedBy((object)$session)) {
            return response()->json([
                'success' => false,
                'message' => 'Problem tidak bisa diterima oleh user ini.'
            ], 403);
        }

        // Update problem di database
        $problem->update([
            'is_received' => true,
            'received_by_user_id' => $session->id,
            'received_at' => Carbon::now(config('app.timezone'))
        ]);

        // Jika ada ticketing terkait problem ini dan belum memiliki waktu problem_received_at, set otomatis
        try {
            \App\Models\TicketingProblem::where('problem_id', $problem->id)
                ->whereNull('problem_received_at')
                ->update([
                    'problem_received_at' => Carbon::now(config('app.timezone'))
                ]);
        } catch (\Throwable $th) {
            // ignore soft-failure agar receive tetap sukses
        }

        // Log receive event
        ForwardProblemLog::logEvent(
            $problem->id,
            ForwardProblemLog::EVENT_RECEIVE,
            $session->id,
            $session->role,
            null,
            'Problem telah diterima untuk penanganan.',
            [
                'machine_name' => $problem->tipe_mesin,
                'problem_type' => $problem->tipe_problem,
                'line_name' => $problem->line_name
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Problem berhasil diterima',
            'data' => [
                'problem_id' => $problem->id,
                'received_by' => $session->name,
                'received_at' => Carbon::now(config('app.timezone'))->format('d/m/Y H:i:s')
            ]
        ]);
    }

    /**
     * Feedback resolved (untuk user department)
     */
    public function feedbackResolved(Request $request, $id)
    {
        // Ambil token dari request header
        $token = $request->bearerToken() ?? $request->header('Authorization');
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token required'
            ], 401);
        }

        // Validasi token dan ambil user data
        try {
            $session = DB::table('user_sessions')
                ->join('users', 'user_sessions.user_id', '=', 'users.id')
                ->where('user_sessions.token', str_replace('Bearer ', '', $token))
                ->where('users.active', 1)
                ->where('user_sessions.expires_at', '>', Carbon::now(config('app.timezone')))
                ->select('users.id', 'users.name', 'users.role', 'users.line_name')
                ->first();

            if (!$session) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error during authentication'
            ], 500);
        }

        // Cari problem yang statusnya masih 'ON' berdasarkan ID
        $problem = Log::where('id', $id)->where('status', 'ON')->first();

        if (!$problem) {
            return response()->json([
                'success' => false,
                'message' => 'Problem tidak ditemukan atau sudah diselesaikan.'
            ], 404);
        }

        // Validasi bahwa problem bisa di-feedback resolved oleh user ini
        if (!$problem->canBeFeedbackResolvedBy((object)$session)) {
            return response()->json([
                'success' => false,
                'message' => 'Problem tidak bisa di-feedback resolved oleh user ini.'
            ], 403);
        }

        // Update problem di database
        $problem->update([
            'has_feedback_resolved' => true,
            'feedback_resolved_by_user_id' => $session->id,
            'feedback_resolved_at' => Carbon::now(config('app.timezone')),
            'feedback_message' => $request->input('message', 'Problem sudah selesai ditangani.')
        ]);

        // Jika ada ticketing terkait problem ini dan belum memiliki waktu repair_completed_at, set otomatis
        try {
            \App\Models\TicketingProblem::where('problem_id', $problem->id)
                ->whereNull('repair_completed_at')
                ->update([
                    'repair_completed_at' => Carbon::now(config('app.timezone'))
                ]);
        } catch (\Throwable $th) {
            // ignore soft-failure agar feedback tetap sukses
        }

        // Log feedback resolved event
        ForwardProblemLog::logEvent(
            $problem->id,
            ForwardProblemLog::EVENT_FEEDBACK_RESOLVED,
            $session->id,
            $session->role,
            null,
            $request->input('message', 'Problem sudah selesai ditangani.'),
            [
                'machine_name' => $problem->tipe_mesin,
                'problem_type' => $problem->tipe_problem,
                'line_name' => $problem->line_name
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Feedback problem selesai berhasil dikirim ke leader',
            'data' => [
                'problem_id' => $problem->id,
                'feedback_by' => $session->name,
                'feedback_at' => Carbon::now('Asia/Jakarta')->format('d/m/Y H:i:s'),
                'message' => $request->input('message', 'Problem sudah selesai ditangani.')
            ]
        ]);
    }

    /**
     * Final resolved (untuk leader)
     */
    public function finalResolved(Request $request, $id)
    {
        // Ambil token dari request header
        $token = $request->bearerToken() ?? $request->header('Authorization');
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token required'
            ], 401);
        }

        // Validasi token dan ambil user data
        try {
            $session = DB::table('user_sessions')
                ->join('users', 'user_sessions.user_id', '=', 'users.id')
                ->where('user_sessions.token', str_replace('Bearer ', '', $token))
                ->where('users.active', 1)
                ->where('user_sessions.expires_at', '>', Carbon::now(config('app.timezone')))
                ->select('users.id', 'users.name', 'users.role', 'users.line_name')
                ->first();

            if (!$session) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Validasi bahwa yang melakukan final resolved adalah leader
            if ($session->role !== 'leader') {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya leader yang dapat melakukan final resolved problem.'
                ], 403);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error during authentication'
            ], 500);
        }

        // Cari problem yang statusnya masih 'ON' berdasarkan ID
        $problem = Log::where('id', $id)->where('status', 'ON')->first();

        if (!$problem) {
            return response()->json([
                'success' => false,
                'message' => 'Problem tidak ditemukan atau sudah diselesaikan.'
            ], 404);
        }

        // Validasi bahwa problem bisa di-final resolved oleh user ini
        // Cek apakah ini direct resolve atau final resolve setelah feedback
        $isDirectResolve = !$problem->is_forwarded;
        
        if ($isDirectResolve) {
            // Direct resolve - problem belum di-forward
            if (!$problem->canBeDirectResolvedBy((object)$session)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Problem tidak bisa di-direct resolved oleh user ini.'
                ], 403);
            }
        } else {
            // Final resolve setelah feedback
            if (!$problem->canBeFinalResolvedBy((object)$session)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Problem tidak bisa di-final resolved oleh user ini.'
                ], 403);
            }
        }

        // Update problem status ke OFF
        $problem->status = 'OFF';
        $problem->resolved_at = Carbon::now(config('app.timezone'));

        // Hitung durasi final yang akurat
        $timestampString = $problem->getRawOriginal('timestamp');
        $startTime = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $timestampString, config('app.timezone'));
        $problem->duration_in_seconds = (int) abs($problem->resolved_at->diffInSeconds($startTime));

        $problem->save();

        // Log resolved event
        $eventType = $isDirectResolve ? 'direct_resolved' : ForwardProblemLog::EVENT_FINAL_RESOLVED;
        $message = $isDirectResolve ? 'Problem diselesaikan secara langsung oleh leader.' : 'Problem diselesaikan secara final oleh leader.';
        
        ForwardProblemLog::logEvent(
            $problem->id,
            $eventType,
            $session->id,
            $session->role,
            null,
            $message,
            [
                'machine_name' => $problem->tipe_mesin,
                'problem_type' => $problem->tipe_problem,
                'line_name' => $problem->line_name,
                'duration_seconds' => $problem->duration_in_seconds,
                'is_direct_resolve' => $isDirectResolve
            ]
        );

        return response()->json([
            'success' => true,
            'message' => $isDirectResolve ? 'Problem berhasil diselesaikan secara langsung' : 'Problem berhasil diselesaikan secara final',
            'data' => [
                'problem_id' => $problem->id,
                'resolved_by' => $session->name,
                'resolved_at' => Carbon::now(config('app.timezone'))->format('d/m/Y H:i:s'),
                'duration_seconds' => $problem->duration_in_seconds
            ]
        ]);
    }

    /**
     * Get forward problem logs untuk analisis
     */
    public function getForwardLogs(Request $request, $problemId = null)
    {
        $query = ForwardProblemLog::with(['problem', 'user'])
            ->orderBy('event_timestamp', 'desc');

        if ($problemId) {
            $query->where('problem_id', $problemId);
        }

        // Filter berdasarkan tanggal jika ada
        if ($request->has('start_date')) {
            $query->where('event_timestamp', '>=', $request->input('start_date'));
        }

        if ($request->has('end_date')) {
            $query->where('event_timestamp', '<=', $request->input('end_date'));
        }

        $logs = $query->get()->map(function($log) {
            return [
                'id' => $log->id,
                'problem_id' => $log->problem_id,
                'event_type' => $log->event_type,
                'event_description' => $log->event_description,
                'user_name' => $log->user ? $log->user->name : 'Unknown',
                'user_role' => $log->user_role,
                'target_role' => $log->target_role,
                'message' => $log->message,
                'event_timestamp' => $log->formatted_event_timestamp,
                'machine_name' => $log->problem ? $log->problem->tipe_mesin : 'Unknown',
                'problem_type' => $log->problem ? $log->problem->tipe_problem : 'Unknown',
                'line_name' => $log->problem ? $log->problem->line_name : 'Unknown',
                'metadata' => $log->metadata
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $logs
        ]);
    }

    /**
     * Get active problems with JOIN to inspection_tables to get machine names
     */
    public function getActiveProblemsApi(Request $request)
    {
        try {
            // Get user role and division from request
            $userRole = $request->header('X-User-Role');
            $userDivision = $request->header('X-User-Division');
            
            // Get the latest log entry for each machine (GROUP BY tipe_mesin)
            // Filter status 'ON' and JOIN with inspection_tables
            // PERBAIKAN: Tambahkan filtering berdasarkan line_name untuk mencegah cross-line problem
            $query = DB::table('log')
                ->select([
                    'log.id',
                    'log.tipe_mesin',
                    'log.line_name',
                    'log.tipe_problem',
                    'log.status',
                    'log.timestamp',
                    'log.is_forwarded',
                    'log.forwarded_to_role',
                    'inspection_tables.name as machine_name',
                    'inspection_tables.address'
                ])
                ->join('inspection_tables', function($join) {
                    $join->on('log.tipe_mesin', '=', 'inspection_tables.address')
                         ->on('log.line_name', '=', 'inspection_tables.line_name');
                })
                ->where('log.status', 'ON');
                
            // Filter based on user role and division
            if ($userRole === 'manager' && $userDivision) {
                $mapping = $this->getDivisionLineMapping();
                $allowedLines = $mapping[$userDivision] ?? [];
                
                if (!empty($allowedLines)) {
                    $query->whereIn('inspection_tables.line_name', $allowedLines);
                }
            }
            
            $activeProblems = $query->orderBy('log.timestamp', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $activeProblems
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching active problems: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch active problems',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add new problem with duplicate prevention logic
     */
    public function addProblem(Request $request)
    {
        try {
            $validated = $request->validate([
                'tipe_mesin' => 'required|string|max:20',
                'tipe_problem' => 'required|string|max:50',
                'line_name' => 'required|string|max:50'
            ]);

            // Check if there's already an active problem with the same characteristics
            $existingProblem = DB::table('log')
                ->where('tipe_mesin', $validated['tipe_mesin'])
                ->where('tipe_problem', $validated['tipe_problem'])
                ->where('line_name', $validated['line_name'])
                ->where('status', 'ON')
                ->first();

            if ($existingProblem) {
                // Problem already exists with same characteristics, don't create duplicate
                return response()->json([
                    'success' => false,
                    'message' => 'Problem dengan karakteristik yang sama sudah aktif',
                    'duplicate' => true,
                    'existing_problem_id' => $existingProblem->id
                ], 409); // 409 Conflict
            }

            // Create new problem
            $problemId = DB::table('log')->insertGetId([
                'timestamp' => Carbon::now(config('app.timezone')),
                'tipe_mesin' => $validated['tipe_mesin'],
                'tipe_problem' => $validated['tipe_problem'],
                'line_name' => $validated['line_name'],
                'status' => 'ON',
                'is_forwarded' => 0,
                'forwarded_to_role' => null,
                'forwarded_by_user_id' => null,
                'forwarded_at' => null,
                'forward_message' => null,
                'is_received' => 0,
                'received_by_user_id' => null,
                'received_at' => null,
                'has_feedback_resolved' => 0,
                'feedback_resolved_by_user_id' => null,
                'feedback_resolved_at' => null,
                'feedback_message' => null
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Problem berhasil ditambahkan',
                'problem_id' => $problemId
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Error adding problem: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to add problem',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}