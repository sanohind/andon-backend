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
            $activeTime = Carbon::parse($problem->timestamp);
            $resolvedTime = Carbon::parse($problem->resolved_at);

            // 1. Durasi dari Active hingga Receive (jika ada)
            if ($problem->is_received && $problem->received_at) {
                $receiveTime = Carbon::parse($problem->received_at);
                $activeToReceive = $receiveTime->diffInSeconds($activeTime);
                $metrics['active_to_receive'][] = [
                    'problem_id' => $problem->id,
                    'machine' => $problem->tipe_mesin,
                    'problem_type' => $problem->tipe_problem,
                    'duration_seconds' => $activeToReceive,
                    'duration_formatted' => $this->formatDuration($activeToReceive)
                ];
            }

            // 2. Durasi dari Receive hingga Feedback (jika ada)
            if ($problem->is_received && $problem->has_feedback_resolved && 
                $problem->received_at && $problem->feedback_resolved_at) {
                $receiveTime = Carbon::parse($problem->received_at);
                $feedbackTime = Carbon::parse($problem->feedback_resolved_at);
                $receiveToFeedback = $feedbackTime->diffInSeconds($receiveTime);
                $metrics['receive_to_feedback'][] = [
                    'problem_id' => $problem->id,
                    'machine' => $problem->tipe_mesin,
                    'problem_type' => $problem->tipe_problem,
                    'duration_seconds' => $receiveToFeedback,
                    'duration_formatted' => $this->formatDuration($receiveToFeedback)
                ];
            }

            // 3. Durasi dari Feedback hingga Final Resolved (jika ada)
            if ($problem->has_feedback_resolved && $problem->feedback_resolved_at) {
                $feedbackTime = Carbon::parse($problem->feedback_resolved_at);
                $feedbackToFinal = $resolvedTime->diffInSeconds($feedbackTime);
                $metrics['feedback_to_final'][] = [
                    'problem_id' => $problem->id,
                    'machine' => $problem->tipe_mesin,
                    'problem_type' => $problem->tipe_problem,
                    'duration_seconds' => $feedbackToFinal,
                    'duration_formatted' => $this->formatDuration($feedbackToFinal)
                ];
            }

            // 4. Total durasi resolusi
            $totalDuration = $resolvedTime->diffInSeconds($activeTime);
            $metrics['total_resolution'][] = [
                'problem_id' => $problem->id,
                'machine' => $problem->tipe_mesin,
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
                $durations = array_column($data, 'duration_seconds');
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
        ]);

        $appTimezone = config('app.timezone');
        $startDate = Carbon::parse($request->start_date, $appTimezone)->startOfDay()->utc();
        $endDate = Carbon::parse($request->end_date, $appTimezone)->endOfDay()->utc();

        // Ambil semua problem yang sudah resolved dalam rentang waktu
        $resolvedProblems = Log::where('status', 'OFF')
            ->whereNotNull('resolved_at')
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('timestamp', [$startDate, $endDate])
                      ->orWhereBetween('resolved_at', [$startDate, $endDate])
                      ->orWhereBetween('forwarded_at', [$startDate, $endDate])
                      ->orWhereBetween('received_at', [$startDate, $endDate])
                      ->orWhereBetween('feedback_resolved_at', [$startDate, $endDate]);
            })
            ->with(['forwardedByUser', 'receivedByUser', 'feedbackResolvedByUser'])
            ->orderBy('resolved_at', 'desc')
            ->get();
            
        // Jika tidak ada data dalam rentang waktu, ambil semua resolved data untuk testing
        if ($resolvedProblems->isEmpty()) {
            $resolvedProblems = Log::where('status', 'OFF')
                ->whereNotNull('resolved_at')
                ->with(['forwardedByUser', 'receivedByUser', 'feedbackResolvedByUser'])
                ->orderBy('resolved_at', 'desc')
                ->get();
        }

        $detailedData = [];

        foreach ($resolvedProblems as $problem) {
            $activeTime = Carbon::parse($problem->timestamp);
            $forwardTime = $problem->forwarded_at ? Carbon::parse($problem->forwarded_at) : null;
            $receiveTime = $problem->received_at ? Carbon::parse($problem->received_at) : null;
            $feedbackTime = $problem->feedback_resolved_at ? Carbon::parse($problem->feedback_resolved_at) : null;
            $finalTime = Carbon::parse($problem->resolved_at);

            // Hitung durasi antar tahapan dalam menit
            $activeToForward = ($activeTime && $forwardTime && $forwardTime->gt($activeTime)) ? round($activeTime->diffInMinutes($forwardTime), 2) : null;
            $forwardToReceive = ($forwardTime && $receiveTime && $receiveTime->gt($forwardTime)) ? round($forwardTime->diffInMinutes($receiveTime), 2) : null;
            $receiveToFeedback = ($receiveTime && $feedbackTime && $feedbackTime->gt($receiveTime)) ? round($receiveTime->diffInMinutes($feedbackTime), 2) : null;
            $feedbackToFinal = ($feedbackTime && $finalTime && $finalTime->gt($feedbackTime)) ? round($feedbackTime->diffInMinutes($finalTime), 2) : null;
            $totalDuration = ($finalTime->gt($activeTime)) ? round($activeTime->diffInMinutes($finalTime), 2) : 0;

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
                'machine' => $problem->tipe_mesin,
                'problem_type' => $problem->tipe_problem,
                'line_number' => $problem->line_number,
                'flow_type' => $flowType,
                'timestamps' => [
                    'active_at' => $activeTime->format('Y-m-d H:i:s'),
                    'forwarded_at' => $forwardTime ? $forwardTime->format('Y-m-d H:i:s') : null,
                    'received_at' => $receiveTime ? $receiveTime->format('Y-m-d H:i:s') : null,
                    'feedback_resolved_at' => $feedbackTime ? $feedbackTime->format('Y-m-d H:i:s') : null,
                    'final_resolved_at' => $finalTime->format('Y-m-d H:i:s'),
                ],
                'durations_minutes' => [
                    'active_to_forward' => $activeToForward,
                    'forward_to_receive' => $forwardToReceive,
                    'receive_to_feedback' => $receiveToFeedback,
                    'feedback_to_final' => $feedbackToFinal,
                    'total_duration' => $totalDuration,
                ],
                'durations_formatted' => [
                    'active_to_forward' => $activeToForward ? $activeToForward . ' menit' : '-',
                    'forward_to_receive' => $forwardToReceive ? $forwardToReceive . ' menit' : '-',
                    'receive_to_feedback' => $receiveToFeedback ? $receiveToFeedback . ' menit' : '-',
                    'feedback_to_final' => $feedbackToFinal ? $feedbackToFinal . ' menit' : '-',
                    'total_duration' => $totalDuration . ' menit',
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
}