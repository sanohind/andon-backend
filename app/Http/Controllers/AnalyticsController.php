<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Log;
use App\Models\TicketingProblem;
use App\Models\InspectionTable;
use App\Models\ProductionData;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    /**
     * Cache untuk konversi address -> nama mesin agar tidak query berulang
     */
    protected $machineNameCache = [];
    protected $machineNameLookup = null;

    /**
     * Menyediakan data analitik berdasarkan rentang waktu.
     */
    public function getAnalyticsData(Request $request)
    {
        // Validasi input tanggal
        $request->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d',
            'division' => 'nullable|string',
            'line_name' => 'nullable|string',
        ]);

        $appTimezone = config('app.timezone');
        $startDate = Carbon::parse($request->start_date, $appTimezone)->startOfDay()->utc();
        $endDate = Carbon::parse($request->end_date, $appTimezone)->endOfDay()->utc();

        // ===================================================================
        // LOGIKA QUERY TERPADU YANG BARU
        // ===================================================================
        // Ambil semua log yang relevan: yang DIMULAI atau DISELESAIKAN dalam rentang waktu.
        $query = Log::where(function ($query) use ($startDate, $endDate) {
            $query->whereBetween('timestamp', [$startDate, $endDate])
                  ->orWhereBetween('resolved_at', [$startDate, $endDate]);
        });
        
        // Filter berdasarkan division dan line_name jika disediakan (untuk filtering berdasarkan divisi/line)
        if ($request->filled('division')) {
            $machineAddresses = InspectionTable::where('division', $request->division)
                ->pluck('address')
                ->toArray();
            if (!empty($machineAddresses)) {
                $query->whereIn('tipe_mesin', $machineAddresses);
            } else {
                $query->whereRaw('1 = 0');
            }
        }
        
        if ($request->filled('line_name')) {
            $query->where('line_name', $request->line_name);
        }
        
        $allRelevantLogs = $query->get();
        
        // Dari semua log yang relevan, filter hanya yang sudah selesai.
        $resolvedLogs = $allRelevantLogs->where('status', 'OFF')->whereNotNull('resolved_at');
        // ===================================================================

        // Kalkulasi semua metrik dari data yang sudah benar
        $kpis = $this->calculateKPIs($allRelevantLogs, $resolvedLogs);
        $problemFrequency = $this->calculateProblemFrequency($allRelevantLogs);
        $downtime = $this->calculateDowntime($resolvedLogs);
        $problemTypes = $this->calculateProblemTypeDistribution($allRelevantLogs);
        $mttr = $this->calculateMTTR($resolvedLogs);

        return response()->json([
            'success' => true,
            'data' => [
                'kpis' => $kpis,
                'problemFrequency' => $problemFrequency,
                'downtime' => $downtime,
                'problemTypes' => $problemTypes,
                'mttr' => $mttr,
            ]
        ]);
    }

    private function calculateKPIs($allLogs, $resolvedLogs)
    {
        $mostProblematicMachine = $allLogs->groupBy('tipe_mesin')
                                          ->sortByDesc(fn($group) => $group->count())
                                          ->keys()
                                          ->first();

        return [
            'total_problems' => $allLogs->count(), // Sekarang menghitung dari sumber yang benar
            'total_downtime_seconds' => $resolvedLogs->sum('duration_in_seconds'),
            'most_problematic_machine' => $mostProblematicMachine ? $this->resolveMachineName($mostProblematicMachine) : 'N/A',
            'average_resolution_time_seconds' => $resolvedLogs->avg('duration_in_seconds'),
        ];
    }

    private function calculateProblemFrequency($allLogs)
    {
        $data = $allLogs->groupBy('tipe_mesin')->map(fn($group) => $group->count());
        return [
            'labels' => $data->keys()
                               ->map(fn($identifier) => $this->resolveMachineName($identifier))
                               ->values(),
            'data' => $data->values(),
        ];
    }

    private function calculateDowntime($resolvedLogs)
    {
        $data = $resolvedLogs->groupBy('tipe_mesin')->map(fn($group) => $group->sum('duration_in_seconds'));
        return [
            'labels' => $data->keys()
                               ->map(fn($identifier) => $this->resolveMachineName($identifier))
                               ->values(),
            'data' => $data->values(),
        ];
    }

    private function calculateProblemTypeDistribution($allLogs)
    {
        $data = $allLogs->groupBy('tipe_problem')->map(fn($group) => $group->count());
        return [
            'labels' => $data->keys(),
            'data' => $data->values(),
        ];
    }

    /**
     * Get target vs actual quantity per mesin/meja untuk chart perbandingan.
     * Mendukung: tanggal spesifik, bulanan (akumulasi), tahuanan (akumulasi).
     * Shift pagi: 07:00 - 19:59 | Shift malam: 20:00 - 06:59 (hari berikutnya)
     */
    public function getLineQuantityComparison(Request $request)
    {
        $request->validate([
            'division' => 'nullable|string',
            'period' => 'required|in:daily,monthly,yearly',
            'date' => 'required_if:period,daily|nullable|date_format:Y-m-d',
            'month' => 'required_if:period,monthly|nullable|date_format:Y-m',
            'year' => 'required_if:period,yearly|nullable|date_format:Y',
            'shift' => 'required|in:pagi,malam',
        ]);

        $availableDivisions = InspectionTable::select('division')
            ->whereNotNull('division')
            ->distinct()
            ->orderBy('division')
            ->get()
            ->pluck('division')
            ->filter()
            ->values();

        if ($availableDivisions->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'divisions' => [],
                    'division' => null,
                    'lines' => [],
                ]
            ]);
        }

        $selectedDivision = $request->division ?: $availableDivisions->first();
        $appTimezone = config('app.timezone', 'Asia/Jakarta');
        $period = $request->period;
        $shift = $request->shift;

        $dateRange = $this->resolveQuantityDateRange($period, $request->date, $request->month, $request->year, $appTimezone);
        if (!$dateRange) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid date/month/year for period.',
                'data' => ['lines' => []]
            ], 422);
        }

        [$dates, $filterLabel, $productionDays] = $dateRange;

        $linesInDivision = InspectionTable::select('line_name')
            ->where('division', $selectedDivision)
            ->whereNotNull('line_name')
            ->distinct()
            ->orderBy('line_name')
            ->get();

        $linesData = [];
        foreach ($linesInDivision as $lineInfo) {
            $lineName = $lineInfo->line_name;
            $machines = InspectionTable::where('line_name', $lineName)
                ->where('division', $selectedDivision)
                ->whereNotNull('address')
                ->orderBy('name')
                ->get();

            $machinesData = [];
            foreach ($machines as $machine) {
                $targetPerDay = (int) ($machine->target_quantity ?? 0);
                $totalActual = 0;

                foreach ($dates as $dateStr) {
                    [$startUtc, $endUtc] = $this->resolveShiftWindow($dateStr, $shift, $appTimezone);
                    $actualForDate = $this->getLatestMachineQuantityInWindow($machine->address, $startUtc, $endUtc);
                    $totalActual += $actualForDate;
                }

                $totalTarget = $targetPerDay * $productionDays;
                $machinesData[] = [
                    'name' => $machine->name,
                    'address' => $machine->address,
                    'target_quantity' => $totalTarget,
                    'actual_quantity' => $totalActual,
                ];
            }

            $linesData[] = [
                'line_name' => $lineName,
                'machines' => $machinesData,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'divisions' => $availableDivisions,
                'division' => $selectedDivision,
                'lines' => $linesData,
                'filter' => [
                    'period' => $period,
                    'period_label' => $period === 'daily' ? 'Harian' : ($period === 'monthly' ? 'Bulanan' : 'Tahunan'),
                    'shift' => $shift,
                    'shift_label' => $shift === 'pagi' ? 'Shift Pagi' : 'Shift Malam',
                    'filter_label' => $filterLabel,
                    'production_days' => $productionDays,
                    'timezone' => $appTimezone,
                ],
            ]
        ]);
    }

    /**
     * Resolve date range berdasarkan period.
     * @return array|null [dates[], filter_label, production_days]
     */
    private function resolveQuantityDateRange(string $period, ?string $date, ?string $month, ?string $year, string $appTimezone): ?array
    {
        if ($period === 'daily' && $date) {
            return [[$date], Carbon::parse($date, $appTimezone)->format('d/m/Y'), 1];
        }
        if ($period === 'monthly' && $month) {
            $start = Carbon::parse($month . '-01', $appTimezone);
            $days = $start->daysInMonth;
            $dates = [];
            for ($i = 1; $i <= $days; $i++) {
                $dates[] = $start->copy()->day($i)->format('Y-m-d');
            }
            return [$dates, $start->translatedFormat('F Y'), $days];
        }
        if ($period === 'yearly' && $year) {
            $start = Carbon::createFromFormat('Y', $year, $appTimezone);
            $days = $start->isLeapYear() ? 366 : 365;
            $dates = [];
            for ($d = 0; $d < $days; $d++) {
                $dates[] = $start->copy()->addDays($d)->format('Y-m-d');
            }
            return [$dates, (string) $year, $days];
        }
        return null;
    }

    /**
     * Menentukan window UTC untuk shift terpilih.
     * Shift pagi: tanggal 07:00 - tanggal 19:59
     * Shift malam: tanggal 20:00 - tanggal+1 06:59
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveShiftWindow(string $dateStr, string $shift, string $appTimezone): array
    {
        $date = Carbon::parse($dateStr, $appTimezone);

        if ($shift === 'pagi') {
            // Shift Pagi: 07:00 - 19:59 pada hari yang sama
            $startApp = $date->copy()->setTime(7, 0, 0);
            $endApp = $date->copy()->setTime(19, 59, 59);
        } else {
            // Shift Malam: 20:00 hari ini - 06:59 hari berikutnya
            $startApp = $date->copy()->setTime(20, 0, 0);
            $endApp = $date->copy()->addDay()->setTime(6, 59, 59);
        }

        $startUtc = $startApp->copy()->utc();
        $endUtc = $endApp->copy()->utc();

        return [$startUtc, $endUtc];
    }

    /**
     * Ambil quantity aktual dari record TERBARU dalam window shift.
     * Bukan akumulasi/SUM - hanya data paling baru yang masih masuk range waktu shift.
     * Shift pagi: 07:00-19:59 (reset jam 20:00 untuk shift malam).
     * Shift malam: 20:00-06:59 (reset jam 07:00 untuk shift pagi berikutnya).
     */
    private function getLatestMachineQuantityInWindow(?string $address, Carbon $startUtc, Carbon $endUtc): int
    {
        if (!$address) {
            return 0;
        }

        $normalizedAddress = trim($address);
        $lowerAddress = strtolower($normalizedAddress);
        $startStr = $startUtc->format('Y-m-d H:i:s');
        $endStr = $endUtc->format('Y-m-d H:i:s');

        $windowBase = function () use ($startStr, $endStr) {
            return ProductionData::whereRaw('timestamp >= ?', [$startStr])
                ->whereRaw('timestamp <= ?', [$endStr])
                ->orderBy('timestamp', 'desc');
        };

        // Strategy 1: Exact match - ambil record terbaru
        $latest = $windowBase()->where('machine_name', $normalizedAddress)->first();
        if ($latest) {
            return max(0, (int) $latest->quantity);
        }

        // Strategy 2: Case-insensitive + trim
        $latest = ProductionData::whereRaw('timestamp >= ?', [$startStr])
            ->whereRaw('timestamp <= ?', [$endStr])
            ->whereRaw('LOWER(TRIM(machine_name)) = ?', [$lowerAddress])
            ->orderBy('timestamp', 'desc')
            ->first();
        if ($latest) {
            return max(0, (int) $latest->quantity);
        }

        // Strategy 3: Partial match (last resort)
        $latest = ProductionData::whereRaw('timestamp >= ?', [$startStr])
            ->whereRaw('timestamp <= ?', [$endStr])
            ->whereRaw('LOWER(machine_name) LIKE ?', ['%' . $lowerAddress . '%'])
            ->orderBy('timestamp', 'desc')
            ->first();
        if ($latest) {
            return max(0, (int) $latest->quantity);
        }

        return 0;
    }

    private function calculateMTTR($resolvedLogs)
    {
        $data = $resolvedLogs->groupBy('tipe_problem')->map(fn($group) => $group->avg('duration_in_seconds'));
        return [
            'labels' => $data->keys(),
            'data' => $data->values(),
        ];
    }

    /**
     * Menghitung durasi setiap tahapan problem untuk analytics yang lebih detail
     */
    public function getProblemDurationAnalytics(Request $request)
    {
        // Validasi input tanggal
        $request->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d',
        ]);

        $appTimezone = config('app.timezone');
        $startDate = Carbon::parse($request->start_date, $appTimezone)->startOfDay()->utc();
        $endDate = Carbon::parse($request->end_date, $appTimezone)->endOfDay()->utc();

        // Ambil semua problem yang sudah resolved dalam rentang waktu
        $resolvedProblems = Log::where('status', 'OFF')
            ->whereNotNull('resolved_at')
            ->whereBetween('resolved_at', [$startDate, $endDate])
            ->with(['forwardedByUser', 'receivedByUser', 'feedbackResolvedByUser'])
            ->get();

        $durationAnalytics = $this->calculateProblemDurationMetrics($resolvedProblems);

        return response()->json([
            'success' => true,
            'data' => $durationAnalytics
        ]);
    }

    /**
     * Menghitung metrik durasi untuk setiap tahapan problem
     */
    private function calculateProblemDurationMetrics($problems)
    {
        $metrics = [
            'active_to_receive' => [],
            'receive_to_feedback' => [],
            'feedback_to_final' => [],
            'total_resolution' => [],
            'summary' => []
        ];

        foreach ($problems as $problem) {
            // Parse semua timestamp dengan timezone yang sama (dari config)
            $appTimezone = config('app.timezone', 'Asia/Jakarta');
            
            // Pastikan semua timestamp diparse dengan timezone yang sama
            $activeTime = Carbon::parse($problem->timestamp)->setTimezone($appTimezone);
            $resolvedTime = Carbon::parse($problem->resolved_at)->setTimezone($appTimezone);

            // Validasi bahwa resolved_at harus setelah timestamp
            if ($resolvedTime->lt($activeTime)) {
                continue; // Skip jika data tidak valid
            }

            // 1. Durasi dari Active hingga Receive (jika ada)
            if ($problem->is_received && $problem->received_at) {
                $receiveTime = Carbon::parse($problem->received_at)->setTimezone($appTimezone);
                
                // Validasi urutan waktu: activeTime <= receiveTime <= resolvedTime
                if ($receiveTime->gte($activeTime) && $receiveTime->lte($resolvedTime)) {
                    // Gunakan waktu yang lebih besar dikurangi waktu yang lebih kecil
                    $activeToReceive = abs($receiveTime->diffInSeconds($activeTime));
                    
                    $metrics['active_to_receive'][] = [
                        'problem_id' => $problem->id,
                        'machine' => $this->resolveMachineName($problem->tipe_mesin),
                        'problem_type' => $problem->tipe_problem,
                        'duration_seconds' => $activeToReceive,
                        'duration_formatted' => $this->formatDuration($activeToReceive)
                    ];
                }
            }

            // 2. Durasi dari Receive hingga Feedback (jika ada)
            if ($problem->is_received && $problem->has_feedback_resolved && 
                $problem->received_at && $problem->feedback_resolved_at) {
                $receiveTime = Carbon::parse($problem->received_at)->setTimezone($appTimezone);
                $feedbackTime = Carbon::parse($problem->feedback_resolved_at)->setTimezone($appTimezone);
                
                // Validasi urutan waktu: receiveTime <= feedbackTime <= resolvedTime
                if ($feedbackTime->gte($receiveTime) && $feedbackTime->lte($resolvedTime)) {
                    $receiveToFeedback = abs($feedbackTime->diffInSeconds($receiveTime));
                    
                    $metrics['receive_to_feedback'][] = [
                        'problem_id' => $problem->id,
                        'machine' => $this->resolveMachineName($problem->tipe_mesin),
                        'problem_type' => $problem->tipe_problem,
                        'duration_seconds' => $receiveToFeedback,
                        'duration_formatted' => $this->formatDuration($receiveToFeedback)
                    ];
                }
            }

            // 3. Durasi dari Feedback hingga Final Resolved (jika ada)
            if ($problem->has_feedback_resolved && $problem->feedback_resolved_at) {
                $feedbackTime = Carbon::parse($problem->feedback_resolved_at)->setTimezone($appTimezone);
                
                // Validasi urutan waktu: feedbackTime <= resolvedTime
                if ($resolvedTime->gte($feedbackTime)) {
                    $feedbackToFinal = abs($resolvedTime->diffInSeconds($feedbackTime));
                    
                    $metrics['feedback_to_final'][] = [
                        'problem_id' => $problem->id,
                        'machine' => $this->resolveMachineName($problem->tipe_mesin),
                        'problem_type' => $problem->tipe_problem,
                        'duration_seconds' => $feedbackToFinal,
                        'duration_formatted' => $this->formatDuration($feedbackToFinal)
                    ];
                }
            }

            // 4. Total durasi resolusi (selalu dihitung karena sudah divalidasi di atas)
            $totalDuration = abs($resolvedTime->diffInSeconds($activeTime));
            $metrics['total_resolution'][] = [
                'problem_id' => $problem->id,
                'machine' => $this->resolveMachineName($problem->tipe_mesin),
                'problem_type' => $problem->tipe_problem,
                'duration_seconds' => $totalDuration,
                'duration_formatted' => $this->formatDuration($totalDuration),
                'flow_type' => $this->getProblemFlowType($problem)
            ];
        }

        // Hitung summary statistics
        $metrics['summary'] = $this->calculateDurationSummary($metrics);

        return $metrics;
    }

    /**
     * Menentukan tipe alur problem
     */
    private function getProblemFlowType($problem)
    {
        if (!$problem->is_forwarded) {
            return 'direct_resolve';
        } elseif (!$problem->has_feedback_resolved) {
            return 'forwarded_only';
        } else {
            return 'full_flow';
        }
    }

    /**
     * Menghitung summary statistics untuk durasi
     */
    private function calculateDurationSummary($metrics)
    {
        $summary = [];

        foreach ($metrics as $stage => $data) {
            if ($stage === 'summary') continue;
            
            if (!empty($data)) {
                // Ambil semua duration dan filter hanya nilai positif
                $durations = array_filter(
                    array_column($data, 'duration_seconds'),
                    function($value) {
                        return $value >= 0; // Hanya ambil nilai positif atau nol
                    }
                );
                
                // Jika masih ada data valid setelah filter
                if (!empty($durations)) {
                    $durations = array_values($durations); // Re-index array
                    $summary[$stage] = [
                        'count' => count($durations),
                        'average_seconds' => round(array_sum($durations) / count($durations), 2),
                        'min_seconds' => min($durations),
                        'max_seconds' => max($durations),
                        'average_formatted' => $this->formatDuration(round(array_sum($durations) / count($durations))),
                        'min_formatted' => $this->formatDuration(min($durations)),
                        'max_formatted' => $this->formatDuration(max($durations))
                    ];
                } else {
                    // Jika semua data invalid, set ke default
                    $summary[$stage] = [
                        'count' => 0,
                        'average_seconds' => 0,
                        'min_seconds' => 0,
                        'max_seconds' => 0,
                        'average_formatted' => 'N/A',
                        'min_formatted' => 'N/A',
                        'max_formatted' => 'N/A'
                    ];
                }
            } else {
                $summary[$stage] = [
                    'count' => 0,
                    'average_seconds' => 0,
                    'min_seconds' => 0,
                    'max_seconds' => 0,
                    'average_formatted' => 'N/A',
                    'min_formatted' => 'N/A',
                    'max_formatted' => 'N/A'
                ];
            }
        }

        return $summary;
    }

    /**
     * Mengembalikan data detail forward problem untuk tabel
     */
    public function getDetailedForwardAnalyticsData(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d',
            'division' => 'nullable|string',
            'line_name' => 'nullable|string',
        ]);

        $appTimezone = config('app.timezone');
        $startDate = Carbon::parse($request->start_date, $appTimezone)->startOfDay()->utc();
        $endDate = Carbon::parse($request->end_date, $appTimezone)->endOfDay()->utc();

        // Ambil semua problem yang sudah resolved dalam rentang waktu
        $query = Log::where('status', 'OFF')
            ->whereNotNull('resolved_at')
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('timestamp', [$startDate, $endDate])
                      ->orWhereBetween('resolved_at', [$startDate, $endDate])
                      ->orWhereBetween('forwarded_at', [$startDate, $endDate])
                      ->orWhereBetween('received_at', [$startDate, $endDate])
                      ->orWhereBetween('feedback_resolved_at', [$startDate, $endDate]);
            });
        
        // Filter berdasarkan division dan line_name jika disediakan (untuk filtering berdasarkan divisi/line)
        if ($request->filled('division')) {
            $machineAddresses = InspectionTable::where('division', $request->division)
                ->pluck('address')
                ->toArray();
            if (!empty($machineAddresses)) {
                $query->whereIn('tipe_mesin', $machineAddresses);
            } else {
                $query->whereRaw('1 = 0');
            }
        }
        
        if ($request->filled('line_name')) {
            $query->where('line_name', $request->line_name);
        }
        
        $resolvedProblems = $query->with(['forwardedByUser', 'receivedByUser', 'feedbackResolvedByUser'])
            ->orderBy('resolved_at', 'asc')
            ->get();

        $detailedData = [];

        foreach ($resolvedProblems as $problem) {
            $activeTime = $problem->timestamp ? Carbon::parse($problem->timestamp)->setTimezone($appTimezone) : null;
            $forwardTime = $problem->forwarded_at ? Carbon::parse($problem->forwarded_at)->setTimezone($appTimezone) : null;
            $receiveTime = $problem->received_at ? Carbon::parse($problem->received_at)->setTimezone($appTimezone) : null;
            $feedbackTime = $problem->feedback_resolved_at ? Carbon::parse($problem->feedback_resolved_at)->setTimezone($appTimezone) : null;
            $finalTime = $problem->resolved_at ? Carbon::parse($problem->resolved_at)->setTimezone($appTimezone) : null;

            if (!$activeTime || !$finalTime) {
                continue;
            }

            $formatTimestamp = function (?Carbon $time) {
                return $time ? $time->format('d/m/Y H:i:s') : null;
            };

            // Hitung durasi antar tahapan dalam menit (dibulatkan ke menit terdekat)
            $activeToForward = ($activeTime && $forwardTime && $forwardTime->gt($activeTime)) ? round($activeTime->diffInMinutes($forwardTime)) : null;
            $forwardToReceive = ($forwardTime && $receiveTime && $receiveTime->gt($forwardTime)) ? round($forwardTime->diffInMinutes($receiveTime)) : null;
            $receiveToFeedback = ($receiveTime && $feedbackTime && $feedbackTime->gt($receiveTime)) ? round($receiveTime->diffInMinutes($feedbackTime)) : null;
            $feedbackToFinal = ($feedbackTime && $finalTime && $finalTime->gt($feedbackTime)) ? round($feedbackTime->diffInMinutes($finalTime)) : null;
            $totalDuration = ($finalTime->gt($activeTime)) ? round($activeTime->diffInMinutes($finalTime)) : 0;

            // Tentukan flow type
            $flowType = 'Direct Resolved';
            if ($problem->is_forwarded && $problem->is_received && $problem->has_feedback_resolved) {
                $flowType = 'Full Flow';
            } elseif ($problem->is_forwarded && $problem->is_received) {
                $flowType = 'Forwarded & Received';
            } elseif ($problem->is_forwarded) {
                $flowType = 'Forwarded Only';
            }

            $problemData = [
                'problem_id' => $problem->id,
                'machine' => $this->resolveMachineName($problem->tipe_mesin),
                'problem_type' => $problem->tipe_problem,
                'line_name' => $problem->line_name,
                'flow_type' => $flowType,
                'timestamps' => [
                    'active_at' => $formatTimestamp($activeTime),
                    'forwarded_at' => $formatTimestamp($forwardTime),
                    'received_at' => $formatTimestamp($receiveTime),
                    'feedback_resolved_at' => $formatTimestamp($feedbackTime),
                    'final_resolved_at' => $formatTimestamp($finalTime),
                ],
                'durations_minutes' => [
                    'active_to_forward' => $activeToForward,
                    'forward_to_receive' => $forwardToReceive,
                    'receive_to_feedback' => $receiveToFeedback,
                    'feedback_to_final' => $feedbackToFinal,
                    'total_duration' => $totalDuration,
                ],
                'durations_formatted' => [
                    'active_to_forward' => $activeToForward ? $this->formatDurationMinutes($activeToForward) : '-',
                    'forward_to_receive' => $forwardToReceive ? $this->formatDurationMinutes($forwardToReceive) : '-',
                    'receive_to_feedback' => $receiveToFeedback ? $this->formatDurationMinutes($receiveToFeedback) : '-',
                    'feedback_to_final' => $feedbackToFinal ? $this->formatDurationMinutes($feedbackToFinal) : '-',
                    'total_duration' => $this->formatDurationMinutes($totalDuration),
                ],
                'users' => [
                    'forwarded_by' => $problem->forwardedByUser ? $problem->forwardedByUser->name : null,
                    'received_by' => $problem->receivedByUser ? $problem->receivedByUser->name : null,
                    'feedback_by' => $problem->feedbackResolvedByUser ? $problem->feedbackResolvedByUser->name : null,
                ],
                'messages' => [
                    'forward_message' => $problem->forward_message,
                    'feedback_message' => $problem->feedback_message,
                ]
            ];

            $detailedData[] = $problemData;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'problems' => $detailedData,
                'count' => count($detailedData)
            ]
        ]);
    }

    /**
     * Format durasi dalam detik menjadi format yang lebih readable
     */
    private function formatDuration($seconds)
    {
        if ($seconds < 60) {
            return $seconds . ' detik';
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $remainingSeconds = $seconds % 60;
            return $minutes . ' menit ' . $remainingSeconds . ' detik';
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            $remainingSeconds = $seconds % 60;
            return $hours . ' jam ' . $minutes . ' menit ' . $remainingSeconds . ' detik';
        }
    }

    /**
     * Format durasi dalam menit menjadi format yang lebih readable
     */
    private function formatDurationMinutes($minutes)
    {
        if ($minutes < 1) {
            return '< 1 menit';
        } elseif ($minutes < 60) {
            return $minutes . ' menit';
        } else {
            $hours = floor($minutes / 60);
            $remainingMinutes = $minutes % 60;
            if ($remainingMinutes == 0) {
                return $hours . ' jam';
            } else {
                return $hours . ' jam ' . $remainingMinutes . ' menit';
            }
        }
    }

    /**
     * Get ticketing data untuk analytics
     */
    public function getTicketingAnalyticsData(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d',
            'division' => 'nullable|string',
            'line_name' => 'nullable|string',
        ]);

        $appTimezone = config('app.timezone');
        $startDate = Carbon::parse($request->start_date, $appTimezone)->startOfDay()->utc();
        $endDate = Carbon::parse($request->end_date, $appTimezone)->endOfDay()->utc();

        $query = TicketingProblem::with(['problem', 'createdByUser', 'updatedByUser'])
            ->whereBetween('created_at', [$startDate, $endDate]);
        
        // Filter berdasarkan division dan line_name jika disediakan (untuk filtering berdasarkan divisi/line)
        if ($request->filled('division')) {
            $machineAddresses = InspectionTable::where('division', $request->division)
                ->pluck('address')
                ->toArray();
            if (!empty($machineAddresses)) {
                $query->whereHas('problem', function($q) use ($machineAddresses) {
                    $q->whereIn('tipe_mesin', $machineAddresses);
                });
            } else {
                $query->whereRaw('1 = 0');
            }
        }
        
        if ($request->filled('line_name')) {
            $query->whereHas('problem', function($q) use ($request) {
                $q->where('line_name', $request->line_name);
            });
        }
        
        $ticketingData = $query->orderBy('created_at', 'asc')
            ->get()
            ->map(function($ticketing) use ($appTimezone) {
                try {
                    // Problem received at: ambil dari ticketing atau dari problem jika belum ada
                    // Data ini sama dengan kolom "Received at" pada tabel detail forward problem
                    $problemReceivedAt = $ticketing->problem_received_at;
                    if (!$problemReceivedAt && $ticketing->problem && $ticketing->problem->received_at) {
                        $problemReceivedAt = $ticketing->problem->received_at;
                    }
                    
                    // Format problem received at
                    $formattedProblemReceivedAt = null;
                    if ($problemReceivedAt) {
                        try {
                            $formattedProblemReceivedAt = Carbon::parse($problemReceivedAt)->setTimezone($appTimezone)->format('d/m/Y H:i:s');
                        } catch (\Exception $e) {
                            $formattedProblemReceivedAt = null;
                        }
                    }
                    
                    // Hitung Downtime: dari Problem Received hingga Repair Completed
                    // Gunakan nilai dari database jika sudah dihitung, jika tidak hitung ulang
                    $downtimeSeconds = $ticketing->downtime_seconds;
                    if (!$downtimeSeconds && $problemReceivedAt && $ticketing->repair_completed_at) {
                        try {
                            $received = Carbon::parse($problemReceivedAt)->setTimezone($appTimezone);
                            $repairCompleted = Carbon::parse($ticketing->repair_completed_at)->setTimezone($appTimezone);
                            if ($repairCompleted->gt($received)) {
                                $downtimeSeconds = abs($received->diffInSeconds($repairCompleted));
                            }
                        } catch (\Exception $e) {
                            \Log::warning('Error calculating downtime in analytics', [
                                'ticketing_id' => $ticketing->id,
                                'error' => $e->getMessage()
                            ]);
                            $downtimeSeconds = null;
                        }
                    }
                    
                    // Hitung MTTR: dari Repair Started hingga Repair Completed
                    // Gunakan nilai dari database jika sudah dihitung, jika tidak hitung ulang
                    $mttrSeconds = $ticketing->mttr_seconds;
                    if (!$mttrSeconds && $ticketing->repair_started_at && $ticketing->repair_completed_at) {
                        try {
                            $repairStarted = Carbon::parse($ticketing->repair_started_at)->setTimezone($appTimezone);
                            $repairCompleted = Carbon::parse($ticketing->repair_completed_at)->setTimezone($appTimezone);
                            if ($repairCompleted->gt($repairStarted)) {
                                $mttrSeconds = abs($repairStarted->diffInSeconds($repairCompleted));
                            }
                        } catch (\Exception $e) {
                            \Log::warning('Error calculating MTTR in analytics', [
                                'ticketing_id' => $ticketing->id,
                                'error' => $e->getMessage()
                            ]);
                            $mttrSeconds = null;
                        }
                    }
                    
                    // Hitung MTTD: dari Diagnosis Started hingga Repair Started
                    // Gunakan nilai dari database jika sudah dihitung, jika tidak hitung ulang
                    $mttdSeconds = $ticketing->mttd_seconds;
                    if (!$mttdSeconds && $ticketing->diagnosis_started_at && $ticketing->repair_started_at) {
                        try {
                            $diagnosisStarted = Carbon::parse($ticketing->diagnosis_started_at)->setTimezone($appTimezone);
                            $repairStarted = Carbon::parse($ticketing->repair_started_at)->setTimezone($appTimezone);
                            if ($repairStarted->gt($diagnosisStarted)) {
                                $mttdSeconds = abs($diagnosisStarted->diffInSeconds($repairStarted));
                            }
                        } catch (\Exception $e) {
                            \Log::warning('Error calculating MTTD in analytics', [
                                'ticketing_id' => $ticketing->id,
                                'error' => $e->getMessage()
                            ]);
                            $mttdSeconds = null;
                        }
                    }
                
                    // Format durations - format menit dan jam, jika < 1 menit tampilkan "< 1 menit"
                    $formatDuration = function($seconds) {
                        if ($seconds === null || $seconds <= 0) {
                            return '-';
                        }
                        $minutes = round($seconds / 60);
                        if ($minutes < 1) {
                            return '< 1 menit';
                        } elseif ($minutes < 60) {
                            return $minutes . ' menit';
                        } else {
                            $hours = floor($minutes / 60);
                            $remainingMinutes = $minutes % 60;
                            if ($remainingMinutes == 0) {
                                return $hours . ' jam';
                            } else {
                                return $hours . ' jam ' . $remainingMinutes . ' menit';
                            }
                        }
                    };
                    
                    // Format timestamps dengan error handling
                    $formatTimestamp = function($timestamp) use ($appTimezone) {
                        if (!$timestamp) {
                            return null;
                        }
                        try {
                            return Carbon::parse($timestamp)->setTimezone($appTimezone)->format('d/m/Y H:i:s');
                        } catch (\Exception $e) {
                            return null;
                        }
                    };
                    
                    // Ambil machine identifier - sama seperti forward problem
                    $machineIdentifier = null;
                    if ($ticketing->problem && $ticketing->problem->tipe_mesin) {
                        $machineIdentifier = $ticketing->problem->tipe_mesin;
                    } elseif (!empty($ticketing->metadata['machine_identifier'])) {
                        $machineIdentifier = $ticketing->metadata['machine_identifier'];
                    }
                    
                    // Resolve machine name menggunakan metode yang sama dengan forward problem
                    // Pastikan kita selalu mendapatkan nama mesin, bukan address
                    $machineName = $this->resolveMachineName($machineIdentifier);
                    
                    // Debug logging jika masih mendapatkan address
                    if ($machineName === $machineIdentifier && $machineIdentifier) {
                        \Log::info('Ticketing machine name resolution returned identifier', [
                            'ticketing_id' => $ticketing->id,
                            'problem_id' => $ticketing->problem_id,
                            'machine_identifier' => $machineIdentifier,
                            'resolved_name' => $machineName
                        ]);
                    }

                    return [
                        'id' => $ticketing->id,
                        'problem_id' => $ticketing->problem_id,
                        'machine' => $machineName,
                        'machine_display_name' => $machineName,
                        'machine_name' => $machineName,
                        'machine_identifier' => $machineIdentifier,
                        'problem_type' => $ticketing->problem ? $ticketing->problem->tipe_problem : 'Unknown',
                        'line_name' => $ticketing->problem ? $ticketing->problem->line_name : 'Unknown',
                        'pic_technician' => $ticketing->pic_technician ?? '',
                        'diagnosis' => $ticketing->diagnosis ?? '',
                        'result_repair' => $ticketing->result_repair ?? '',
                        'status' => $ticketing->status ?? 'open',
                        'status_label' => $ticketing->status_label ?? 'Open',
                        'status_badge_class' => $ticketing->status_badge_class ?? 'badge-secondary',
                        'timestamps' => [
                            'problem_received_at' => $formattedProblemReceivedAt,
                            'diagnosis_started_at' => $formatTimestamp($ticketing->diagnosis_started_at),
                            'repair_started_at' => $formatTimestamp($ticketing->repair_started_at),
                            'repair_completed_at' => $formatTimestamp($ticketing->repair_completed_at),
                            'created_at' => $ticketing->created_at ? $ticketing->created_at->format('d/m/Y H:i:s') : null,
                            'updated_at' => $ticketing->updated_at ? $ticketing->updated_at->format('d/m/Y H:i:s') : null,
                        ],
                        'durations' => [
                            'downtime' => $formatDuration($downtimeSeconds),
                            'mttr' => $formatDuration($mttrSeconds),
                            'mttd' => $formatDuration($mttdSeconds),
                            'mtbf' => $ticketing->formatted_mtbf ?? '-',
                        ],
                        'durations_seconds' => [
                            'downtime_seconds' => $downtimeSeconds,
                            'mttr_seconds' => $mttrSeconds,
                            'mttd_seconds' => $mttdSeconds,
                            'mtbf_seconds' => $ticketing->mtbf_seconds ?? null,
                        ],
                        'users' => [
                            'created_by' => $ticketing->createdByUser ? $ticketing->createdByUser->name : 'Unknown',
                            'updated_by' => $ticketing->updatedByUser ? $ticketing->updatedByUser->name : null,
                        ],
                        'metadata' => $ticketing->metadata ?? []
                    ];
                } catch (\Exception $e) {
                    // Log error dan return data minimal untuk menghindari error 500
                    \Log::error('Error processing ticketing data: ' . $e->getMessage(), [
                        'ticketing_id' => $ticketing->id ?? null,
                        'trace' => $e->getTraceAsString()
                    ]);
                    
                    return [
                        'id' => $ticketing->id ?? null,
                        'problem_id' => $ticketing->problem_id ?? null,
                        'machine' => 'Error',
                        'machine_display_name' => 'Error',
                        'machine_name' => 'Error',
                        'machine_identifier' => null,
                        'problem_type' => 'Error',
                        'line_name' => 'Error',
                        'pic_technician' => '',
                        'diagnosis' => '',
                        'result_repair' => '',
                        'status' => 'error',
                        'status_label' => 'Error',
                        'status_badge_class' => 'badge-danger',
                        'timestamps' => [
                            'problem_received_at' => null,
                            'diagnosis_started_at' => null,
                            'repair_started_at' => null,
                            'repair_completed_at' => null,
                            'created_at' => null,
                            'updated_at' => null,
                        ],
                        'durations' => [
                            'downtime' => '-',
                            'mttr' => '-',
                            'mttd' => '-',
                            'mtbf' => '-',
                        ],
                        'durations_seconds' => [
                            'downtime_seconds' => null,
                            'mttr_seconds' => null,
                            'mttd_seconds' => null,
                            'mtbf_seconds' => null,
                        ],
                        'users' => [
                            'created_by' => 'Unknown',
                            'updated_by' => null,
                        ],
                        'metadata' => []
                    ];
                }
            });

        return response()->json([
            'success' => true,
            'data' => [
                'ticketing' => $ticketingData,
                'count' => $ticketingData->count()
            ]
        ]);
    }

    /**
     * Konversi identifier mesin (address/code) menjadi nama mesin human-readable
     */
    private function resolveMachineName(?string $identifier): string
    {
        if (!$identifier) {
            return 'Unknown';
        }

        // Cache check
        if (isset($this->machineNameCache[$identifier])) {
            return $this->machineNameCache[$identifier];
        }

        // Build lookup table jika belum ada
        if ($this->machineNameLookup === null) {
            $this->machineNameLookup = $this->buildMachineNameLookup();
        }

        $normalized = mb_strtolower(trim($identifier));

        // Cek di lookup table terlebih dahulu
        if (isset($this->machineNameLookup[$normalized])) {
            $resolvedName = $this->machineNameLookup[$normalized];
            // Pastikan kita tidak mengembalikan address sebagai nama
            if ($resolvedName && $resolvedName !== $identifier) {
                return $this->machineNameCache[$identifier] = $resolvedName;
            }
        }

        // Fallback: Query database langsung dengan berbagai variasi pencarian
        // Ini memastikan kita selalu mendapatkan nama mesin jika ada di database
        try {
            $trimmedIdentifier = trim($identifier);
            $lowerIdentifier = mb_strtolower($trimmedIdentifier);
            
            // Coba berbagai variasi pencarian
            $machine = null;
            
            // 1. Coba dengan address (case-insensitive, trimmed)
            $machine = InspectionTable::whereRaw('LOWER(TRIM(address)) = ?', [$lowerIdentifier])->first();
            
            // 2. Jika tidak ditemukan, coba exact match dengan address
            if (!$machine) {
                $machine = InspectionTable::where('address', $trimmedIdentifier)->first();
            }
            
            // 3. Jika masih tidak ditemukan, coba dengan name (case-insensitive, trimmed)
            if (!$machine) {
                $machine = InspectionTable::whereRaw('LOWER(TRIM(name)) = ?', [$lowerIdentifier])->first();
            }
            
            // 4. Jika masih tidak ditemukan, coba exact match dengan name
            if (!$machine) {
                $machine = InspectionTable::where('name', $trimmedIdentifier)->first();
            }
            
            // 5. Coba dengan LIKE untuk partial match (jika identifier adalah bagian dari address atau name)
            if (!$machine && strlen($trimmedIdentifier) > 2) {
                $machine = InspectionTable::whereRaw('LOWER(address) LIKE ?', ['%' . $lowerIdentifier . '%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%' . $lowerIdentifier . '%'])
                    ->first();
            }
            
            if ($machine) {
                // Pastikan kita menggunakan name, bukan address
                // Jika name kosong, gunakan address sebagai fallback (tapi ini seharusnya tidak terjadi)
                $resolvedName = !empty($machine->name) ? $machine->name : $machine->address;
                
                // Pastikan resolvedName bukan identifier yang sama (untuk menghindari loop)
                if ($resolvedName && $resolvedName !== $identifier) {
                    // Update lookup table untuk penggunaan selanjutnya
                    $this->machineNameLookup[$normalized] = $resolvedName;
                    if ($machine->address) {
                        $normalizedAddress = mb_strtolower(trim($machine->address));
                        $this->machineNameLookup[$normalizedAddress] = $resolvedName;
                    }
                    // Juga cache dengan name sebagai key
                    if ($machine->name) {
                        $normalizedName = mb_strtolower(trim($machine->name));
                        $this->machineNameLookup[$normalizedName] = $resolvedName;
                    }
                    
                    return $this->machineNameCache[$identifier] = $resolvedName;
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Error querying machine name from database', [
                'identifier' => $identifier,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        // Fallback terakhir: return identifier jika benar-benar tidak ditemukan
        // Log warning untuk debugging
        \Log::warning('Machine name not found for identifier - returning identifier as-is', [
            'identifier' => $identifier,
            'normalized' => $normalized,
            'lookup_table_size' => count($this->machineNameLookup ?? [])
        ]);
        return $this->machineNameCache[$identifier] = $identifier;
    }

    /**
     * Bangun lookup table address -> nama mesin, hanya dipanggil sekali per request
     */
    private function buildMachineNameLookup(): array
    {
        $lookup = [];
        $tables = InspectionTable::select('name', 'address')->get();

        foreach ($tables as $table) {
            // Normalize dan simpan mapping address -> name
            // Pastikan kita selalu menggunakan name, bukan address
            if ($table->address) {
                $normalizedAddress = mb_strtolower(trim($table->address));
                // Gunakan name jika ada, jika tidak baru gunakan address sebagai fallback
                $machineName = $table->name ?: $table->address;
                $lookup[$normalizedAddress] = $machineName;
            }

            // Normalize dan simpan mapping name -> name (untuk pencarian langsung)
            if ($table->name) {
                $normalizedName = mb_strtolower(trim($table->name));
                $lookup[$normalizedName] = $table->name;
            }
        }

        // Log untuk debugging
        \Log::debug('Machine name lookup table built', [
            'total_machines' => $tables->count(),
            'lookup_entries' => count($lookup)
        ]);

        return $lookup;
    }
}