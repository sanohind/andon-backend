<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Log;
use App\Models\TicketingProblem;
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
                        'machine' => $problem->tipe_mesin,
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
                        'machine' => $problem->tipe_mesin,
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
                        'machine' => $problem->tipe_mesin,
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
                'machine' => $problem->tipe_mesin,
                'problem_type' => $problem->tipe_problem,
                'line_name' => $problem->line_name,
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
        ]);

        $appTimezone = config('app.timezone');
        $startDate = Carbon::parse($request->start_date, $appTimezone)->startOfDay()->utc();
        $endDate = Carbon::parse($request->end_date, $appTimezone)->endOfDay()->utc();

        $ticketingData = TicketingProblem::with(['problem', 'createdByUser', 'updatedByUser'])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($ticketing) use ($appTimezone) {
                // Hitung ulang nilai-nilai menggunakan helper methods
                $problemReceivedAtStr = $this->calculateProblemReceivedAt($ticketing, $appTimezone);
                $downtimeSeconds = $this->calculateDowntimeForTicketing($ticketing, $appTimezone);
                $mttrSeconds = $this->calculateMTTRForTicketing($ticketing, $appTimezone);
                $mttdSeconds = $this->calculateMTTDForTicketing($ticketing, $appTimezone);
                
                // Format durations
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
                
                // Format problem received at
                $formattedProblemReceivedAt = null;
                if ($problemReceivedAtStr) {
                    try {
                        $formattedProblemReceivedAt = Carbon::parse($problemReceivedAtStr)->format('d/m/Y H:i:s');
                    } catch (\Exception $e) {
                        $formattedProblemReceivedAt = null;
                    }
                }
                
                return [
                    'id' => $ticketing->id,
                    'problem_id' => $ticketing->problem_id,
                    'machine' => $ticketing->problem ? $ticketing->problem->tipe_mesin : 'Unknown',
                    'problem_type' => $ticketing->problem ? $ticketing->problem->tipe_problem : 'Unknown',
                    'line_name' => $ticketing->problem ? $ticketing->problem->line_name : 'Unknown',
                    'pic_technician' => $ticketing->pic_technician,
                    'diagnosis' => $ticketing->diagnosis,
                    'result_repair' => $ticketing->result_repair,
                    'status' => $ticketing->status,
                    'status_label' => $ticketing->status_label,
                    'status_badge_class' => $ticketing->status_badge_class,
                    'timestamps' => [
                        'problem_received_at' => $formattedProblemReceivedAt,
                        'diagnosis_started_at' => $ticketing->formatted_diagnosis_started_at,
                        'repair_started_at' => $ticketing->formatted_repair_started_at,
                        'repair_completed_at' => $ticketing->formatted_repair_completed_at,
                        'created_at' => $ticketing->created_at->format('d/m/Y H:i:s'),
                        'updated_at' => $ticketing->updated_at->format('d/m/Y H:i:s'),
                    ],
                    'durations' => [
                        'downtime' => $formatDuration($downtimeSeconds ?? $ticketing->downtime_seconds),
                        'mttr' => $formatDuration($mttrSeconds ?? $ticketing->mttr_seconds),
                        'mttd' => $formatDuration($mttdSeconds ?? $ticketing->mttd_seconds),
                        'mtbf' => $ticketing->formatted_mtbf,
                    ],
                    'durations_seconds' => [
                        'downtime_seconds' => $downtimeSeconds ?? $ticketing->downtime_seconds,
                        'mttr_seconds' => $mttrSeconds ?? $ticketing->mttr_seconds,
                        'mttd_seconds' => $mttdSeconds ?? $ticketing->mttd_seconds,
                        'mtbf_seconds' => $ticketing->mtbf_seconds,
                    ],
                    'users' => [
                        'created_by' => $ticketing->createdByUser ? $ticketing->createdByUser->name : 'Unknown',
                        'updated_by' => $ticketing->updatedByUser ? $ticketing->updatedByUser->name : null,
                    ],
                    'metadata' => $ticketing->metadata
                ];
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
     * Debug endpoint untuk melihat data mentah ticketing
     */
    public function getTicketingAnalyticsDataDebug(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d',
        ]);

        $appTimezone = config('app.timezone');
        $startDate = Carbon::parse($request->start_date, $appTimezone)->startOfDay()->utc();
        $endDate = Carbon::parse($request->end_date, $appTimezone)->endOfDay()->utc();

        $ticketingData = TicketingProblem::with(['problem', 'createdByUser', 'updatedByUser'])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($ticketing) use ($appTimezone) {
                return [
                    'id' => $ticketing->id,
                    'problem_id' => $ticketing->problem_id,
                    'raw_data' => [
                        'problem_received_at' => $ticketing->problem_received_at ? $ticketing->problem_received_at->format('Y-m-d H:i:s') : null,
                        'diagnosis_started_at' => $ticketing->diagnosis_started_at ? $ticketing->diagnosis_started_at->format('Y-m-d H:i:s') : null,
                        'repair_started_at' => $ticketing->repair_started_at ? $ticketing->repair_started_at->format('Y-m-d H:i:s') : null,
                        'repair_completed_at' => $ticketing->repair_completed_at ? $ticketing->repair_completed_at->format('Y-m-d H:i:s') : null,
                        'downtime_seconds' => $ticketing->downtime_seconds,
                        'mttr_seconds' => $ticketing->mttr_seconds,
                        'mttd_seconds' => $ticketing->mttd_seconds,
                    ],
                    'problem_data' => $ticketing->problem ? [
                        'received_at' => $ticketing->problem->received_at ? $ticketing->problem->received_at->format('Y-m-d H:i:s') : null,
                        'timestamp' => $ticketing->problem->timestamp ? $ticketing->problem->timestamp->format('Y-m-d H:i:s') : null,
                    ] : null,
                    'metadata' => $ticketing->metadata,
                    'calculated' => [
                        'problem_received_at' => $this->calculateProblemReceivedAt($ticketing, $appTimezone),
                        'downtime_seconds' => $this->calculateDowntimeForTicketing($ticketing, $appTimezone),
                        'mttr_seconds' => $this->calculateMTTRForTicketing($ticketing, $appTimezone),
                        'mttd_seconds' => $this->calculateMTTDForTicketing($ticketing, $appTimezone),
                    ]
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'ticketing' => $ticketingData,
                'count' => $ticketingData->count()
            ]
        ]);
    }

    private function calculateProblemReceivedAt($ticketing, $appTimezone)
    {
        if ($ticketing->problem_received_at) {
            return $ticketing->problem_received_at->format('Y-m-d H:i:s');
        }
        if ($ticketing->problem && $ticketing->problem->received_at) {
            return $ticketing->problem->received_at->format('Y-m-d H:i:s');
        }
        if ($ticketing->metadata && isset($ticketing->metadata['problem_received_at'])) {
            return $ticketing->metadata['problem_received_at'];
        }
        return null;
    }

    private function calculateDowntimeForTicketing($ticketing, $appTimezone)
    {
        $repairStarted = $ticketing->repair_started_at ? Carbon::parse($ticketing->repair_started_at)->setTimezone($appTimezone) : null;
        $repairCompleted = $ticketing->repair_completed_at ? Carbon::parse($ticketing->repair_completed_at)->setTimezone($appTimezone) : null;
        
        if ($repairStarted && $repairCompleted && $repairCompleted->gt($repairStarted)) {
            return abs($repairStarted->diffInSeconds($repairCompleted));
        }
        
        if ($ticketing->problem && $ticketing->problem->timestamp && $repairCompleted) {
            $problemTimestamp = Carbon::parse($ticketing->problem->timestamp)->setTimezone($appTimezone);
            if ($repairCompleted->gt($problemTimestamp)) {
                return abs($problemTimestamp->diffInSeconds($repairCompleted));
            }
        }
        
        return null;
    }

    private function calculateMTTRForTicketing($ticketing, $appTimezone)
    {
        $repairStarted = $ticketing->repair_started_at ? Carbon::parse($ticketing->repair_started_at)->setTimezone($appTimezone) : null;
        $repairCompleted = $ticketing->repair_completed_at ? Carbon::parse($ticketing->repair_completed_at)->setTimezone($appTimezone) : null;
        
        if ($repairStarted && $repairCompleted && $repairCompleted->gt($repairStarted)) {
            return abs($repairStarted->diffInSeconds($repairCompleted));
        }
        
        return null;
    }

    private function calculateMTTDForTicketing($ticketing, $appTimezone)
    {
        $problemReceivedAt = $this->calculateProblemReceivedAt($ticketing, $appTimezone);
        if (!$problemReceivedAt) {
            return null;
        }
        
        $received = Carbon::parse($problemReceivedAt)->setTimezone($appTimezone);
        $diagnosisStarted = $ticketing->diagnosis_started_at ? Carbon::parse($ticketing->diagnosis_started_at)->setTimezone($appTimezone) : null;
        
        if ($diagnosisStarted && $diagnosisStarted->gt($received)) {
            return abs($received->diffInSeconds($diagnosisStarted));
        }
        
        return null;
    }
}