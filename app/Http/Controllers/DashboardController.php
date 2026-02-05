<?php

namespace App\Http\Controllers;

use App\Models\Log;
use App\Models\ProductionData;
use App\Models\InspectionTable;
use App\Models\ForwardProblemLog;
use App\Models\Division;
use App\Models\Line;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Carbon\Carbon;

class DashboardController extends Controller
{
    // Cache untuk cycle-based status calculation (untuk mengurangi beban sistem)
    private static $cycleStatusCache = [];
    private static $cycleStatusCacheTime = [];
    private const CYCLE_STATUS_CACHE_TTL = 5; // Cache TTL dalam detik (5 detik)
    
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

    private function normalizeControlledTablesInput($tablesInput)
    {
        if (is_null($tablesInput) || $tablesInput === '') {
            return null;
        }

        $decoded = $tablesInput;
        if (is_string($tablesInput)) {
            $decoded = json_decode($tablesInput, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // If the payload is not valid JSON, keep original value
                return $tablesInput;
            }
        }

        if (!is_array($decoded)) {
            return $tablesInput;
        }

        $tables = InspectionTable::select('name', 'address')->get();
        $addressLookup = [];
        $nameLookup = [];

        foreach ($tables as $table) {
            if (!empty($table->address)) {
                $addressLookup[strtolower(trim($table->address))] = $table->address;
            }
            if (!empty($table->name)) {
                $nameLookup[strtolower(trim($table->name))] = $table->address;
            }
        }

        $normalized = [];

        foreach ($decoded as $entry) {
            $candidateAddress = null;
            $candidateName = null;

            if (is_array($entry)) {
                $candidateAddress = $entry['address'] ?? null;
                $candidateName = $entry['name'] ?? null;
            } elseif (is_string($entry)) {
                $candidateAddress = $entry;
            }

            if ($candidateAddress) {
                $addressKey = strtolower(trim($candidateAddress));
                if ($addressKey !== '' && isset($addressLookup[$addressKey])) {
                    $normalized[] = $addressLookup[$addressKey];
                    continue;
                }
            }

            if ($candidateName) {
                $nameKey = strtolower(trim($candidateName));
                if ($nameKey !== '' && isset($nameLookup[$nameKey])) {
                    $normalized[] = $nameLookup[$nameKey];
                    continue;
                }
            }

            if ($candidateAddress) {
                $normalized[] = trim($candidateAddress);
            }
        }

        if (empty($normalized)) {
            return json_encode([]);
        }

        $unique = array_values(array_unique(array_filter($normalized)));
        $encoded = json_encode($unique);
        return $encoded === false ? null : $encoded;
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
     * Now uses database instead of hardcoded values
     */
    private function getDivisionLineMapping()
    {
        try {
            $divisions = Division::with('lines')->orderBy('name')->get();
            $mapping = [];
            
            foreach ($divisions as $division) {
                $key = strtolower(trim($division->name));
                $mapping[$key] = $division->lines->pluck('name')->toArray();
            }
            
            return $mapping;
        } catch (\Exception $e) {
            \Log::error('Error getting division line mapping: ' . $e->getMessage());
            // Fallback to empty array if database query fails
            return [];
        }
    }

    /**
     * Get divisions and lines with machine counts and active problems
     * Now uses database instead of hardcoded values
     */
    public function getDivisionsAndLines(Request $request)
    {
        try {
            $userRole = $request->input('user_role') ?? $request->header('X-User-Role');
            $userDivision = $request->input('user_division') ?? $request->header('X-User-Division');
            
            // Get divisions from database
            $query = Division::with('lines');
            
            // Determine which divisions to show
            if (in_array($userRole, ['admin', 'management', 'maintenance', 'quality', 'engineering'])) {
                // Show all divisions
                // No filter needed
            } elseif ($userRole === 'manager' && $userDivision) {
                // Show only manager's division
                $query->where('name', $userDivision);
            }
            // Default: show all if role not recognized
            
            $divisions = $query->orderBy('name')->get();
            
            $result = [];
            
            foreach ($divisions as $division) {
                $divisionData = [
                    'name' => $division->name,
                    'lines' => []
                ];
                
                foreach ($division->lines as $line) {
                    $lineName = $line->name;
                    // Count total machines for this line
                    $totalMachines = InspectionTable::where('line_name', $lineName)->count();
                    
                    // Get all tables for this line
                    $tables = InspectionTable::where('line_name', $lineName)->get();
                    $addresses = $tables->pluck('address')->toArray();
                    
                    // Get ALL active problems from log table (tidak di-keyBy agar bisa menghitung semua problem)
                    // BUGFIX: Count semua problem aktif, bukan hanya unique machine
                    $allActiveProblemsFromLog = DB::table('log')
                        ->select([
                            'log.*',
                            'inspection_tables.name as machine_name',
                            'inspection_tables.address as machine_address',
                            'inspection_tables.line_name as table_line_name'
                        ])
                        ->join('inspection_tables', function($join) {
                            $join->on('log.tipe_mesin', '=', 'inspection_tables.address')
                                 ->on('log.line_name', '=', 'inspection_tables.line_name');
                        })
                        ->where('log.status', 'ON')
                        ->where('inspection_tables.line_name', $lineName)
                        ->get();
                    
                    // Group active problems by address for checking machine status
                    $activeProblemsByAddress = $allActiveProblemsFromLog->groupBy('machine_address');
                    
                    // Initialize counters
                    $runningCount = 0;
                    $idleCount = 0;
                    $stopCount = 0;
                    $cycleBasedProblemsCount = 0;
                    
                    // Define threshold untuk menentukan production data masih aktif (5 menit terakhir)
                    $productionActiveThreshold = Carbon::now()->subMinutes(5);
                    
                    if (!empty($addresses)) {
                        // Get latest production data
                        try {
                            $latestProductions = DB::table('production_data')
                                ->select([
                                    'production_data.*',
                                    'inspection_tables.name as machine_name',
                                    'inspection_tables.line_name as table_line_name',
                                    'inspection_tables.address as machine_address'
                                ])
                                ->join('inspection_tables', function($join) {
                                    $join->on('production_data.machine_name', '=', 'inspection_tables.address')
                                         ->on('production_data.line_name', '=', 'inspection_tables.line_name');
                                })
                                ->whereIn('production_data.machine_name', $addresses)
                                ->orderBy('production_data.timestamp', 'desc')
                                ->get()
                                ->unique(function($item) {
                                    return ($item->machine_address ?? '') . '_line_' . ($item->table_line_name ?? 'default');
                                })
                                ->keyBy(function($item) {
                                    return ($item->machine_address ?? '') . '_line_' . ($item->table_line_name ?? 'default');
                                });
                        } catch (\Exception $e) {
                            \Log::warning('Error fetching production data for line ' . $lineName . ': ' . $e->getMessage());
                            $latestProductions = collect(); // Empty collection if query fails
                        }
                        
                        // Check each machine status
                        foreach ($tables as $table) {
                            $machineAddress = $table->address;
                            $machineName = $table->name;
                            $lineNameTable = $table->line_name;
                            
                            // Use address as key for both problems and production data
                            $problemKey = $machineAddress;
                            $productionKey = $machineAddress . '_line_' . $lineNameTable;
                            
                            // Check for active problem from log (by address)
                            $hasActiveProblem = $activeProblemsByAddress->has($machineAddress);
                            
                            // Get latest production data
                            $latestProduction = $latestProductions->get($productionKey);
                            if (!$latestProduction) {
                                // Try to find by address directly (without line check)
                                $latestProduction = $latestProductions->first(function($item) use ($machineAddress) {
                                    return isset($item->machine_address) && $item->machine_address === $machineAddress;
                                });
                            }
                            
                            // Additional fallback: try to find by address directly if still not found
                            if (!$latestProduction) {
                                $latestProduction = DB::table('production_data')
                                    ->where('machine_name', $machineAddress)
                                    ->orderBy('timestamp', 'desc')
                                    ->first();
                            }
                            
                            // Check if production data is still active (within threshold)
                            $isProductionActive = false;
                            if ($latestProduction) {
                                try {
                                    $productionTimestamp = Carbon::parse($latestProduction->timestamp);
                                    $isProductionActive = $productionTimestamp->gte($productionActiveThreshold);
                                } catch (\Exception $e) {
                                    $isProductionActive = false;
                                }
                            }
                            
                            // Check cycle-based status (with error handling)
                            try {
                                $cacheKey = $table->id . '_' . ($latestProduction ? ($latestProduction->quantity ?? 'no_data') : 'no_data');
                                $cycleBasedStatus = $this->checkCycleBasedStatusWithCache($table, $latestProduction, $cacheKey);
                            } catch (\Exception $e) {
                                // If cycle status check fails, default to normal
                                \Log::warning('Error checking cycle status for ' . $machineName . ': ' . $e->getMessage());
                                $cycleBasedStatus = ['status' => 'normal', 'cycles_without_increase' => 0];
                            }
                            
                            // Determine machine status
                            // Priority: activeProblem > cycleBasedProblem > cycleBasedWarning > normal/running
                            if ($hasActiveProblem) {
                                // Machine has active problem - Stop
                                $stopCount++;
                                if (isset($cycleBasedStatus['status']) && $cycleBasedStatus['status'] === 'problem') {
                                    $cycleBasedProblemsCount++;
                                }
                            } elseif (isset($cycleBasedStatus['status']) && $cycleBasedStatus['status'] === 'problem') {
                                // Cycle-based problem - Stop
                                $stopCount++;
                                $cycleBasedProblemsCount++;
                            } elseif (isset($cycleBasedStatus['status']) && $cycleBasedStatus['status'] === 'warning') {
                                // Cycle-based warning - Idle (quantity not increasing)
                                $idleCount++;
                            } elseif ($isProductionActive || $latestProduction) {
                                // Machine has production data (active or not recent) and no problems - Running
                                // Fix: Mesin tanpa problem yang punya production data dianggap running, bukan idle
                                $runningCount++;
                            } else {
                                // No production data - consider as Stop (offline)
                                $stopCount++;
                            }
                        }
                    } else {
                        // No machines in this line
                        $stopCount = $totalMachines;
                    }
                    
                    // BUGFIX: Count semua problem aktif, bukan hanya unique machine
                    $totalActiveProblems = $allActiveProblemsFromLog->count() + $cycleBasedProblemsCount;
                    
                    $divisionData['lines'][] = [
                        'name' => $lineName,
                        'total_machines' => $totalMachines,
                        'active_problems' => $totalActiveProblems,
                        'running_count' => $runningCount,
                        'idle_count' => $idleCount,
                        'stop_count' => $stopCount
                    ];
                }
                
                $result[] = $divisionData;
            }
            
            return response()->json([
                'success' => true,
                'data' => $result
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error getting divisions and lines: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Error getting divisions and lines: ' . $e->getMessage(),
                'error_details' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }


    /**
     * Check if manager can see specific line based on their division
     * Now uses database instead of hardcoded values
     */
    private function canManagerSeeLine($managerDivision, $lineName)
    {
        try {
            $division = Division::where('name', $managerDivision)
                ->with('lines')
                ->first();
            
            if (!$division) {
                return false;
            }
            
            return $division->lines->contains('name', $lineName);
        } catch (\Exception $e) {
            \Log::error('Error checking manager line access: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get status semua mesin (untuk display lampu indikator)
     */
    public function getMachineStatuses(Request $request)
    {
        // Get user role and division from request or session
        $userRole = $request->input('user_role') ?? $request->header('X-User-Role');
        $userDivision = $request->input('user_division') ?? $request->header('X-User-Division');
        $userDivisionKey = strtolower(trim((string) $userDivision));
        // PERBAIKAN: Ambil line_name dari request untuk filtering
        $lineName = $request->input('line_name') ?? $request->header('X-Line-Name');
        
        
        // Ambil meja dengan filtering
        $allInspectionTables = InspectionTable::query();
        
        // Filter berdasarkan line_name jika ada
        if ($lineName) {
            $allInspectionTables->where('line_name', $lineName);
        }
        
        // Filter tables based on user role and division
        if ($userRole === 'manager' && $userDivisionKey !== '') {
            $mapping = $this->getDivisionLineMapping();
            $allowedLines = $mapping[$userDivisionKey] ?? [];
            
            if (!empty($allowedLines)) {
                // Normalize allowed line keys
                $allowedLineKeys = array_map(fn($v) => strtolower(trim($v)), $allowedLines);
                // Pastikan hanya memakai line yang benar-benar ada di inspection_tables
                $existingLines = InspectionTable::whereIn(DB::raw('lower(trim(line_name))'), $allowedLineKeys)
                    ->distinct()
                    ->pluck('line_name')
                    ->toArray();
                
                if (!empty($existingLines)) {
                    if ($lineName) {
                        $lineKey = strtolower(trim($lineName));
                        $existingKeys = array_map(fn($v) => strtolower(trim($v)), $existingLines);
                        if (!in_array($lineKey, $existingKeys)) {
                            $allInspectionTables->whereRaw('1 = 0'); // Return empty result
                        }
                    } else {
                        // Filter berdasarkan allowedLines yang eksis (normalize)
                        $allInspectionTables->whereIn(DB::raw('lower(trim(line_name))'), $allowedLineKeys);
                    }
                }
                // Jika mapping ada tapi tidak ada line yang cocok di database,
                // jangan melakukan filter tambahan agar manager tetap melihat data.
            }
        }
        
        $allInspectionTables = $allInspectionTables->get();
        
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

        // Ambil data produksi terbaru dengan JOIN ke inspection_tables untuk mendapatkan address
        $addresses = $allInspectionTables->pluck('address')->toArray();
        $latestProductions = DB::table('production_data')
            ->select([
                'production_data.*',
                'inspection_tables.name as machine_name',
                'inspection_tables.line_name as table_line_name'
            ])
            ->join('inspection_tables', function($join) {
                // Relax join: match by address only to tolerate missing/mismatched line_name in production_data
                $join->on('production_data.machine_name', '=', 'inspection_tables.address');
            })
            ->whereIn('production_data.machine_name', $addresses)
            ->orderBy('production_data.timestamp', 'desc')
            ->get()
            ->unique(function($item) {
                // PERBAIKAN: Gunakan kombinasi machine_name dan line_name untuk uniqueness
                return $item->machine_name . '_line_' . ($item->table_line_name ?? 'default');
            })
            ->keyBy(function($item) {
                return $item->machine_name . '_line_' . ($item->table_line_name ?? 'default');
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
            if (!$latestProduction) {
                // Fallback: use the first record for this machine regardless of line
                $latestProduction = $latestProductions->first(function($item) use ($machineName) {
                    return $item->machine_name === $machineName;
                });
            }
            
            // Additional fallback: try to find by address directly if still not found
            if (!$latestProduction) {
                $latestProduction = DB::table('production_data')
                    ->where('machine_name', $table->address)
                    ->orderBy('timestamp', 'desc')
                    ->first();
            }

            // Check cycle-based status (warning/problem based on quantity not increasing)
            // Use cached result if available and still valid, or if quantity hasn't changed
            $cacheKey = $table->id . '_' . ($latestProduction ? $latestProduction->quantity : 'no_data');
            $cycleBasedStatus = $this->checkCycleBasedStatusWithCache($table, $latestProduction, $cacheKey);
            
            // Determine final status: priority: activeProblem > cycleBasedProblem > cycleBasedWarning > normal
            $finalStatus = 'normal';
            $finalColor = 'green';
            $problemType = null;
            $timestamp = null;
            
            if ($activeProblem) {
                // Active problem from log takes priority
                $finalStatus = 'problem';
                $finalColor = 'red';
                $problemType = $activeProblem->tipe_problem;
                $timestamp = $activeProblem->timestamp;
            } elseif ($cycleBasedStatus['status'] === 'problem') {
                // Cycle-based problem
                $finalStatus = 'problem';
                $finalColor = 'red';
                $problemType = 'Cycle Time';
            } elseif ($cycleBasedStatus['status'] === 'warning') {
                // Cycle-based warning
                $finalStatus = 'warning';
                $finalColor = 'yellow';
            }

            $statusData = [
                'name' => $machineName,
                'line_name' => $lineName, // TAMBAHAN: Sertakan line_name dalam response
                'address' => $table->address,
                'status' => $finalStatus,
                'color' => $finalColor,
                'problem_type' => $problemType,
                'timestamp' => $timestamp,
                'last_check' => Carbon::now(config('app.timezone'))->format('Y-m-d H:i:s'),
                'quantity' => $latestProduction ? $latestProduction->quantity : 0,
                'id' => $table->id,
                'cycle_based_status' => $cycleBasedStatus, // Include cycle-based status for reference
                'ot_enabled' => (bool) ($table->ot_enabled ?? false),
                'ot_duration_type' => $table->ot_duration_type,
                'target_ot' => $table->target_ot !== null ? (int) $table->target_ot : null,
            ];
            
            // Only log when status is not normal (to reduce log volume)
            if ($finalStatus !== 'normal') {
                \Log::info("Final status changed for {$machineName} (line: {$lineName}):", [
                    'final_status' => $finalStatus,
                    'cycle_based_status' => $cycleBasedStatus['status'] ?? 'normal'
                ]);
            }

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

        // Ambil data produksi terbaru dengan JOIN ke inspection_tables untuk mendapatkan address
        $addresses = $allInspectionTables->pluck('address')->toArray();
        $latestProductions = DB::table('production_data')
            ->select([
                'production_data.*',
                'inspection_tables.name as machine_name',
                'inspection_tables.line_name as table_line_name'
            ])
            ->join('inspection_tables', function($join) {
                $join->on('production_data.machine_name', '=', 'inspection_tables.address');
            })
            ->whereIn('production_data.machine_name', $addresses)
            ->orderBy('production_data.timestamp', 'desc')
            ->get()
            ->unique(function($item) {
                // PERBAIKAN: Gunakan kombinasi machine_name dan line_name untuk uniqueness
                return $item->machine_name . '_line_' . ($item->table_line_name ?? 'default');
            })
            ->keyBy(function($item) {
                return $item->machine_name . '_line_' . ($item->table_line_name ?? 'default');
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

            if (!$latestProduction) {
                $latestProduction = $latestProductions->firstWhere('machine_name', $machineName);
            }
            
            // Additional fallback: try to find by address directly if still not found
            if (!$latestProduction) {
                $latestProduction = DB::table('production_data')
                    ->where('machine_name', $table->address)
                    ->orderBy('timestamp', 'desc')
                    ->first();
            }

            // Check cycle-based status (warning/problem based on quantity not increasing)
            // Use cached result if available and still valid, or if quantity hasn't changed
            $cacheKey = $table->id . '_' . ($latestProduction ? $latestProduction->quantity : 'no_data');
            $cycleBasedStatus = $this->checkCycleBasedStatusWithCache($table, $latestProduction, $cacheKey);
            
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
            
            // Determine final status: priority: activeProblem > cycleBasedProblem > cycleBasedWarning > normal
            $finalStatus = $machineStatus;
            $finalColor = $machineStatus === 'problem' ? 'red' : 'green';
            
            if ($machineStatus === 'normal') {
                // Only check cycle-based status if no active problem
                if ($cycleBasedStatus['status'] === 'problem') {
                    $finalStatus = 'problem';
                    $finalColor = 'red';
                    $problemType = 'Cycle Time';
                } elseif ($cycleBasedStatus['status'] === 'warning') {
                    $finalStatus = 'warning';
                    $finalColor = 'yellow';
                }
            }

            $statusData = [
                'name' => $machineName,
                'line_name' => $lineName,
                'address' => $table->address,
                'status' => $finalStatus,
                'color' => $finalColor,
                'problem_type' => $problemType,
                'timestamp' => $timestamp,
                'last_check' => Carbon::now(config('app.timezone'))->format('Y-m-d H:i:s'),
                'quantity' => $latestProduction ? $latestProduction->quantity : 0,
                'id' => $table->id,
                'cycle_based_status' => $cycleBasedStatus, // Include cycle-based status for reference
                'ot_enabled' => (bool) ($table->ot_enabled ?? false),
                'ot_duration_type' => $table->ot_duration_type,
                'target_ot' => $table->target_ot !== null ? (int) $table->target_ot : null,
            ];
            
            // Only log when status is not normal (to reduce log volume)
            if ($finalStatus !== 'normal') {
                \Log::info("Final status changed (role-filtered) for {$machineName} (line: {$lineName}):", [
                    'final_status' => $finalStatus,
                    'cycle_based_status' => $cycleBasedStatus['status'] ?? 'normal'
                ]);
            }

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
        $userDivisionKey = strtolower(trim((string) $userDivision));
        if ($userRole === 'manager' && $userDivisionKey !== '') {
            $mapping = $this->getDivisionLineMapping();
            $allowedLines = $mapping[$userDivisionKey] ?? [];
            
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
                'forward_photo' => $problem->forward_photo ? url($problem->forward_photo) : null,
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
        
        // Ambil line_name dari query parameter atau header untuk filtering
        $lineName = $request->input('line_name') ?? $request->header('X-Line-Name');

        $request->merge(['line_name' => $lineName]);
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
            'forward_photo' => $problem->forward_photo ? url($problem->forward_photo) : null,
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
        // Block write operations for management role
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

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
                $existingLines = \App\Models\InspectionTable::whereIn('line_name', $allowedLines)
                    ->distinct()
                    ->pluck('line_name')
                    ->toArray();
                if (!empty($existingLines)) {
                    $totalMachinesQuery->whereIn('line_name', $existingLines);
                }
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
                $existingLines = \App\Models\InspectionTable::whereIn('line_name', $allowedLines)
                    ->distinct()
                    ->pluck('line_name')
                    ->toArray();
                if (!empty($existingLines)) {
                    $logQuery->whereIn('line_name', $existingLines);
                }
            }
        }
        
        // Buat klon query untuk penggunaan berulang agar lebih efisien
        $activeProblemsQuery = clone $logQuery;
        $resolvedTodayQuery = clone $logQuery;
        $criticalProblemsQuery = clone $logQuery;

        // Count active problems from log (machine problems)
        $activeProblemsFromLog = $activeProblemsQuery->where('status', 'ON')->count();
        
        // Count cycle-based problems (quantity not increasing)
        $cycleBasedProblemsCount = 0;
        $allTables = $totalMachinesQuery->get();
        
        // Get latest production data for all tables
        $addresses = $allTables->pluck('address')->toArray();
        $latestProductions = DB::table('production_data')
            ->select([
                'production_data.*',
                'inspection_tables.name as machine_name',
                'inspection_tables.line_name as table_line_name'
            ])
            ->join('inspection_tables', function($join) {
                $join->on('production_data.machine_name', '=', 'inspection_tables.address')
                     ->on('production_data.line_name', '=', 'inspection_tables.line_name');
            })
            ->whereIn('production_data.machine_name', $addresses)
            ->orderBy('production_data.timestamp', 'desc')
            ->get()
            ->unique(function($item) {
                return $item->machine_name . '_line_' . ($item->table_line_name ?? 'default');
            })
            ->keyBy(function($item) {
                return $item->machine_name . '_line_' . ($item->table_line_name ?? 'default');
            });
        
        // Check each table for cycle-based problems
        foreach ($allTables as $table) {
            $machineName = $table->name;
            $lineName = $table->line_name;
            $productionKey = $machineName . '_line_' . $lineName;
            $latestProduction = $latestProductions->get($productionKey);
            
            if (!$latestProduction) {
                // Fallback: cari berdasarkan machine name saja jika data produksi tidak menemukan line yang cocok
                $latestProduction = $latestProductions->firstWhere('machine_name', $machineName);
            }

            // Check if this table has cycle-based problem status
            // Use cached result if available and still valid, or if quantity hasn't changed
            $cacheKey = $table->id . '_' . ($latestProduction ? $latestProduction->quantity : 'no_data');
            $cycleBasedStatus = $this->checkCycleBasedStatusWithCache($table, $latestProduction, $cacheKey);
            if ($cycleBasedStatus['status'] === 'problem') {
                $cycleBasedProblemsCount++;
            }
        }
        
        // Total active problems = log problems + cycle-based problems
        $totalActiveProblems = $activeProblemsFromLog + $cycleBasedProblemsCount;

        $stats = [
            'total_machines' => $totalMachinesQuery->count(),
            'active_problems' => $totalActiveProblems,
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

    public function getPlcStatusFromDatabase()
    {
        try {
            $devices = DB::table('device_status')->get();
            
            return response()->json([
                'success' => true,
                'data' => $devices
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching device status: ' . $e->getMessage()
            ], 500);
        }
    }

    public function createPlcDevice(Request $request)
    {
        // Block write operations for management role
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

        try {
            $request->validate([
                'device_id' => 'required|string|max:50|unique:device_status,device_id',
                'device_name' => 'nullable|string|max:100',
                'status' => 'required|string|max:20',
                'details' => 'nullable|string',
                'controlled_tables' => 'nullable' // JSON string of table names
            ]);

            $controlledTables = $this->normalizeControlledTablesInput($request->input('controlled_tables'));

            $deviceId = DB::table('device_status')->insertGetId([
                'device_id' => $request->device_id,
                'device_name' => $request->device_name,
                'status' => $request->status,
                'last_seen' => now(),
                'details' => $request->details,
                // Persist controlled tables as provided (frontend sends JSON string)
                'controlled_tables' => $controlledTables,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'PLC device created successfully',
                'data' => ['id' => $deviceId]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating device: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updatePlcDevice(Request $request, $id)
    {
        // Block write operations for management role
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

        try {
            $request->validate([
                'device_id' => 'required|string|max:50|unique:device_status,device_id,' . $id,
                'device_name' => 'nullable|string|max:100',
                'status' => 'required|string|max:20',
                'details' => 'nullable|string',
                'controlled_tables' => 'nullable' // JSON string of table names
            ]);

            $controlledTables = $this->normalizeControlledTablesInput($request->input('controlled_tables'));

            $updated = DB::table('device_status')
                ->where('id', $id)
                ->update([
                    'device_id' => $request->device_id,
                    'device_name' => $request->device_name,
                    'status' => $request->status,
                    'details' => $request->details,
                    // Persist controlled tables as provided (frontend sends JSON string)
                    'controlled_tables' => $controlledTables,
                    'updated_at' => now()
                ]);

            if ($updated) {
                return response()->json([
                    'success' => true,
                    'message' => 'PLC device updated successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Device not found'
                ], 404);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating device: ' . $e->getMessage()
            ], 500);
        }
    }

    public function deletePlcDevice(Request $request, $id)
    {
        // Block write operations for management role
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

        try {
            $deleted = DB::table('device_status')->where('id', $id)->delete();

            if ($deleted) {
                return response()->json([
                    'success' => true,
                    'message' => 'PLC device deleted successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Device not found'
                ], 404);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting device: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getInspectionTables()
    {
        try {
            $tables = DB::table('inspection_tables')
                ->select('id', 'name', 'line_name', 'division', 'address')
                ->orderBy('line_name')
                ->orderBy('name')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $tables
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching inspection tables: ' . $e->getMessage()
            ], 500);
        }
    }

    public function forwardProblem(Request $request, $id)
    {
        // Block write operations for management role
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

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

        // Validasi bahwa message wajib diisi
        $request->validate([
            'message' => 'required|string|min:1',
            'photo' => 'nullable|image|mimes:jpeg,jpg,png,gif|max:5120' // max 5MB
        ], [
            'message.required' => 'Pesan wajib diisi',
            'message.string' => 'Pesan harus berupa teks',
            'message.min' => 'Pesan tidak boleh kosong',
            'photo.image' => 'File harus berupa gambar',
            'photo.mimes' => 'Format gambar harus JPG, PNG, atau GIF',
            'photo.max' => 'Ukuran foto maksimal 5MB'
        ]);

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

        // Pastikan targetRole dalam lowercase untuk konsistensi
        $targetRole = strtolower(trim($targetRole));

        // Handle foto upload jika ada
        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photo = $request->file('photo');
            $photoName = 'forward_' . $problem->id . '_' . time() . '.' . $photo->getClientOriginalExtension();
            $photoPath = $photo->storeAs('forward_photos', $photoName, 'public');
            
            // Simpan path relatif untuk akses web
            $photoPath = 'storage/' . $photoPath;
        }

        // Update problem di database
        $updateData = [
            'is_forwarded' => true,
            'forwarded_to_role' => $targetRole,
            'forwarded_by_user_id' => $session->id,
            'forwarded_at' => Carbon::now(config('app.timezone')),
            'forward_message' => trim($request->input('message'))
        ];

        if ($photoPath) {
            $updateData['forward_photo'] = $photoPath;
        }

        $problem->update($updateData);
        
        // Log untuk debugging
        \Log::info('Problem forwarded', [
            'problem_id' => $problem->id,
            'problem_type' => $problem->tipe_problem,
            'forwarded_to_role' => $targetRole,
            'forwarded_by' => $session->id,
            'forwarded_by_role' => $session->role
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
            'message' => $request->input('message', 'Problem telah diteruskan untuk penanganan.'),
            'photo_path' => $photoPath ? url($photoPath) : null
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
        // Block write operations for management role
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

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
            // Log detail untuk debugging
            \Log::warning('Receive problem failed validation', [
                'problem_id' => $problem->id,
                'user_id' => $session->id,
                'user_role' => $session->role,
                'user_line' => $session->line_name,
                'problem_status' => $problem->status,
                'is_forwarded' => $problem->is_forwarded,
                'is_received' => $problem->is_received,
                'forwarded_to_role' => $problem->forwarded_to_role,
                'problem_type' => $problem->tipe_problem
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Problem tidak bisa diterima oleh user ini. Pastikan problem sudah di-forward ke role Anda (' . $session->role . ').',
                'debug' => [
                    'user_role' => $session->role,
                    'forwarded_to_role' => $problem->forwarded_to_role,
                    'is_forwarded' => $problem->is_forwarded,
                    'is_received' => $problem->is_received
                ]
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
        // Block write operations for management role
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

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

        // Validasi result_repair wajib diisi
        $request->validate([
            'result_repair' => 'required|string|min:1',
            'message' => 'nullable|string'
        ]);
        
        // Log untuk debugging
        \Log::info('Feedback resolved request received', [
            'problem_id' => $problem->id,
            'result_repair_provided' => $request->has('result_repair'),
            'result_repair_value' => $request->input('result_repair') ? 'has_value' : 'empty',
            'result_repair_length' => $request->input('result_repair') ? strlen($request->input('result_repair')) : 0
        ]);

        // Update problem di database
        $problem->update([
            'has_feedback_resolved' => true,
            'feedback_resolved_by_user_id' => $session->id,
            'feedback_resolved_at' => Carbon::now(config('app.timezone')),
            'feedback_message' => $request->input('message', 'Problem sudah selesai ditangani.')
        ]);

        // Jika ada ticketing terkait problem ini, update result_repair dan status menjadi close
        try {
            $ticketing = \App\Models\TicketingProblem::where('problem_id', $problem->id)
                ->first();
            
            if ($ticketing) {
                // Ambil result_repair dari request
                $resultRepair = $request->input('result_repair');
                
                // Log untuk debugging
                \Log::info('Updating ticketing with feedback resolved', [
                    'ticketing_id' => $ticketing->id,
                    'problem_id' => $problem->id,
                    'result_repair_provided' => !empty($resultRepair),
                    'result_repair_length' => $resultRepair ? strlen($resultRepair) : 0
                ]);
                
                // Validasi result_repair tidak kosong
                if (empty($resultRepair) || trim($resultRepair) === '') {
                    \Log::error('result_repair is empty when trying to update ticketing', [
                        'ticketing_id' => $ticketing->id,
                        'problem_id' => $problem->id
                    ]);
                    throw new \Exception('Result repair tidak boleh kosong');
                }
                
                // Update ticketing dengan result_repair dan repair_completed_at
                $ticketing->result_repair = trim($resultRepair);
                $ticketing->repair_completed_at = Carbon::now(config('app.timezone'));
                $ticketing->updated_by_user_id = $session->id;
                
                // Status: tetap OPEN sampai result_repair terisi dan repair_completed_at terisi
                // Setelah itu baru jadi close (CLOSED)
                // Pastikan kedua field terisi sebelum ubah status
                if (!empty(trim($ticketing->result_repair)) && $ticketing->repair_completed_at) {
                    $ticketing->status = 'close'; // CLOSED
                } else {
                    $ticketing->status = 'open'; // Tetap OPEN
                }
                
                $statusDowngraded = false;
                
                // Save ticketing dengan penanganan apabila enum status belum memiliki nilai 'close'
                try {
                    $saved = $ticketing->save();
                } catch (QueryException $e) {
                    if ($this->isTicketingStatusEnumError($e)) {
                        $this->ensureTicketingStatusEnumHasClose();
                        try {
                            $saved = $ticketing->save();
                        } catch (QueryException $retryException) {
                            \Log::warning('Retry save after ensuring status enum still failed, downgrading status to completed', [
                                'ticketing_id' => $ticketing->id,
                                'problem_id' => $problem->id,
                                'error' => $retryException->getMessage()
                            ]);
                            $ticketing->status = 'completed'; // Fallback agar data lain tetap tersimpan
                            $saved = $ticketing->save();
                            $statusDowngraded = true;
                        }
                    } else {
                        throw $e;
                    }
                }
                
                if (!$saved) {
                    \Log::error('Failed to save ticketing', [
                        'ticketing_id' => $ticketing->id,
                        'problem_id' => $problem->id,
                        'result_repair' => $ticketing->result_repair,
                        'repair_completed_at' => $ticketing->repair_completed_at,
                        'status' => $ticketing->status
                    ]);
                    throw new \Exception('Gagal menyimpan ticketing ke database');
                }
                
                // Refresh untuk memastikan data terbaru
                $ticketing->refresh();
                
                // Verifikasi data tersimpan dengan benar
                if (empty($ticketing->result_repair) || !$ticketing->repair_completed_at) {
                    \Log::error('Data ticketing tidak tersimpan dengan benar setelah save', [
                        'ticketing_id' => $ticketing->id,
                        'result_repair' => $ticketing->result_repair ? 'filled' : 'empty',
                        'repair_completed_at' => $ticketing->repair_completed_at ? 'filled' : 'empty'
                    ]);
                    throw new \Exception('Data ticketing tidak tersimpan dengan benar');
                }
                
                // Update calculated times setelah repair_completed_at di-set
                $ticketing->updateCalculatedTimes();
                
                \Log::info('Repair completed at set for ticketing and status updated to close', [
                    'ticketing_id' => $ticketing->id,
                    'problem_id' => $problem->id,
                    'result_repair' => $ticketing->result_repair ? 'filled' : 'empty',
                    'repair_completed_at' => $ticketing->repair_completed_at,
                    'status' => $ticketing->status,
                    'saved' => $saved,
                    'status_downgraded' => $statusDowngraded
                ]);
            } else {
                \Log::warning('No ticketing found for problem when feedback resolved', [
                    'problem_id' => $problem->id
                ]);
            }
        } catch (\Throwable $th) {
            \Log::error('Error setting repair_completed_at and result_repair for ticketing', [
                'problem_id' => $problem->id,
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);
            // Log error tapi jangan stop proses feedback resolved
            // Feedback resolved tetap sukses meskipun update ticketing gagal
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
        // Block write operations for management role
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

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
     * Cancel problem - hanya mematikan problem tanpa menyimpan ke database/analytics
     * Digunakan untuk mengatasi kesalahan penekanan tombol oleh operator
     */
    public function cancelProblem(Request $request, $id)
    {
        // Block write operations for management role
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

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

            // Validasi bahwa yang melakukan cancel adalah leader
            if ($session->role !== 'leader') {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya leader yang dapat melakukan cancel problem.'
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

        // Validasi bahwa problem belum di-forward (hanya bisa cancel problem yang belum di-forward)
        if ($problem->is_forwarded) {
            return response()->json([
                'success' => false,
                'message' => 'Problem yang sudah di-forward tidak bisa di-cancel.'
            ], 403);
        }

        // Validasi bahwa problem adalah milik line leader ini
        if ($session->line_name && $problem->line_name != $session->line_name) {
            return response()->json([
                'success' => false,
                'message' => 'Anda hanya bisa cancel problem di line Anda sendiri.'
            ], 403);
        }

        // Update problem status ke OFF tanpa logging ke ForwardProblemLog atau analytics
        $problem->status = 'OFF';
        $problem->resolved_at = Carbon::now(config('app.timezone'));
        
        // Hitung durasi untuk keperluan internal saja (tidak disimpan ke analytics)
        $timestampString = $problem->getRawOriginal('timestamp');
        $startTime = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $timestampString, config('app.timezone'));
        $durationSeconds = (int) abs($problem->resolved_at->diffInSeconds($startTime));
        
        // Jangan set duration_in_seconds karena tidak ingin tercatat di analytics
        // $problem->duration_in_seconds = $durationSeconds;
        
        $problem->save();

        // TIDAK melakukan logging ke ForwardProblemLog atau analytics
        // Ini adalah perbedaan utama dengan direct resolve

        return response()->json([
            'success' => true,
            'message' => 'Problem berhasil di-cancel tanpa menyimpan data ke database atau analytics.',
            'data' => [
                'problem_id' => $problem->id,
                'cancelled_by' => $session->name,
                'cancelled_at' => Carbon::now(config('app.timezone'))->format('d/m/Y H:i:s')
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

    /**
     * Check cycle-based status with caching to reduce system load
     * Only recalculate if cache expired or quantity changed
     * Real-time updates only when status changes (not for cycle count calculation)
     */
    private function checkCycleBasedStatusWithCache($table, $latestProduction, $cacheKey)
    {
        $now = time();
        
        // Check if we have cached result and it's still valid
        if (isset(self::$cycleStatusCache[$cacheKey]) && isset(self::$cycleStatusCacheTime[$cacheKey])) {
            $cacheAge = $now - self::$cycleStatusCacheTime[$cacheKey];
            $cachedStatus = self::$cycleStatusCache[$cacheKey];
            
            // If cache is still valid (within TTL), return cached result
            // This reduces system load by not recalculating every second
            if ($cacheAge < self::CYCLE_STATUS_CACHE_TTL) {
                return $cachedStatus;
            }
            
            // Cache expired - recalculate to check for status changes
            // This ensures status changes are detected within TTL period
        }
        
        // Cache expired or doesn't exist - calculate fresh
        $result = $this->checkCycleBasedStatus($table, $latestProduction);
        
        // Check if status changed - if so, log it
        if (isset(self::$cycleStatusCache[$cacheKey])) {
            $oldStatus = self::$cycleStatusCache[$cacheKey]['status'];
            $newStatus = $result['status'];
            if ($oldStatus !== $newStatus) {
                \Log::info("Cycle-based status changed for {$table->name}: {$oldStatus} -> {$newStatus}");
            }
        }
        
        // Store in cache
        self::$cycleStatusCache[$cacheKey] = $result;
        self::$cycleStatusCacheTime[$cacheKey] = $now;
        
        // Clean old cache entries (keep only last 100 entries to prevent memory leak)
        if (count(self::$cycleStatusCache) > 100) {
            $oldestKey = array_key_first(self::$cycleStatusCache);
            unset(self::$cycleStatusCache[$oldestKey]);
            unset(self::$cycleStatusCacheTime[$oldestKey]);
        }
        
        return $result;
    }
    
    /**
     * Check cycle-based status (warning/problem) based on quantity not increasing
     */
    private function checkCycleBasedStatus($table, $latestProduction)
    {
        // Default return if cycle thresholds are not set
        $defaultStatus = [
            'status' => 'normal',
            'cycles_without_increase' => 0,
            'last_increase_timestamp' => null
        ];

        // Reduced logging - only log when status changes or for debugging
        // Remove verbose logging to reduce system load

        // If cycle thresholds are not set, return normal
        if (!$table->warning_cycle_count || !$table->problem_cycle_count || !$table->cycle_time || $table->cycle_time <= 0) {
            return $defaultStatus;
        }

        // If no production data, return normal
        if (!$latestProduction) {
            return $defaultStatus;
        }

        $currentQuantity = (int)$latestProduction->quantity;
        $currentTimestamp = Carbon::parse($latestProduction->timestamp, config('app.timezone'));
        
        // Get production history with fallback for records without line_name
        $productionQuery = ProductionData::where('machine_name', $table->address);

        if (!empty($table->line_name)) {
            $productionQuery->where(function($query) use ($table) {
                $query->where('line_name', $table->line_name)
                      ->orWhereNull('line_name');
            });
        }

        // Ambil maksimal 200 data terbaru lalu urutkan ascending untuk analisis
        $productionHistory = $productionQuery
            ->orderBy('timestamp', 'desc')
            ->limit(200)
            ->get()
            ->reverse()
            ->values();

        if ($productionHistory->count() === 0) {
            return $defaultStatus;
        }

        // Determine the timestamp since quantity last increased to the current value
        // Strategy:
        // - Walk from latest backward; find the first record where quantity != currentQuantity.
        // - The last increase time is the timestamp of the next record after that (first occurrence of currentQuantity streak).
        // - If all recent records have the same quantity (no diff found), search beyond the window to find the last change.
        $lastIncreaseTimestamp = null;
        $indexOfChange = null;
        
        // First, try to find change within the current window
        for ($i = $productionHistory->count() - 1; $i >= 0; $i--) {
            $q = (int)$productionHistory[$i]->quantity;
            if ($q !== $currentQuantity) {
                $indexOfChange = $i;
                break;
            }
        }
        
        if ($indexOfChange !== null) {
            // Next record after change is the first with the currentQuantity
            $firstCurrentIndex = $indexOfChange + 1;
            if ($firstCurrentIndex < $productionHistory->count()) {
                $lastIncreaseTimestamp = Carbon::parse($productionHistory[$firstCurrentIndex]->timestamp, config('app.timezone'));
            }
        } else {
            // No change found within the window: search for the last record with different quantity
            // This handles the case where quantity hasn't changed in the last 200 records
            $lastDifferentQuantity = ProductionData::where('machine_name', $table->address)
                ->where('quantity', '!=', $currentQuantity)
                ->when(!empty($table->line_name), function($query) use ($table) {
                    $query->where(function($q) use ($table) {
                        $q->where('line_name', $table->line_name)
                          ->orWhereNull('line_name');
                    });
                })
                ->orderBy('timestamp', 'desc')
                ->first();
            
            if ($lastDifferentQuantity) {
                // Find the first record with currentQuantity after the last different quantity
                $firstCurrentQuantityRecord = ProductionData::where('machine_name', $table->address)
                    ->where('quantity', $currentQuantity)
                    ->when(!empty($table->line_name), function($query) use ($table) {
                        $query->where(function($q) use ($table) {
                            $q->where('line_name', $table->line_name)
                              ->orWhereNull('line_name');
                        });
                    })
                    ->where('timestamp', '>', $lastDifferentQuantity->timestamp)
                    ->orderBy('timestamp', 'asc')
                    ->first();
                
                if ($firstCurrentQuantityRecord) {
                    $lastIncreaseTimestamp = Carbon::parse($firstCurrentQuantityRecord->timestamp, config('app.timezone'));
                } else {
                    // If no record found after last different quantity, use the oldest record in window
                    $oldest = $productionHistory->first();
                    $lastIncreaseTimestamp = Carbon::parse($oldest->timestamp, config('app.timezone'));
                }
            } else {
                // No different quantity found at all: use the oldest record's timestamp in the window
                $oldest = $productionHistory->first();
                $lastIncreaseTimestamp = Carbon::parse($oldest->timestamp, config('app.timezone'));
            }
        }

        // Validate that lastIncreaseTimestamp is set
        if (!$lastIncreaseTimestamp) {
            $lastIncreaseTimestamp = $currentTimestamp;
        }
        
        // Calculate time elapsed since last increase (use current time, not latest production timestamp)
        $now = Carbon::now(config('app.timezone'));
        
        // Ensure both timestamps are Carbon instances
        if (!$lastIncreaseTimestamp instanceof Carbon) {
            $lastIncreaseTimestamp = Carbon::parse($lastIncreaseTimestamp, config('app.timezone'));
        }
        
        // Use diffInRealSeconds with absolute to ensure positive value
        $timeElapsedSeconds = abs($now->diffInRealSeconds($lastIncreaseTimestamp));
        
        // Calculate how many cycle times have passed
        $cycleTimeSeconds = $table->cycle_time;
        $cyclesElapsed = $cycleTimeSeconds > 0 ? ($timeElapsedSeconds / $cycleTimeSeconds) : 0;

        // Determine status based on cycle counts
        // Use >= for comparison to trigger status change immediately when threshold is reached
        $status = 'normal';
        if ($cyclesElapsed >= $table->problem_cycle_count) {
            $status = 'problem';
        } elseif ($cyclesElapsed >= $table->warning_cycle_count) {
            $status = 'warning';
        }

        // Only log when status is not normal (to reduce log volume)
        if ($status !== 'normal') {
            \Log::info("Cycle-based status changed for {$table->name} (address: {$table->address}):", [
                'status' => $status,
                'cycles_elapsed' => round($cyclesElapsed, 2),
                'warning_threshold' => $table->warning_cycle_count,
                'problem_threshold' => $table->problem_cycle_count,
                'time_elapsed_seconds' => $timeElapsedSeconds
            ]);
        }

        return [
            'status' => $status,
            'cycles_without_increase' => floor($cyclesElapsed),
            'cycles_elapsed' => round($cyclesElapsed, 2), // Add precise cycles elapsed for debugging
            'last_increase_timestamp' => $lastIncreaseTimestamp ? $lastIncreaseTimestamp->format('Y-m-d H:i:s') : null,
            'current_quantity' => $currentQuantity,
            'warning_threshold' => $table->warning_cycle_count,
            'problem_threshold' => $table->problem_cycle_count,
            'time_elapsed_seconds' => $timeElapsedSeconds // Add for debugging
        ];
    }

    /**
     * Detect apakah error berasal dari enum status yang belum mengenal nilai 'close'
     */
    private function isTicketingStatusEnumError(QueryException $exception): bool
    {
        $message = Str::lower($exception->getMessage());
        
        return Str::contains($message, 'ticketing_problems_status_enum')
            || Str::contains($message, 'invalid input value for enum')
            || Str::contains($message, "data truncated for column 'status'")
            || (Str::contains($message, 'check constraint') && Str::contains($message, 'status'));
    }

    /**
     * Pastikan enum status di tabel ticketing_problems memiliki opsi 'close'
     */
    private function ensureTicketingStatusEnumHasClose(): void
    {
        try {
            $driver = DB::getDriverName();
            
            if ($driver === 'pgsql') {
                DB::statement("ALTER TYPE ticketing_problems_status_enum ADD VALUE IF NOT EXISTS 'close'");
            } elseif (in_array($driver, ['mysql', 'mariadb'])) {
                DB::statement("ALTER TABLE ticketing_problems MODIFY COLUMN status ENUM('open','in_progress','close','completed','cancelled') DEFAULT 'open'");
            }
            
            \Log::info('Ensured ticketing_problems.status enum includes close', [
                'driver' => $driver
            ]);
        } catch (\Throwable $th) {
            \Log::error('Failed ensuring ticketing_problems.status enum includes close', [
                'driver' => DB::getDriverName(),
                'error' => $th->getMessage()
            ]);
        }
    }
}