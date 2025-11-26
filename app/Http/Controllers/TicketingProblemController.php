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
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function($ticketing) {
                return $this->formatTicketingResponse($ticketing);
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
            // result_repair tidak ada di form create, akan diisi saat mark as resolved
            'problem_received_at' => 'nullable|date', // Diterima dari form (read-only field)
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
        // Ambil problem_received_at dari request (sudah diisi di form)
        // Jika tidak ada di request, ambil dari problem->received_at sebagai fallback
        $problemReceivedAt = null;
        if ($request->has('problem_received_at') && $request->problem_received_at) {
            $problemReceivedAt = Carbon::parse($request->problem_received_at, config('app.timezone'));
        } elseif ($problem->is_received && $problem->received_at) {
            $problemReceivedAt = Carbon::parse($problem->received_at, config('app.timezone'));
        }
        
        // Jika masih null, gunakan waktu sekarang sebagai fallback terakhir
        if (!$problemReceivedAt) {
            $problemReceivedAt = Carbon::now(config('app.timezone'));
        }
        
        // Buat ticketing dengan result_repair = null
        try {
            $ticketing = TicketingProblem::create([
                'problem_id' => $request->problem_id,
                'pic_technician' => $request->pic_technician,
                'diagnosis' => $request->diagnosis,
                'result_repair' => null, // Akan diisi saat mark as resolved
                'problem_received_at' => $problemReceivedAt, // Pastikan selalu ada nilai
                'diagnosis_started_at' => $request->diagnosis_started_at ? Carbon::parse($request->diagnosis_started_at, config('app.timezone')) : null,
                'repair_started_at' => $request->repair_started_at ? Carbon::parse($request->repair_started_at, config('app.timezone')) : null,
                // repair_completed_at akan diisi otomatis saat event Feedback
                'status' => 'open', // Status tetap OPEN sampai mark as resolved
                'created_by_user_id' => $session->id,
                'updated_by_user_id' => $session->id,
                'metadata' => [
                    'created_via' => 'api',
                    'problem_forwarded_at' => $problem->forwarded_at,
                    'problem_received_at' => $problem->received_at
                ]
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Jika error karena NOT NULL constraint pada result_repair
            if (strpos($e->getMessage(), 'null value in column "result_repair"') !== false || 
                strpos($e->getMessage(), 'NOT NULL') !== false) {
                
                // Coba ubah kolom menjadi nullable
                try {
                    DB::statement('ALTER TABLE ticketing_problems ALTER COLUMN result_repair DROP NOT NULL');
                    \Log::info('Successfully made result_repair column nullable');
                    
                    // Coba create lagi setelah kolom diubah
                    $ticketing = TicketingProblem::create([
                        'problem_id' => $request->problem_id,
                        'pic_technician' => $request->pic_technician,
                        'diagnosis' => $request->diagnosis,
                        'result_repair' => null,
                        'problem_received_at' => $problemReceivedAt,
                        'diagnosis_started_at' => $request->diagnosis_started_at ? Carbon::parse($request->diagnosis_started_at, config('app.timezone')) : null,
                        'repair_started_at' => $request->repair_started_at ? Carbon::parse($request->repair_started_at, config('app.timezone')) : null,
                        'status' => 'open',
                        'created_by_user_id' => $session->id,
                        'updated_by_user_id' => $session->id,
                        'metadata' => [
                            'created_via' => 'api',
                            'problem_forwarded_at' => $problem->forwarded_at,
                            'problem_received_at' => $problem->received_at
                        ]
                    ]);
                } catch (\Exception $alterError) {
                    \Log::error('Failed to alter result_repair column: ' . $alterError->getMessage());
                    return response()->json([
                        'success' => false,
                        'message' => 'Database error: Kolom result_repair masih NOT NULL. Silakan jalankan migration: php artisan migrate'
                    ], 500);
                }
            } else {
                // Re-throw jika bukan error NOT NULL
                throw $e;
            }
        }

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

        // Validasi input - diagnosis required, result_repair optional
        $request->validate([
            'diagnosis' => 'required|string',
            'result_repair' => 'nullable|string'
        ]);

        // Update ticketing - diagnosis dan result_repair
        $updateData = [
            'diagnosis' => $request->diagnosis,
            'updated_by_user_id' => $session->id
        ];
        
        // Update result_repair jika disediakan
        if ($request->has('result_repair')) {
            $updateData['result_repair'] = $request->result_repair;
        }

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
        try {
            $ticketing = TicketingProblem::with(['problem', 'createdByUser', 'updatedByUser'])
                ->where('problem_id', $problemId)
                ->first();

            if (!$ticketing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticketing tidak ditemukan untuk problem ini'
                ], 404);
            }

            // Refresh ticketing untuk memastikan data terbaru
            $ticketing->refresh();
            
            return response()->json([
                'success' => true,
                'data' => $this->formatTicketingResponse($ticketing)
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in getTicketingByProblem', [
                'problem_id' => $problemId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching ticketing: ' . $e->getMessage()
            ], 500);
        }
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

    /**
     * Get ticketing by ID
     */
    public function getTicketingById($id)
    {
        try {
            $ticketing = TicketingProblem::with(['problem', 'createdByUser', 'updatedByUser'])->find($id);

            if (!$ticketing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticketing tidak ditemukan'
                ], 404);
            }

            $ticketing->refresh();

            return response()->json([
                'success' => true,
                'data' => $this->formatTicketingResponse($ticketing)
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in getTicketingById', [
                'ticketing_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching ticketing: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper untuk format respon ticketing
     */
    private function formatTicketingResponse(TicketingProblem $ticketing): array
    {
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
                'created_at' => $ticketing->created_at ? $ticketing->created_at->format('d/m/Y H:i:s') : null,
                'updated_at' => $ticketing->updated_at ? $ticketing->updated_at->format('d/m/Y H:i:s') : null,
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
    }
}
