<?php

namespace App\Http\Controllers;

use App\Models\TicketingProblem;
use App\Models\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TicketingProblemController extends Controller
{
    /**
     * Get ticketing data untuk analytics
     */
    public function getTicketingData(Request $request)
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
            ->map(function($ticketing) {
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
                        'problem_received_at' => $ticketing->formatted_problem_received_at,
                        'diagnosis_started_at' => $ticketing->formatted_diagnosis_started_at,
                        'repair_started_at' => $ticketing->formatted_repair_started_at,
                        'repair_completed_at' => $ticketing->formatted_repair_completed_at,
                        'created_at' => $ticketing->created_at->format('d/m/Y H:i:s'),
                        'updated_at' => $ticketing->updated_at->format('d/m/Y H:i:s'),
                    ],
                    'durations' => [
                        'downtime' => $ticketing->formatted_downtime,
                        'mttr' => $ticketing->formatted_mttr,
                        'mttd' => $ticketing->formatted_mttd,
                        'mtbf' => $ticketing->formatted_mtbf,
                    ],
                    'durations_seconds' => [
                        'downtime_seconds' => $ticketing->downtime_seconds,
                        'mttr_seconds' => $ticketing->mttr_seconds,
                        'mttd_seconds' => $ticketing->mttd_seconds,
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
     * Create new ticketing
     */
    public function createTicketing(Request $request)
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

            // Validasi bahwa yang membuat ticketing adalah user maintenance, quality, atau engineering
            if (!in_array($session->role, ['maintenance', 'quality', 'engineering'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya user maintenance, quality, atau engineering yang dapat membuat ticketing problem.'
                ], 403);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error during authentication'
            ], 500);
        }

        // Validasi input
        $request->validate([
            'problem_id' => 'required|exists:log,id',
            'pic_technician' => 'required|string|max:100',
            'diagnosis' => 'required|string',
            'result_repair' => 'required|string',
            // problem_received_at ditetapkan otomatis saat Receive
            'diagnosis_started_at' => 'nullable|date',
            'repair_started_at' => 'nullable|date',
            // repair_completed_at ditetapkan otomatis saat Feedback
        ]);

        // Cek apakah problem sudah ada ticketing
        $existingTicketing = TicketingProblem::where('problem_id', $request->problem_id)->first();
        if ($existingTicketing) {
            return response()->json([
                'success' => false,
                'message' => 'Ticketing untuk problem ini sudah ada.'
            ], 400);
        }

        // Cek apakah problem adalah tipe machine/quality/engineering dan sudah di-forward
        $problem = Log::find($request->problem_id);
        if (!$problem || !in_array($problem->tipe_problem, ['Machine', 'Quality', 'Engineering', 'Material']) || !$problem->is_forwarded) {
            return response()->json([
                'success' => false,
                'message' => 'Ticketing hanya bisa dibuat untuk problem machine/quality/engineering yang sudah di-forward.'
            ], 400);
        }

        // Buat ticketing baru
        $ticketing = TicketingProblem::create([
            'problem_id' => $request->problem_id,
            'pic_technician' => $request->pic_technician,
            'diagnosis' => $request->diagnosis,
            'result_repair' => $request->result_repair,
            // problem_received_at akan diisi otomatis saat event Receive
            'diagnosis_started_at' => $request->diagnosis_started_at ? Carbon::parse($request->diagnosis_started_at, config('app.timezone')) : null,
            'repair_started_at' => $request->repair_started_at ? Carbon::parse($request->repair_started_at, config('app.timezone')) : null,
            // repair_completed_at akan diisi otomatis saat event Feedback
            'status' => 'open',
            'created_by_user_id' => $session->id,
            'updated_by_user_id' => $session->id,
            'metadata' => [
                'created_via' => 'api',
                'problem_forwarded_at' => $problem->forwarded_at,
                'problem_received_at' => $problem->received_at
            ]
        ]);

        // Update calculated times
        $ticketing->updateCalculatedTimes();

        return response()->json([
            'success' => true,
            'message' => 'Ticketing berhasil dibuat',
            'data' => [
                'ticketing_id' => $ticketing->id,
                'problem_id' => $ticketing->problem_id,
                'created_by' => $session->name,
                'created_at' => $ticketing->created_at->format('d/m/Y H:i:s')
            ]
        ]);
    }

    /**
     * Update ticketing
     */
    public function updateTicketing(Request $request, $id)
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

        // Cari ticketing
        $ticketing = TicketingProblem::find($id);
        if (!$ticketing) {
            return response()->json([
                'success' => false,
                'message' => 'Ticketing tidak ditemukan'
            ], 404);
        }

        // Validasi bahwa user bisa update ticketing ini
        if (!$ticketing->canBeUpdatedBy((object)$session)) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengupdate ticketing ini.'
            ], 403);
        }

        // Validasi input
        $request->validate([
            'pic_technician' => 'sometimes|string|max:100',
            'diagnosis' => 'sometimes|string',
            'result_repair' => 'sometimes|string',
            'problem_received_at' => 'nullable|date',
            'diagnosis_started_at' => 'nullable|date',
            'repair_started_at' => 'nullable|date',
            'repair_completed_at' => 'nullable|date',
            'status' => 'sometimes|in:open,in_progress,completed,cancelled'
        ]);

        // Update ticketing
        $updateData = $request->only([
            'pic_technician', 'diagnosis', 'result_repair', 'status'
        ]);

        // Update timestamps jika ada
        if ($request->has('problem_received_at')) {
            $updateData['problem_received_at'] = $request->problem_received_at ? 
                Carbon::parse($request->problem_received_at, config('app.timezone')) : null;
        }
        if ($request->has('diagnosis_started_at')) {
            $updateData['diagnosis_started_at'] = $request->diagnosis_started_at ? 
                Carbon::parse($request->diagnosis_started_at, config('app.timezone')) : null;
        }
        if ($request->has('repair_started_at')) {
            $updateData['repair_started_at'] = $request->repair_started_at ? 
                Carbon::parse($request->repair_started_at, config('app.timezone')) : null;
        }
        if ($request->has('repair_completed_at')) {
            $updateData['repair_completed_at'] = $request->repair_completed_at ? 
                Carbon::parse($request->repair_completed_at, config('app.timezone')) : null;
        }

        $updateData['updated_by_user_id'] = $session->id;

        $ticketing->update($updateData);

        // Update calculated times
        $ticketing->updateCalculatedTimes();

        return response()->json([
            'success' => true,
            'message' => 'Ticketing berhasil diupdate',
            'data' => [
                'ticketing_id' => $ticketing->id,
                'updated_by' => $session->name,
                'updated_at' => $ticketing->updated_at->format('d/m/Y H:i:s')
            ]
        ]);
    }

    /**
     * Get ticketing by problem ID
     */
    public function getTicketingByProblem($problemId)
    {
        $ticketing = TicketingProblem::with(['problem', 'createdByUser', 'updatedByUser'])
            ->where('problem_id', $problemId)
            ->first();

        if (!$ticketing) {
            return response()->json([
                'success' => false,
                'message' => 'Ticketing tidak ditemukan untuk problem ini'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
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
                    'problem_received_at' => $ticketing->formatted_problem_received_at,
                    'diagnosis_started_at' => $ticketing->formatted_diagnosis_started_at,
                    'repair_started_at' => $ticketing->formatted_repair_started_at,
                    'repair_completed_at' => $ticketing->formatted_repair_completed_at,
                    'created_at' => $ticketing->created_at->format('d/m/Y H:i:s'),
                    'updated_at' => $ticketing->updated_at->format('d/m/Y H:i:s'),
                ],
                'durations' => [
                    'downtime' => $ticketing->formatted_downtime,
                    'mttr' => $ticketing->formatted_mttr,
                    'mttd' => $ticketing->formatted_mttd,
                    'mtbf' => $ticketing->formatted_mtbf,
                ],
                'users' => [
                    'created_by' => $ticketing->createdByUser ? $ticketing->createdByUser->name : 'Unknown',
                    'updated_by' => $ticketing->updatedByUser ? $ticketing->updatedByUser->name : null,
                ],
                'metadata' => $ticketing->metadata
            ]
        ]);
    }

    /**
     * Get available technicians for dropdown
     */
    public function getTechnicians()
    {
        $technicians = [
            'Teknisi A',
            'Teknisi B', 
            'Teknisi C',
            'Teknisi D',
            'Teknisi E',
            'Senior Teknisi A',
            'Senior Teknisi B',
            'Lead Teknisi'
        ];

        return response()->json([
            'success' => true,
            'data' => $technicians
        ]);
    }
}
