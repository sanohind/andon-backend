<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Log;
use App\Models\TicketingProblem;
use App\Models\InspectionTable;
use App\Models\ProductionData;
use App\Models\ProductionDataHourly;
use App\Models\OeeRecord;
use App\Models\OeeRecordHourly;
use App\Models\BreakSchedule;
use App\Models\MachineSchedule;
use App\Models\OeeSetting;
use App\Support\RunningHourOtExtension;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    /**
     * Cache untuk konversi address -> nama mesin agar tidak query berulang
     */
    protected $machineNameCache = [];
    protected $machineNameLookup = null;

    /**
     * Cache simple untuk cycle time per mesin (address) agar tidak query berulang.
     */
    protected array $cycleTimeCache = [];

    /**
     * Cache sederhana untuk nilai cavity per mesin (address)
     */
    protected array $cavityCache = [];

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
     * Window shift (sama dengan resolveShiftWindow): pagi 07:01–20:00, malam 20:01–07:00 (lokal).
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

            $allAddresses = $machines->pluck('address')->filter()->values()->all();
            $scheduleMap = $this->buildScheduleMapForDates($dates, $allAddresses, $shift);

            $machinesData = [];
            foreach ($machines as $machine) {
                $cavity = (int) ($machine->cavity ?? 1);
                if ($cavity < 1) {
                    $cavity = 1;
                }
                $totalActual = 0;
                $totalActualRegular = 0;
                $totalTarget = 0;
                $totalTargetOt = 0;
                $anyOtEnabled = false;

                foreach ($dates as $dateStr) {
                    $schedule = $scheduleMap[$dateStr][$machine->address] ?? null;
                    $targetForDate = $schedule ? (int) ($schedule->target_quantity ?? 0) : 0;
                    $totalTarget += $targetForDate;

                    [$startUtc, $endUtc] = $this->resolveShiftWindow($dateStr, $shift, $appTimezone);
                    $actualForDate = $this->getLatestMachineQuantityInWindow($machine->address, $startUtc, $endUtc, $appTimezone);
                    $totalActual += $actualForDate;

                    if ($schedule && $schedule->ot_enabled) {
                        $anyOtEnabled = true;
                        $totalTargetOt += (int) ($schedule->target_ot ?? 0);
                        [$_, $endRegulerUtc] = $this->resolveRegulerEndForShift($dateStr, $shift, $appTimezone);
                        $actualRegularForDate = $this->getLatestMachineQuantityInWindow($machine->address, $startUtc, $endRegulerUtc, $appTimezone);
                        $totalActualRegular += $actualRegularForDate;
                    } else {
                        $totalActualRegular += $actualForDate;
                    }
                }

                $actualOt = $anyOtEnabled ? max(0, $totalActual - $totalActualRegular) : 0;
                $actualRegular = $anyOtEnabled ? $totalActualRegular : $totalActual;
                $targetOt = $anyOtEnabled && $totalTargetOt > 0 ? $totalTargetOt : null;

                $actualQuantityWithCavity = $totalActual * $cavity;
                $actualRegularWithCavity = $actualRegular * $cavity;
                $actualOtWithCavity = $actualOt * $cavity;

                $machinesData[] = [
                    'name' => $machine->name,
                    'address' => $machine->address,
                    'target_quantity' => $totalTarget,
                    'actual_quantity' => $actualQuantityWithCavity,
                    'actual_quantity_regular' => $actualRegularWithCavity,
                    'actual_quantity_ot' => $actualOtWithCavity,
                    'target_ot' => $targetOt,
                    'ot_enabled' => $anyOtEnabled,
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
     * OEE per mesin per line — filter tanggal/shift/divisi sama seperti Production Quantity.
     * Sumber utama: oee_records; fallback dari production + running hour jika belum ada snapshot.
     */
    public function getLineOeeComparison(Request $request)
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
                $oeeSum = 0.0;
                $aSum = 0.0;
                $pSum = 0.0;
                $qSum = 0.0;
                $cnt = 0;

                foreach ($dates as $dateStr) {
                    $rec = OeeRecord::query()
                        ->where('shift_date', $dateStr)
                        ->where('shift', $shift)
                        ->where('machine_address', $machine->address)
                        ->first();

                    if ($rec && $rec->oee_percent !== null) {
                        $oeeSum += (float) $rec->oee_percent;
                        $aSum += (float) ($rec->availability_percent ?? 0);
                        $pSum += (float) ($rec->performance_percent ?? 0);
                        $qSum += (float) ($rec->quality_percent ?? 100);
                        $cnt++;
                    } else {
                        $fb = $this->computeOeeFallbackForShiftDay($machine, $dateStr, $shift, $appTimezone);
                        if ($fb['oee'] !== null) {
                            $oeeSum += $fb['oee'];
                            $aSum += (float) ($fb['availability'] ?? 0);
                            $pSum += (float) ($fb['performance'] ?? 0);
                            $qSum += (float) ($fb['quality'] ?? 100);
                            $cnt++;
                        }
                    }
                }

                $machinesData[] = [
                    'name' => $machine->name,
                    'address' => $machine->address,
                    'oee_percent' => $cnt > 0 ? round($oeeSum / $cnt, 2) : null,
                    'availability_percent' => $cnt > 0 ? round($aSum / $cnt, 2) : null,
                    'performance_percent' => $cnt > 0 ? round($pSum / $cnt, 2) : null,
                    'quality_percent' => $cnt > 0 ? round($qSum / $cnt, 2) : 100.0,
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
     * Akumulasi Efisiensi (OEE) harian per line dalam satu divisi (untuk chart division view).
     * X = OEE (%), Y = tanggal.
     *
     * Query:
     * - division (optional)
     * - period: monthly|yearly
     * - month (required if monthly, YYYY-MM)
     * - year (required if yearly, YYYY)
     */
    public function getDivisionEfficiencyDaily(Request $request)
    {
        $request->validate([
            'division' => 'nullable|string',
            'period' => 'required|in:monthly,yearly',
            'month' => 'required_if:period,monthly|nullable|date_format:Y-m',
            'year' => 'required_if:period,yearly|nullable|date_format:Y',
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
                    'dates' => [],
                    'lines' => [],
                ]
            ]);
        }

        $selectedDivision = $request->division ?: $availableDivisions->first();
        $period = $request->period;

        $appTimezone = config('app.timezone', 'Asia/Jakarta');
        $dates = [];
        $rangeStart = null;
        $rangeEnd = null;

        if ($period === 'monthly') {
            $month = Carbon::createFromFormat('Y-m', $request->month, $appTimezone)->startOfMonth();
            $rangeStart = $month->copy()->startOfDay();
            $rangeEnd = $month->copy()->endOfMonth()->startOfDay();
            $cur = $rangeStart->copy();
            while ($cur->lte($rangeEnd)) {
                $dates[] = $cur->format('Y-m-d');
                $cur->addDay();
            }
        } else {
            $year = Carbon::createFromFormat('Y', $request->year, $appTimezone)->startOfYear();
            $rangeStart = $year->copy()->startOfDay();
            $rangeEnd = $year->copy()->endOfYear()->startOfDay();
            for ($m = 1; $m <= 12; $m++) {
                $dates[] = $year->copy()->month($m)->format('Y-m');
            }
        }

        $linesInDivision = InspectionTable::select('line_name')
            ->where('division', $selectedDivision)
            ->whereNotNull('line_name')
            ->distinct()
            ->orderBy('line_name')
            ->get()
            ->pluck('line_name')
            ->filter()
            ->values();

        $linesData = [];
        foreach ($linesInDivision as $lineName) {
            // 1) Primary: read from oee_records (already computed)
            $oeeQ = OeeRecord::query()
                ->where('division', $selectedDivision)
                ->where('line_name', $lineName)
                ->whereNotNull('oee_percent');

            if ($period === 'monthly') {
                $oeeQ->whereBetween('shift_date', [$rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d')]);
            } else {
                $oeeQ->whereBetween('shift_date', [$rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d')]);
            }

            $recs = $oeeQ->get(['shift_date', 'oee_percent']);

            $daily = [];
            if ($recs->count() > 0) {
                if ($period === 'monthly') {
                    $byDate = [];
                    foreach ($recs as $r) {
                        $k = (string) $r->shift_date;
                        if (!isset($byDate[$k])) $byDate[$k] = ['sum' => 0.0, 'cnt' => 0];
                        $byDate[$k]['sum'] += (float) $r->oee_percent;
                        $byDate[$k]['cnt']++;
                    }
                    foreach ($dates as $dateStr) {
                        $row = $byDate[$dateStr] ?? null;
                        $daily[] = [
                            'date' => $dateStr,
                            'oee_percent' => $row && $row['cnt'] > 0 ? round($row['sum'] / $row['cnt'], 2) : null,
                        ];
                    }
                } else {
                    $byMonth = [];
                    foreach ($recs as $r) {
                        $mKey = Carbon::parse($r->shift_date, $appTimezone)->format('Y-m');
                        if (!isset($byMonth[$mKey])) $byMonth[$mKey] = ['sum' => 0.0, 'cnt' => 0];
                        $byMonth[$mKey]['sum'] += (float) $r->oee_percent;
                        $byMonth[$mKey]['cnt']++;
                    }
                    foreach ($dates as $monthKey) {
                        $row = $byMonth[$monthKey] ?? null;
                        $daily[] = [
                            'date' => $monthKey,
                            'oee_percent' => $row && $row['cnt'] > 0 ? round($row['sum'] / $row['cnt'], 2) : null,
                        ];
                    }
                }
            } else {
                // 2) Fallback (best-effort) jika belum ada snapshot oee_records sama sekali.
                // Untuk menjaga performa, fallback hanya aktif untuk mode bulanan (harian).
                if ($period === 'monthly') {
                    $machines = InspectionTable::where('division', $selectedDivision)
                        ->where('line_name', $lineName)
                        ->whereNotNull('address')
                        ->get(['address', 'name', 'cycle_time', 'cavity', 'ot_enabled']);

                    $machineByAddress = [];
                    foreach ($machines as $m) {
                        if ($m->address) $machineByAddress[$m->address] = $m;
                    }
                    $addresses = array_keys($machineByAddress);

                    foreach ($dates as $dateStr) {
                        $oeeSum = 0.0;
                        $cnt = 0;
                        foreach (['pagi', 'malam'] as $shift) {
                            foreach ($addresses as $addr) {
                                $machine = $machineByAddress[$addr];
                                $fb = $this->computeOeeFallbackForShiftDay($machine, $dateStr, $shift, $appTimezone);
                                if ($fb['oee'] !== null) {
                                    $oeeSum += (float) $fb['oee'];
                                    $cnt++;
                                }
                            }
                        }
                        $daily[] = [
                            'date' => $dateStr,
                            'oee_percent' => $cnt > 0 ? round($oeeSum / $cnt, 2) : null,
                        ];
                    }
                } else {
                    foreach ($dates as $monthKey) {
                        $daily[] = ['date' => $monthKey, 'oee_percent' => null];
                    }
                }
            }

            $linesData[] = [
                'line_name' => $lineName,
                'daily' => $daily,
            ];
        }

        $setting = OeeSetting::first();
        $target = $setting && $setting->target_efficiency_percent !== null ? (float) $setting->target_efficiency_percent : 96.0;

        return response()->json([
            'success' => true,
            'data' => [
                'divisions' => $availableDivisions,
                'division' => $selectedDivision,
                'dates' => $dates,
                'target_efficiency_percent' => $target,
                'lines' => $linesData,
                'filter' => [
                    'period' => $period,
                    'month' => $request->month,
                    'year' => $request->year,
                    'timezone' => $appTimezone,
                ],
            ],
        ]);
    }

    /**
     * Drilldown Efisiensi: semua mesin dalam line pada tanggal tertentu.
     * Mengembalikan OEE Reguler dan OT untuk shift pagi & malam.
     *
     * Query: division(optional), line_name(required), date(required Y-m-d)
     */
    public function getEfficiencyDrilldown(Request $request)
    {
        $request->validate([
            'division' => 'nullable|string',
            'line_name' => 'required|string',
            'date' => 'required|date_format:Y-m-d',
        ]);

        $division = $request->division;
        $lineName = $request->line_name;
        $dateStr = $request->date;
        $appTimezone = config('app.timezone', 'Asia/Jakarta');

        $machinesQ = InspectionTable::query()
            ->where('line_name', $lineName)
            ->whereNotNull('address')
            ->orderBy('name');
        if ($division) $machinesQ->where('division', $division);
        $machines = $machinesQ->get();

        $addresses = $machines->pluck('address')->filter()->values()->all();
        $scheduleRows = MachineSchedule::query()
            ->where('schedule_date', $dateStr)
            ->whereIn('machine_address', $addresses)
            ->get();
        $scheduleMap = [];
        foreach ($scheduleRows as $s) {
            $key = ($s->schedule_date ?? '') . '|' . ($s->shift ?? '') . '|' . ($s->machine_address ?? '');
            $scheduleMap[$key] = $s;
        }

        $rows = [];
        foreach ($machines as $m) {
            $addr = trim((string) $m->address);
            if ($addr === '') continue;

            $machineRow = [
                'name' => $m->name,
                'address' => $addr,
                'regular' => [
                    'pagi' => null,
                    'malam' => null,
                ],
                'ot' => [
                    'pagi' => null,
                    'malam' => null,
                ],
            ];

            foreach (['pagi', 'malam'] as $shift) {
                [$startUtc, $endUtc] = $this->resolveShiftWindow($dateStr, $shift, $appTimezone);
                [$_, $endRegulerUtc] = $this->resolveRegulerEndForShift($dateStr, $shift, $appTimezone);

                $scheduleKey = $dateStr . '|' . $shift . '|' . $addr;
                $schedule = $scheduleMap[$scheduleKey] ?? null;
                $otEnabled = $schedule ? (bool) $schedule->ot_enabled : false;
                $otDurationType = $schedule ? ($schedule->ot_duration_type ?? null) : null;

                $oeeRegular = $this->computeOeeSimpleInWindow($m, $dateStr, $shift, $startUtc, $endRegulerUtc, $appTimezone, false, null);
                $machineRow['regular'][$shift] = $oeeRegular;

                if ($otEnabled) {
                    $oeeOt = $this->computeOeeSimpleInWindow($m, $dateStr, $shift, $endRegulerUtc, $endUtc, $appTimezone, true, $otDurationType);
                    $machineRow['ot'][$shift] = $oeeOt;
                } else {
                    $machineRow['ot'][$shift] = null;
                }
            }

            $rows[] = $machineRow;
        }

        $setting = OeeSetting::first();
        $target = $setting && $setting->target_efficiency_percent !== null ? (float) $setting->target_efficiency_percent : 96.0;

        return response()->json([
            'success' => true,
            'data' => [
                'division' => $division,
                'line_name' => $lineName,
                'date' => $dateStr,
                'target_efficiency_percent' => $target,
                'machines' => $rows,
            ],
        ]);
    }

    private function computeOeeSimpleInWindow(
        InspectionTable $machine,
        string $dateStr,
        string $shift,
        Carbon $startUtc,
        Carbon $endUtc,
        string $appTimezone,
        bool $otEnabled,
        ?string $otDurationType
    ): ?float {
        $address = trim((string) ($machine->address ?? ''));
        if ($address === '') return null;

        $cavity = (int) ($machine->cavity ?? 1);
        if ($cavity < 1) $cavity = 1;
        $cycle = (int) ($machine->cycle_time ?? 0);
        if ($cycle <= 0) return null;

        $startApp = $startUtc->copy()->setTimezone($appTimezone);
        $endApp = $endUtc->copy()->setTimezone($appTimezone);

        $qty = $this->getLatestMachineQuantityInWindow($address, $startUtc, $endUtc, $appTimezone);
        $runningSeconds = $this->computeRunningHourSecondsForSnapshot(
            $endApp,
            $startApp,
            $shift,
            $appTimezone,
            $otEnabled,
            $otDurationType
        );
        if ($runningSeconds <= 0) return null;

        $idealSeconds = (int) $qty * $cavity * $cycle;
        $oee = ($idealSeconds / $runningSeconds) * 100.0;
        if (!is_finite($oee)) return null;
        return round(max(0.0, $oee), 2);
    }

    /**
     * Fallback OEE jika belum ada baris oee_records (tanpa data runtime historis → runtime = running hour).
     */
    private function computeOeeFallbackForShiftDay(InspectionTable $machine, string $dateStr, string $shift, string $appTimezone): array
    {
        $address = trim($machine->address ?? '');
        if ($address === '') {
            return ['oee' => null, 'availability' => null, 'performance' => null, 'quality' => 100.0];
        }

        $cavity = (int) ($machine->cavity ?? 1);
        if ($cavity < 1) {
            $cavity = 1;
        }
        $cycle = (int) ($machine->cycle_time ?? 0);

        [$startUtc, $endUtc] = $this->resolveShiftWindow($dateStr, $shift, $appTimezone);
        $startApp = $startUtc->copy()->setTimezone($appTimezone);
        $endApp = $endUtc->copy()->setTimezone($appTimezone);

        $qty = $this->getLatestMachineQuantityInWindow($address, $startUtc, $endUtc, $appTimezone);
        $rh = $this->computeRunningHourSecondsForSnapshot(
            $endApp,
            $startApp,
            $shift,
            $appTimezone,
            (bool) ($machine->ot_enabled ?? false),
            $machine->ot_duration_type ?? null
        );
        $rt = $rh;

        $totalProduct = max(0, $qty) * $cavity;
        $idealBoard = ($cycle > 0 && $rh > 0) ? (int) floor($rh / $cycle) * $cavity : 0;
        $idealPerf = ($cycle > 0 && $rt > 0) ? (int) floor($rt / $cycle) * $cavity : 0;

        $oee = ($idealBoard > 0) ? round(($totalProduct / $idealBoard) * 100, 2) : null;
        $availability = ($rh > 0) ? round(($rt / $rh) * 100, 2) : null;
        $performance = ($idealPerf > 0) ? round(($totalProduct / $idealPerf) * 100, 2) : null;

        return [
            'oee' => $oee,
            'availability' => $availability,
            'performance' => $performance,
            'quality' => 100.0,
        ];
    }

    /**
     * Anchor counter sebelum window shift: snapshot hourly terakhir, atau record production_data terakhir
     * sebelum shift dimulai (agar delta jam pertama shift tidak membawa kumulatif shift sebelumnya).
     *
     * @return array{0: ?Carbon, 1: int} waktu anchor (app tz), quantity board
     */
    private function resolveQuantityHourlyAnchorBeforeShift(
        string $normalizedAddress,
        string $addressLower,
        string $startStr,
        string $appTimezone
    ): array {
        $hourly = ProductionDataHourly::query()
            ->whereRaw('LOWER(TRIM(machine_name)) = ?', [$addressLower])
            ->where('snapshot_at', '<', $startStr)
            ->orderBy('snapshot_at', 'desc')
            ->first();
        if ($hourly) {
            $t = $hourly->snapshot_at instanceof Carbon
                ? $hourly->snapshot_at->copy()->setTimezone($appTimezone)
                : Carbon::parse($hourly->snapshot_at, $appTimezone);

            return [$t, max(0, (int) $hourly->quantity)];
        }

        $pd = ProductionData::query()
            ->where('timestamp', '<', $startStr)
            ->where(function ($q) use ($normalizedAddress, $addressLower) {
                $q->where('machine_name', $normalizedAddress)
                    ->orWhereRaw('LOWER(TRIM(machine_name)) = ?', [$addressLower])
                    ->orWhereRaw('LOWER(machine_name) LIKE ?', ['%' . $addressLower . '%']);
            })
            ->orderBy('timestamp', 'desc')
            ->first();
        if ($pd) {
            $ts = $pd->timestamp instanceof Carbon
                ? $pd->timestamp->copy()->setTimezone($appTimezone)
                : Carbon::parse($pd->timestamp, $appTimezone);

            return [$ts, max(0, (int) $pd->quantity)];
        }

        return [null, 0];
    }

    /**
     * Ideal kumulatif (pieces) sampai snapshot — sama dengan logika chart sebelumnya, untuk dipakai sebagai selisih antar titik.
     */
    private function computeCumulativeIdealQuantityForSnapshot(
        Carbon $snapshotAtApp,
        Carbon $shiftStartApp,
        string $shift,
        string $appTimezone,
        int $cycleTime,
        int $cavity,
        bool $otEnabled,
        ?string $otDurationType
    ): int {
        if ($cycleTime <= 0) {
            return 0;
        }
        $runningSeconds = $this->computeRunningHourSecondsForSnapshot(
            $snapshotAtApp,
            $shiftStartApp,
            $shift,
            $appTimezone,
            $otEnabled,
            $otDurationType
        );
        if ($runningSeconds <= 0) {
            return 0;
        }

        return (int) floor($runningSeconds / $cycleTime) * $cavity;
    }

    /**
     * Klasifikasi interval (jam awal selang = snapshot sebelumnya) untuk split Reguler vs OT,
     * selaras dengan frontend analytics.js.
     */
    private function classifyQuantityHourlyIntervalBand(string $shift, int $hour): string
    {
        if ($shift === 'pagi') {
            if ($hour <= 15) {
                return 'regular';
            }
            if ($hour >= 16) {
                return 'ot';
            }

            return 'regular';
        }
        if ($hour >= 5 && $hour <= 6) {
            return 'ot';
        }

        return 'regular';
    }

    /**
     * Bangun deret per titik snapshot: quantity & ideal dalam bentuk kumulatif sejak awal shift
     * (garis chart menanjak). Selang waktu tetap diisi (period_start/end) untuk klasifikasi Reguler/OT.
     *
     * @param \Illuminate\Database\Eloquent\Collection<int, ProductionDataHourly> $rowsInWindow
     * @return array<int, array<string, mixed>>
     */
    private function buildQuantityHourlyCumulativeSeries(
        $rowsInWindow,
        Carbon $anchorTime,
        int $anchorBoardQty,
        Carbon $shiftStartApp,
        string $shift,
        string $appTimezone,
        int $cycleTime,
        int $cavity,
        array $otSettingsForDay
    ): array {
        $otEnabled = $otSettingsForDay['enabled'];
        $otDur = $otSettingsForDay['duration_type'] ?? null;

        $out = [];
        $prevSnap = $anchorTime->copy();
        $prevQty = max(0, $anchorBoardQty);

        $cumTotal = 0;
        $cumRegular = 0;
        $cumOt = 0;

        foreach ($rowsInWindow as $curr) {
            $currSnap = $curr->snapshot_at->copy()->setTimezone($appTimezone);

            $pq = max(0, $prevQty);
            $cq = max(0, (int) $curr->quantity);
            $boardDelta = ($cq >= $pq) ? ($cq - $pq) : $cq;

            $qtyDelta = $boardDelta * $cavity;

            $hour = (int) $prevSnap->format('G');
            $band = $this->classifyQuantityHourlyIntervalBand($shift, $hour);

            $cumTotal += $qtyDelta;
            if ($otEnabled) {
                if ($band === 'ot') {
                    $cumOt += $qtyDelta;
                } else {
                    $cumRegular += $qtyDelta;
                }
            }

            $idealCum = $this->computeCumulativeIdealQuantityForSnapshot(
                $currSnap,
                $shiftStartApp,
                $shift,
                $appTimezone,
                $cycleTime,
                $cavity,
                $otEnabled,
                $otDur
            );

            $row = [
                'period_start' => $prevSnap->format('Y-m-d H:i'),
                'period_end' => $currSnap->format('Y-m-d H:i'),
                'snapshot_at' => $currSnap->format('Y-m-d H:i'),
                'label' => $currSnap->format('d/m H:i'),
                'quantity' => (int) $cumTotal,
                'ideal_quantity' => (int) $idealCum,
            ];
            if ($otEnabled) {
                $row['quantity_cumulative_regular'] = (int) $cumRegular;
                $row['quantity_cumulative_ot'] = (int) $cumOt;
            }

            $out[] = $row;

            $prevSnap = $currSnap;
            $prevQty = $cq;
        }

        return $out;
    }

    /**
     * Data quantity per jam dari production_data_hourly untuk satu mesin (grafik line per jam).
     * Query sesuai tanggal dan shift yang dipilih.
     * Quantity & ideal per titik = nilai kumulatif sejak awal shift (garis menanjak).
     * period_start / period_end tetap diisi (awal–akhir selang antar snapshot) untuk klasifikasi Reguler vs OT.
     * - Pagi: reguler jam ≤15, OT jam ≥16 (sampai akhir window shift).
     * - Malam: OT jam 5–6; selain itu reguler (termasuk jam 7 = bagian akhir shift malam).
     * - Jika ot_enabled=false: satu seri quantity saja (tanpa split OT).
     */
    public function getQuantityHourly(Request $request)
    {
        $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'shift' => 'required|in:pagi,malam',
            'machine_address' => 'required|string',
        ]);

        $appTimezone = config('app.timezone', 'Asia/Jakarta');
        $address = trim($request->machine_address);
        $addressLower = strtolower($address);
        $normalizedAddress = $address;
        $otSettingsForDay = $this->getOtSettingsForMachineForScheduleDay(
            $address,
            $request->date,
            $request->shift
        );
        $otEnabled = $otSettingsForDay['enabled'];
        $cavity = $this->getCavityForMachine($address);

        [$startUtc, $endUtc] = $this->resolveShiftWindow(
            $request->date,
            $request->shift,
            $appTimezone
        );
        $startApp = $startUtc->copy()->setTimezone($appTimezone);
        $endApp = $endUtc->copy()->setTimezone($appTimezone);
        $startStr = $startApp->format('Y-m-d H:i:s');
        $endStr = $endApp->format('Y-m-d H:i:s');

        // IMPORTANT:
        // machine_name di production_data / production_data_hourly di lapangan kadang punya whitespace / case beda.
        // Gunakan TRIM+LOWER agar hasil konsisten (tidak "kadang OT muncul, kadang hilang").
        $rows = ProductionDataHourly::query()
            ->whereRaw('LOWER(TRIM(machine_name)) = ?', [$addressLower])
            ->whereBetween('snapshot_at', [$startStr, $endStr])
            ->orderBy('snapshot_at', 'asc')
            ->get();

        $cycleTime = $this->getCycleTimeForMachine($address);

        [$anchorTime, $anchorBoardQty] = $this->resolveQuantityHourlyAnchorBeforeShift(
            $normalizedAddress,
            $addressLower,
            $startStr,
            $appTimezone
        );
        if ($anchorTime === null) {
            $anchorTime = $startApp->copy();
            $anchorBoardQty = 0;
        }

        $data = $this->buildQuantityHourlyCumulativeSeries(
            $rows,
            $anchorTime,
            $anchorBoardQty,
            $startApp,
            $request->shift,
            $appTimezone,
            $cycleTime,
            $cavity,
            $otSettingsForDay
        );

        return response()->json([
            'success' => true,
            'data' => $data,
            'ot_enabled' => $otEnabled,
        ]);
    }

    /**
     * Drill-down quantity untuk klik bar chart Production Quantity.
     * - Daily   -> detail per jam
     * - Monthly -> detail per hari
     * - Yearly  -> detail per bulan
     */
    public function getQuantityDrilldown(Request $request)
    {
        $request->validate([
            'period' => 'required|in:daily,monthly,yearly',
            'shift' => 'required|in:pagi,malam',
            'machine_address' => 'required|string',
            'date' => 'required_if:period,daily|nullable|date_format:Y-m-d',
            'month' => 'required_if:period,monthly|nullable|date_format:Y-m',
            'year' => 'required_if:period,yearly|nullable|date_format:Y',
        ]);

        $appTimezone = config('app.timezone', 'Asia/Jakarta');
        $period = $request->period;
        $shift = $request->shift;
        $address = trim($request->machine_address);
        $cavity = $this->getCavityForMachine($address);

        if ($period === 'daily') {
            $date = $request->date;
            $addressLower = strtolower($address);
            $normalizedAddress = trim($address);
            $otSettingsForDay = $this->getOtSettingsForMachineForScheduleDay($address, $date, $shift);
            $otEnabled = $otSettingsForDay['enabled'];

            [$startUtc, $endUtc] = $this->resolveShiftWindow($date, $shift, $appTimezone);
            $startApp = $startUtc->copy()->setTimezone($appTimezone);
            $endApp = $endUtc->copy()->setTimezone($appTimezone);
            $startStr = $startApp->format('Y-m-d H:i:s');
            $endStr = $endApp->format('Y-m-d H:i:s');

            $rows = ProductionDataHourly::query()
                ->whereRaw('LOWER(TRIM(machine_name)) = ?', [$addressLower])
                ->whereBetween('snapshot_at', [$startStr, $endStr])
                ->orderBy('snapshot_at', 'asc')
                ->get();

            $cycleTime = $this->getCycleTimeForMachine($address);

            [$anchorTime, $anchorBoardQty] = $this->resolveQuantityHourlyAnchorBeforeShift(
                $normalizedAddress,
                $addressLower,
                $startStr,
                $appTimezone
            );
            if ($anchorTime === null) {
                $anchorTime = $startApp->copy();
                $anchorBoardQty = 0;
            }

            $data = $this->buildQuantityHourlyCumulativeSeries(
                $rows,
                $anchorTime,
                $anchorBoardQty,
                $startApp,
                $shift,
                $appTimezone,
                $cycleTime,
                $cavity,
                $otSettingsForDay
            );

            return response()->json([
                'success' => true,
                'period' => $period,
                'granularity' => 'hourly',
                'data' => $data,
                'ot_enabled' => $otEnabled,
            ]);
        }

        if ($period === 'monthly') {
            $monthStart = Carbon::parse($request->month . '-01', $appTimezone)->startOfMonth();
            $daysInMonth = $monthStart->daysInMonth;
            $monthEnd = $monthStart->copy()->endOfMonth();
            $dailyMap = $this->getProductionDataShiftQuantityMap($address, $shift, $monthStart, $monthEnd, $appTimezone);
            $addressLower = strtolower($address);
            $scheduleRows = MachineSchedule::query()
                ->whereDate('schedule_date', '>=', $monthStart->format('Y-m-d'))
                ->whereDate('schedule_date', '<=', $monthEnd->format('Y-m-d'))
                ->where('shift', $shift)
                ->whereRaw('LOWER(TRIM(machine_address)) = ?', [$addressLower])
                ->get(['schedule_date', 'target_quantity', 'target_ot', 'ot_enabled']);
            $scheduleMap = [];
            foreach ($scheduleRows as $s) {
                $dateStr = $s->schedule_date instanceof Carbon
                    ? $s->schedule_date->format('Y-m-d')
                    : (string) $s->schedule_date;
                $targetReg = max(0, (int) ($s->target_quantity ?? 0));
                $targetOt = ((bool) ($s->ot_enabled ?? false)) ? max(0, (int) ($s->target_ot ?? 0)) : 0;
                $scheduleMap[$dateStr] = $targetReg + $targetOt;
            }
            $points = [];

            for ($day = 1; $day <= $daysInMonth; $day++) {
                $date = $monthStart->copy()->day($day)->format('Y-m-d');
                $qty = ((int) ($dailyMap[$date] ?? 0)) * $cavity;
                // Ideal Qty dari schedule sudah bersifat target per mesin per hari (jangan dikali cavity lagi).
                $idealQty = (int) ($scheduleMap[$date] ?? 0);
                $points[] = [
                    'label' => str_pad((string) $day, 2, '0', STR_PAD_LEFT),
                    'snapshot_at' => $date,
                    'quantity' => (int) $qty,
                    'ideal_quantity' => (int) $idealQty,
                ];
            }

            return response()->json([
                'success' => true,
                'period' => $period,
                'granularity' => 'daily',
                'data' => $points,
                'ot_enabled' => false,
            ]);
        }

        $yearInt = (int) $request->year;
        $yearStart = Carbon::create($yearInt, 1, 1, 0, 0, 0, $appTimezone)->startOfDay();
        $yearEnd = Carbon::create($yearInt, 12, 31, 23, 59, 59, $appTimezone)->endOfDay();
        $dailyMap = $this->getProductionDataShiftQuantityMap($address, $shift, $yearStart, $yearEnd, $appTimezone);
        $monthlyTotals = array_fill(1, 12, 0);
        foreach ($dailyMap as $dateStr => $dayQty) {
            $m = (int) Carbon::parse($dateStr, $appTimezone)->month;
            if ($m >= 1 && $m <= 12) {
                $monthlyTotals[$m] += ((int) $dayQty) * $cavity;
            }
        }

        $points = [];
        for ($month = 1; $month <= 12; $month++) {
            $monthStart = Carbon::create($yearInt, $month, 1, 0, 0, 0, $appTimezone)->startOfMonth();
            $points[] = [
                'label' => $monthStart->translatedFormat('M'),
                'snapshot_at' => $monthStart->format('Y-m'),
                'quantity' => (int) ($monthlyTotals[$month] ?? 0),
                'ideal_quantity' => null,
            ];
        }

        return response()->json([
            'success' => true,
            'period' => $period,
            'granularity' => 'monthly',
            'data' => $points,
            'ot_enabled' => false,
        ]);
    }

    /**
     * Ambil qty harian per production-date dari production_data utama untuk shift tertentu.
     * Nilai yang diambil adalah quantity terbaru pada window shift per tanggal produksi.
     *
     * @return array<string,int> map Y-m-d => quantity
     */
    private function getProductionDataShiftQuantityMap(
        string $address,
        string $shift,
        Carbon $startDateApp,
        Carbon $endDateApp,
        string $appTimezone
    ): array {
        $normalizedAddress = trim($address);
        $lowerAddress = strtolower($normalizedAddress);

        if ($normalizedAddress === '') {
            return [];
        }

        if ($shift === 'pagi') {
            $queryStart = $startDateApp->copy()->setTime(7, 1, 0);
            $queryEnd = $endDateApp->copy()->setTime(20, 0, 59);
        } else {
            $queryStart = $startDateApp->copy()->setTime(20, 1, 0);
            $queryEnd = $endDateApp->copy()->addDay()->setTime(7, 0, 59);
        }

        $rows = ProductionData::query()
            ->whereBetween('timestamp', [$queryStart->format('Y-m-d H:i:s'), $queryEnd->format('Y-m-d H:i:s')])
            ->where(function ($q) use ($normalizedAddress, $lowerAddress) {
                $q->where('machine_name', $normalizedAddress)
                    ->orWhereRaw('LOWER(TRIM(machine_name)) = ?', [$lowerAddress])
                    ->orWhereRaw('LOWER(machine_name) LIKE ?', ['%' . $lowerAddress . '%']);
            })
            ->orderBy('timestamp', 'asc')
            ->get(['timestamp', 'quantity']);

        $latestByDate = [];
        foreach ($rows as $row) {
            $ts = $row->timestamp instanceof Carbon
                ? $row->timestamp->copy()->setTimezone($appTimezone)
                : Carbon::parse($row->timestamp, $appTimezone);
            $productionDate = $this->resolveProductionDateForShiftTimestamp($ts, $shift);
            if ($productionDate === null) {
                continue;
            }

            if ($productionDate < $startDateApp->format('Y-m-d') || $productionDate > $endDateApp->format('Y-m-d')) {
                continue;
            }

            $currTs = $latestByDate[$productionDate]['ts'] ?? null;
            if ($currTs === null || $ts->greaterThan($currTs)) {
                $latestByDate[$productionDate] = [
                    'ts' => $ts,
                    'qty' => max(0, (int) $row->quantity),
                ];
            }
        }

        $map = [];
        foreach ($latestByDate as $date => $v) {
            $map[$date] = (int) ($v['qty'] ?? 0);
        }
        return $map;
    }

    /**
     * Tentukan tanggal produksi (Y-m-d) dari timestamp lokal sesuai shift.
     */
    private function resolveProductionDateForShiftTimestamp(Carbon $ts, string $shift): ?string
    {
        $h = (int) $ts->format('H');
        $m = (int) $ts->format('i');
        $s = (int) $ts->format('s');
        $secOfDay = $h * 3600 + $m * 60 + $s;

        // Pagi: mulai setelah reset malam (07:01) s.d. akhir jam 20:00 (termasuk 20:00:xx).
        $pagiStart = 7 * 3600 + 1 * 60;
        $pagiEnd = 20 * 3600 + 59;
        // Malam: mulai setelah reset pagi (20:01) s.d. akhir jam 07:00 hari berikutnya (termasuk 07:00:xx).
        $malamStart = 20 * 3600 + 1 * 60;
        $malamEnd = 7 * 3600 + 59;

        if ($shift === 'pagi') {
            if ($secOfDay >= $pagiStart && $secOfDay <= $pagiEnd) {
                return $ts->format('Y-m-d');
            }
            return null;
        }

        if ($secOfDay >= $malamStart) {
            return $ts->format('Y-m-d');
        }
        if ($secOfDay <= $malamEnd) {
            return $ts->copy()->subDay()->format('Y-m-d');
        }
        return null;
    }

    /**
     * Pastikan ada titik OEE untuk jam terakhir window shift (20:00 pagi / 07:00 malam)
     * bila DB belum menulis baris pada jam tersebut — isi forward dari snapshot terakhir.
     *
     * @param array<int, array<string, mixed>> $data
     * @return array<int, array<string, mixed>>
     */
    private function ensureOeeHourlyEndSlot(array $data, Carbon $endSlotApp, string $appTimezone): array
    {
        if ($data === []) {
            return $data;
        }

        $targetHourKey = $endSlotApp->format('Y-m-d H');
        foreach ($data as $row) {
            if (!isset($row['snapshot_at'])) {
                continue;
            }
            $t = Carbon::parse($row['snapshot_at'], $appTimezone);
            if ($t->format('Y-m-d H') === $targetHourKey) {
                return $data;
            }
        }

        $last = $data[count($data) - 1];
        $lastT = Carbon::parse($last['snapshot_at'], $appTimezone);
        if ($lastT->greaterThan($endSlotApp)) {
            return $data;
        }

        $padded = $last;
        $padded['snapshot_at'] = $endSlotApp->format('Y-m-d H:i');
        $data[] = $padded;

        return $data;
    }

    /**
     * OEE per jam dari oee_records_hourly untuk satu mesin (line + stacked A/P/Q).
     */
    public function getOeeHourly(Request $request)
    {
        $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'shift' => 'required|in:pagi,malam',
            'machine_address' => 'required|string',
        ]);

        $appTimezone = config('app.timezone', 'Asia/Jakarta');
        $address = trim($request->machine_address);
        $addressLower = strtolower($address);

        [$startUtc, $endUtc] = $this->resolveShiftWindow(
            $request->date,
            $request->shift,
            $appTimezone
        );
        $startApp = $startUtc->copy()->setTimezone($appTimezone);
        $endApp = $endUtc->copy()->setTimezone($appTimezone);
        $startStr = $startApp->format('Y-m-d H:i:s');
        $endStr = $endApp->format('Y-m-d H:i:s');

        $rows = OeeRecordHourly::query()
            ->where('machine_address', $address)
            ->whereBetween('snapshot_at', [$startStr, $endStr])
            ->orderBy('snapshot_at', 'asc')
            ->get();

        if ($rows->isEmpty() && $address !== '') {
            $rows = OeeRecordHourly::query()
                ->whereRaw('LOWER(TRIM(machine_address)) = ?', [$addressLower])
                ->whereBetween('snapshot_at', [$startStr, $endStr])
                ->orderBy('snapshot_at', 'asc')
                ->get();
        }

        $data = $rows->map(function ($row) use ($appTimezone) {
            $snapshotAt = $row->snapshot_at;
            $snapshotAtApp = $snapshotAt instanceof Carbon
                ? $snapshotAt->copy()->setTimezone($appTimezone)
                : Carbon::parse($snapshotAt)->setTimezone($appTimezone);

            return [
                'snapshot_at' => $snapshotAtApp->format('Y-m-d H:i'),
                'oee_percent' => $row->oee_percent !== null ? round((float) $row->oee_percent, 2) : null,
                'availability_percent' => $row->availability_percent !== null ? round((float) $row->availability_percent, 2) : null,
                'performance_percent' => $row->performance_percent !== null ? round((float) $row->performance_percent, 2) : null,
                'quality_percent' => $row->quality_percent !== null ? round((float) $row->quality_percent, 2) : 100.0,
            ];
        })->values()->all();

        $shiftDate = Carbon::parse($request->date, $appTimezone);
        $endSlotApp = $request->shift === 'pagi'
            ? $shiftDate->copy()->setTime(20, 0, 0)
            : $shiftDate->copy()->addDay()->setTime(7, 0, 0);
        $data = $this->ensureOeeHourlyEndSlot($data, $endSlotApp, $appTimezone);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * @return array{enabled: bool, duration_type: ?string}
     */
    private function getOtSettingsForMachine(string $address): array
    {
        $trimmed = trim($address);
        $lower = strtolower($trimmed);

        $table = InspectionTable::query()
            ->whereRaw('LOWER(TRIM(address)) = ?', [$lower])
            ->first();
        if (!$table) {
            return ['enabled' => false, 'duration_type' => null];
        }

        return [
            'enabled' => (bool) ($table->ot_enabled ?? false),
            'duration_type' => $table->ot_duration_type,
        ];
    }

    /**
     * OT untuk tanggal & shift tertentu dari jadwal harian (machine_schedules).
     * Penting untuk chart quantity per jam historis: jangan pakai hanya flag OT di InspectionTable
     * (kondisi hari ini), supaya ideal Qty dan pemisahan OT tetap benar untuk tanggal lampau.
     *
     * @return array{enabled: bool, duration_type: ?string}
     */
    private function getOtSettingsForMachineForScheduleDay(string $address, string $dateYmd, string $shift): array
    {
        $lower = strtolower(trim($address));
        $fromTable = $this->getOtSettingsForMachine($address);

        $schedule = MachineSchedule::query()
            ->whereDate('schedule_date', $dateYmd)
            ->where('shift', $shift)
            ->whereRaw('LOWER(TRIM(machine_address)) = ?', [$lower])
            ->first();

        if (!$schedule) {
            return $fromTable;
        }

        $rawEnabled = $schedule->getAttributes()['ot_enabled'] ?? null;
        if ($rawEnabled === null) {
            return $fromTable;
        }

        $dayOtEnabled = $this->normalizeBooleanish($rawEnabled);
        if (!$dayOtEnabled) {
            return ['enabled' => false, 'duration_type' => null];
        }

        $duration = $schedule->ot_duration_type;
        if ($duration === null || $duration === '') {
            $duration = $fromTable['duration_type'];
        }

        return [
            'enabled' => true,
            'duration_type' => $duration,
        ];
    }

    private function normalizeBooleanish(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return false;
    }

    /**
     * Ambil cycle time (detik per pcs) untuk mesin berdasarkan address, dengan cache sederhana.
     */
    private function getCycleTimeForMachine(string $address): int
    {
        $key = trim($address);
        if ($key === '') {
            return 0;
        }
        if (array_key_exists($key, $this->cycleTimeCache)) {
            return $this->cycleTimeCache[$key];
        }

        $table = InspectionTable::query()
            ->whereRaw('LOWER(TRIM(address)) = ?', [strtolower($key)])
            ->first();
        $cycle = $table && $table->cycle_time ? (int) $table->cycle_time : 0;
        $this->cycleTimeCache[$key] = $cycle;
        return $cycle;
    }

    /**
     * Ambil nilai cavity untuk mesin berdasarkan address, dengan cache sederhana.
     * Default ke 1 jika tidak ada data atau nilai tidak valid.
     */
    private function getCavityForMachine(string $address): int
    {
        $key = trim($address);
        if ($key === '') {
            return 1;
        }
        if (array_key_exists($key, $this->cavityCache)) {
            return $this->cavityCache[$key];
        }

        $table = InspectionTable::query()
            ->whereRaw('LOWER(TRIM(address)) = ?', [strtolower($key)])
            ->first();
        $cavity = 1;
        if ($table && $table->cavity !== null) {
            $cavityValue = (int) $table->cavity;
            $cavity = $cavityValue > 0 ? $cavityValue : 1;
        }

        $this->cavityCache[$key] = $cavity;
        return $cavity;
    }

    /**
     * Build map [dateStr][address] => MachineSchedule untuk target history dari schedule.
     */
    private function buildScheduleMapForDates(array $dates, array $addresses, string $shift): array
    {
        if (empty($addresses) || empty($dates)) {
            return [];
        }
        $schedules = MachineSchedule::query()
            ->whereIn('schedule_date', $dates)
            ->where('shift', $shift)
            ->whereIn('machine_address', $addresses)
            ->get();
        $map = [];
        foreach ($dates as $d) {
            $map[$d] = [];
        }
        foreach ($schedules as $s) {
            $dateStr = $s->schedule_date instanceof \Carbon\Carbon
                ? $s->schedule_date->format('Y-m-d')
                : $s->schedule_date;
            if (isset($map[$dateStr])) {
                $map[$dateStr][$s->machine_address] = $s;
            }
        }
        return $map;
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
     * Counter di lapangan di-reset sekitar 07:01 (akhir shift malam) dan 20:01 (akhir shift pagi).
     * - Pagi: snapshot dari 07:01:00 s.d. 20:00:59 hari D (jam 20:00 masih shift pagi; 20:01+ = shift malam).
     * - Malam: snapshot dari 20:01:00 hari D s.d. 07:00:59 hari D+1 (jam 07:00 masih shift malam; 07:01+ = shift pagi).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveShiftWindow(string $dateStr, string $shift, string $appTimezone): array
    {
        $date = Carbon::parse($dateStr, $appTimezone);

        if ($shift === 'pagi') {
            $startApp = $date->copy()->setTime(7, 1, 0);
            $endApp = $date->copy()->setTime(20, 0, 59);
        } else {
            $startApp = $date->copy()->setTime(20, 1, 0);
            $endApp = $date->copy()->addDay()->setTime(7, 0, 59);
        }

        $startUtc = $startApp->copy()->utc();
        $endUtc = $endApp->copy()->utc();

        return [$startUtc, $endUtc];
    }

    /**
     * Hitung Running Hour (detik) sampai timestamp snapshot tertentu,
     * mengikuti logika break schedule yang sama dengan dashboard realtime.
     */
    private function computeRunningHourSecondsForSnapshot(
        Carbon $snapshotAtApp,
        Carbon $shiftStartApp,
        string $shift,
        string $appTimezone,
        bool $otEnabled = false,
        ?string $otDurationType = null
    ): int {
        // Jika snapshot sebelum awal shift, tidak ada running hour.
        if ($snapshotAtApp->lt($shiftStartApp)) {
            return 0;
        }

        $elapsed = max(0, $shiftStartApp->diffInSeconds($snapshotAtApp));
        $otSec = RunningHourOtExtension::extraSeconds($otEnabled, $otDurationType, $shift);
        $maxSeconds = 9 * 3600 + $otSec;

        // Ambil jadwal kerja & istirahat untuk hari & shift ini
        $dayOfWeek = $shiftStartApp->isoWeekday(); // 1 (Senin) - 7 (Minggu)
        $schedule = BreakSchedule::where('day_of_week', $dayOfWeek)
            ->where('shift', $shift)
            ->first();

        if (!$schedule || !$schedule->work_start || !$schedule->work_end) {
            return min($elapsed, $maxSeconds);
        }

        $base = $shiftStartApp->copy()->startOfDay();

        $ws = $this->parseTimeStringToHm($schedule->work_start);
        $we = $this->parseTimeStringToHm($schedule->work_end);
        if (!$ws || !$we) {
            return min($elapsed, $maxSeconds);
        }

        [$wsH, $wsM] = $ws;
        [$weH, $weM] = $we;

        $workStartM = $base->copy()->setTime($wsH, $wsM, 0, 0);
        $workEndM = $base->copy()->setTime($weH, $weM, 0, 0);

        // Jika jam akhir < jam awal, berarti nyebrang hari
        if ($weH < $wsH || ($weH === $wsH && $weM < $wsM)) {
            $workEndM->addDay();
        }

        if ($otSec > 0) {
            $workEndM->addSeconds($otSec);
        }

        $effectiveStart = $shiftStartApp->greaterThan($workStartM) ? $shiftStartApp->copy() : $workStartM;
        $effectiveEnd = $snapshotAtApp->lessThan($workEndM) ? $snapshotAtApp->copy() : $workEndM;

        if ($effectiveEnd->lte($effectiveStart)) {
            return 0;
        }

        $runningSec = max(0, $effectiveStart->diffInSeconds($effectiveEnd));

        // Kurangi durasi istirahat yang overlap
        $breaks = $schedule->getBreaksArray();
        $isMalam = $shift === 'malam';
        foreach ($breaks as $b) {
            $bs = $this->parseTimeStringToHm($b['start'] ?? null);
            $be = $this->parseTimeStringToHm($b['end'] ?? null);
            if (!$bs || !$be) {
                continue;
            }
            [$bsH, $bsM] = $bs;
            [$beH, $beM] = $be;

            $breakStartM = $base->copy()->setTime($bsH, $bsM, 0, 0);
            $breakEndM = $base->copy()->setTime($beH, $beM, 0, 0);

            if ($isMalam && $bsH < 12) {
                // Untuk shift malam, jam < 12 dianggap hari+1
                $breakStartM->addDay();
                $breakEndM->addDay();
            } elseif ($beH < $bsH || ($beH === $bsH && $beM < $bsM)) {
                $breakEndM->addDay();
            }

            // Hitung overlap antara window kerja efektif dan window break
            $overStart = $effectiveStart->greaterThan($breakStartM) ? $effectiveStart : $breakStartM;
            $overEnd = $effectiveEnd->lessThan($breakEndM) ? $effectiveEnd : $breakEndM;

            if ($overEnd->gt($overStart)) {
                $overSec = max(0, $overStart->diffInSeconds($overEnd));
                $runningSec -= $overSec;
            }
        }

        $runningSec = max(0, $runningSec);
        return min($runningSec, $maxSeconds);
    }

    /**
     * Parse string waktu seperti "HH:MM" atau "HH:MM:SS" menjadi [h, m].
     */
    private function parseTimeStringToHm(?string $time): ?array
    {
        if (!$time) {
            return null;
        }
        $time = trim($time);
        // Ambil hanya HH:MM dari HH:MM:SS
        $parts = explode(':', $time);
        if (count($parts) < 2) {
            return null;
        }
        $h = (int) $parts[0];
        $m = (int) $parts[1];
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            return null;
        }
        return [$h, $m];
    }

    /**
     * Akhir periode reguler (sebelum OT): pagi 15:59:59, malam 04:59:59 hari berikutnya.
     * Untuk hitung actual_quantity_regular vs actual_quantity_ot.
     *
     * @return array{0: Carbon, 1: Carbon} startUtc, endRegulerUtc
     */
    private function resolveRegulerEndForShift(string $dateStr, string $shift, string $appTimezone): array
    {
        [$startUtc, $endUtc] = $this->resolveShiftWindow($dateStr, $shift, $appTimezone);
        $date = Carbon::parse($dateStr, $appTimezone);

        if ($shift === 'pagi') {
            $endRegulerApp = $date->copy()->setTime(15, 59, 59);
        } else {
            $endRegulerApp = $date->copy()->addDay()->setTime(4, 59, 59);
        }
        $endRegulerUtc = $endRegulerApp->copy()->utc();
        return [$startUtc, $endRegulerUtc];
    }

    /**
     * Ambil quantity aktual untuk window shift dari tabel production_data.
     * Data dipilih berdasarkan timestamp (tanggal + jam) dalam rentang shift (pagi/malam).
     * Nilai = record terakhir dalam window (quantity di akhir shift).
     * Fallback ke production_data_hourly hanya jika production_data tidak punya data.
     */
    private function getLatestMachineQuantityInWindow(?string $address, Carbon $startUtc, Carbon $endUtc, string $appTimezone = 'Asia/Jakarta'): int
    {
        if (!$address) {
            return 0;
        }

        $normalizedAddress = trim($address);
        $lowerAddress = strtolower($normalizedAddress);

        $startLocal = $startUtc->copy()->setTimezone($appTimezone);
        $endLocal = $endUtc->copy()->setTimezone($appTimezone);
        $startStr = $startLocal->format('Y-m-d H:i:s');
        $endStr = $endLocal->format('Y-m-d H:i:s');

        // Selalu ambil dari production_data dulu (untuk history dan realtime).
        // Timestamp berisi tanggal dan jam sehingga rentang shift (pagi/malam) bisa ditentukan.
        $windowBase = function () use ($startStr, $endStr) {
            return ProductionData::whereBetween('timestamp', [$startStr, $endStr])
                ->orderBy('timestamp', 'desc');
        };
        $latest = $windowBase()->where('machine_name', $normalizedAddress)->first();
        if ($latest) {
            return max(0, (int) $latest->quantity);
        }
        $latest = ProductionData::whereBetween('timestamp', [$startStr, $endStr])
            ->whereRaw('LOWER(TRIM(machine_name)) = ?', [$lowerAddress])
            ->orderBy('timestamp', 'desc')
            ->first();
        if ($latest) {
            return max(0, (int) $latest->quantity);
        }
        $latest = ProductionData::whereBetween('timestamp', [$startStr, $endStr])
            ->whereRaw('LOWER(machine_name) LIKE ?', ['%' . $lowerAddress . '%'])
            ->orderBy('timestamp', 'desc')
            ->first();
        if ($latest) {
            return max(0, (int) $latest->quantity);
        }

        // Fallback: production_data_hourly hanya jika production_data tidak ada record dalam window
        $hourlyBase = function () use ($startStr, $endStr) {
            return ProductionDataHourly::whereBetween('snapshot_at', [$startStr, $endStr])
                ->orderBy('snapshot_at', 'desc');
        };
        $latest = $hourlyBase()->where('machine_name', $normalizedAddress)->first();
        if ($latest) {
            return max(0, (int) $latest->quantity);
        }
        $latest = ProductionDataHourly::whereBetween('snapshot_at', [$startStr, $endStr])
            ->whereRaw('LOWER(TRIM(machine_name)) = ?', [$lowerAddress])
            ->orderBy('snapshot_at', 'desc')
            ->first();
        if ($latest) {
            return max(0, (int) $latest->quantity);
        }
        $latest = ProductionDataHourly::whereBetween('snapshot_at', [$startStr, $endStr])
            ->whereRaw('LOWER(machine_name) LIKE ?', ['%' . $lowerAddress . '%'])
            ->orderBy('snapshot_at', 'desc')
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