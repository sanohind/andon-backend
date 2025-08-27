<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Log;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    /**
     * Menyediakan data analitik berdasarkan rentang waktu.
     */
    public function getAnalyticsData(Request $request)
    {
        // Validasi input tanggal
        $request->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d',
        ]);

        $appTimezone = config('app.timezone');
        $startDate = Carbon::parse($request->start_date, $appTimezone)->startOfDay()->utc();
        $endDate = Carbon::parse($request->end_date, $appTimezone)->endOfDay()->utc();

        // ===================================================================
        // LOGIKA QUERY TERPADU YANG BARU
        // ===================================================================
        // Ambil semua log yang relevan: yang DIMULAI atau DISELESAIKAN dalam rentang waktu.
        $allRelevantLogs = Log::where(function ($query) use ($startDate, $endDate) {
            $query->whereBetween('timestamp', [$startDate, $endDate])
                  ->orWhereBetween('resolved_at', [$startDate, $endDate]);
        })->get();
        
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
            'most_problematic_machine' => $mostProblematicMachine ?? 'N/A',
            'average_resolution_time_seconds' => $resolvedLogs->avg('duration_in_seconds'),
        ];
    }

    private function calculateProblemFrequency($allLogs)
    {
        $data = $allLogs->groupBy('tipe_mesin')->map(fn($group) => $group->count());
        return [
            'labels' => $data->keys(),
            'data' => $data->values(),
        ];
    }

    private function calculateDowntime($resolvedLogs)
    {
        $data = $resolvedLogs->groupBy('tipe_mesin')->map(fn($group) => $group->sum('duration_in_seconds'));
        return [
            'labels' => $data->keys(),
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

    private function calculateMTTR($resolvedLogs)
    {
        $data = $resolvedLogs->groupBy('tipe_problem')->map(fn($group) => $group->avg('duration_in_seconds'));
        return [
            'labels' => $data->keys(),
            'data' => $data->values(),
        ];
    }
}