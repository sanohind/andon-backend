<?php

namespace App\Http\Controllers;

use App\Models\MachineSchedule;
use App\Models\InspectionTable;
use Illuminate\Http\Request;

class MachineScheduleController extends Controller
{
    private function getUserContextFromToken(Request $request): ?array
    {
        $token = $request->bearerToken() ?? $request->header('Authorization');
        if (!$token) return null;
        $token = str_replace('Bearer ', '', $token);
        try {
            $session = \Illuminate\Support\Facades\DB::table('user_sessions')
                ->join('users', 'user_sessions.user_id', '=', 'users.id')
                ->where('user_sessions.token', $token)
                ->where('users.active', 1)
                ->where('user_sessions.expires_at', '>', now(config('app.timezone')))
                ->select('users.role', 'users.line_name', 'users.division')
                ->first();
            if (!$session) return null;
            return [
                'role' => $session->role,
                'line_name' => $session->line_name,
                'division' => $session->division,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * GET list schedule. Optional: search, per_page. Returns with machine name from inspection_tables.
     */
    public function index(Request $request)
    {
        $ctx = $this->getUserContextFromToken($request);
        $role = $ctx['role'] ?? null;
        if (!in_array($role, ['admin', 'management', 'leader'])) {
            return response()->json(['success' => false, 'message' => 'Akses Ditolak'], 403);
        }

        $query = MachineSchedule::query()->orderBy('schedule_date', 'desc')->orderBy('machine_address');

        // Leader hanya boleh melihat schedule untuk mesin di line-nya
        if ($role === 'leader') {
            $lineName = $ctx['line_name'] ?? null;
            if (!$lineName) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 10,
                    'total' => 0,
                ]);
            }
            $allowedAddresses = InspectionTable::where('line_name', $lineName)->pluck('address')->filter()->values()->all();
            if (empty($allowedAddresses)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 10,
                    'total' => 0,
                ]);
            }
            $query->whereIn('machine_address', $allowedAddresses);
        }

        // Optional filter by status (open / closed); if not set, return all
        if ($request->filled('status') && in_array(strtolower($request->status), ['open', 'closed'])) {
            $query->where('status', strtolower($request->status));
        }

        // New explicit filters (preferred by UI)
        if ($request->filled('schedule_date')) {
            $query->whereDate('schedule_date', $request->schedule_date);
        }
        if ($request->filled('shift') && in_array(strtolower($request->shift), ['pagi', 'malam'])) {
            $query->where('shift', strtolower($request->shift));
        }
        if ($request->filled('machine_address')) {
            $query->where('machine_address', $request->machine_address);
        }

        // Backward-compatible search (legacy UI)
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('machine_address', 'like', "%{$s}%")
                    ->orWhere('schedule_date', 'like', "%{$s}%")
                    ->orWhere('shift', 'like', "%{$s}%");
            });
        }

        $perPage = min(max((int) $request->get('per_page', 10), 5), 100);
        $paginated = $query->paginate($perPage);

        $addresses = $paginated->pluck('machine_address')->unique()->values()->all();
        $machines = InspectionTable::whereIn('address', $addresses)->get()->keyBy('address');

        $today = now()->startOfDay();
        $data = $paginated->getCollection()->map(function ($row) use ($machines, $today) {
            $machine = $machines->get($row->machine_address);
            $machineName = $machine ? $machine->name : $row->machine_address;
            $scheduleDate = $row->schedule_date->startOfDay();
            // Persist status: if date has passed and still open, update to closed so data never "disappears"
            $status = $row->status ?? 'open';
            if ($scheduleDate->lt($today) && $status === 'open') {
                $row->update(['status' => 'closed']);
                $status = 'closed';
            }
            return [
                'id' => $row->id,
                'schedule_date' => $row->schedule_date->format('Y-m-d'),
                'machine_address' => $row->machine_address,
                'shift' => $row->shift ?? 'pagi',
                'machine_name' => $machineName,
                'target_quantity' => $row->target_quantity,
                'ot_enabled' => (bool) $row->ot_enabled,
                'ot_duration_type' => $row->ot_duration_type,
                'target_ot' => $row->target_ot,
                'status' => strtoupper($status),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
        ]);
    }

    /**
     * POST create schedule.
     */
    public function store(Request $request)
    {
        if ($this->blockManagementWrite($request)) {
            return $this->blockManagementWrite($request);
        }
        $ctx = $this->getUserContextFromToken($request);
        $role = $ctx['role'] ?? null;
        if (!in_array($role, ['admin', 'leader'])) {
            return response()->json(['success' => false, 'message' => 'Akses Ditolak'], 403);
        }

        $validated = $request->validate([
            'schedule_date' => 'required|date',
            'machine_address' => 'required|string|max:20',
            'shift' => 'nullable|string|in:pagi,malam',
            'target_quantity' => 'required|integer|min:0',
            'ot_enabled' => 'nullable',
            'ot_duration_type' => 'nullable|string|max:50',
            'target_ot' => 'nullable|integer|min:0',
        ]);

        if ($role === 'leader') {
            $lineName = $ctx['line_name'] ?? null;
            $machine = InspectionTable::where('address', $validated['machine_address'])->first();
            if (!$lineName || !$machine || (string) $machine->line_name !== (string) $lineName) {
                return response()->json(['success' => false, 'message' => 'Akses Ditolak'], 403);
            }
        }

        $validated['shift'] = $validated['shift'] ?? 'pagi';
        $validated['ot_enabled'] = filter_var($validated['ot_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$validated['ot_enabled']) {
            $validated['ot_duration_type'] = null;
            $validated['target_ot'] = null;
        }
        if ($validated['ot_enabled']) {
            $bad = $this->validateScheduleOtDurationForShift($validated['shift'], $validated['ot_duration_type'] ?? null);
            if ($bad) {
                return $bad;
            }
        }
        $validated['status'] = $this->scheduleStatusForDate($validated['schedule_date']);

        $schedule = MachineSchedule::create($validated);
        return response()->json(['success' => true, 'data' => $schedule], 201);
    }

    /**
     * POST create schedule for 1 week (7 days) ahead from start_date.
     * Constraints: 1 machine, 1 target quantity, OT always disabled.
     */
    public function storeWeek(Request $request)
    {
        if ($this->blockManagementWrite($request)) {
            return $this->blockManagementWrite($request);
        }
        $ctx = $this->getUserContextFromToken($request);
        $role = $ctx['role'] ?? null;
        if (!in_array($role, ['admin', 'leader'])) {
            return response()->json(['success' => false, 'message' => 'Akses Ditolak'], 403);
        }

        $validated = $request->validate([
            'start_date' => 'required|date',
            'machine_address' => 'required|string|max:20',
            'shift' => 'nullable|string|in:pagi,malam',
            'target_quantity' => 'required|integer|min:0',
        ]);

        if ($role === 'leader') {
            $lineName = $ctx['line_name'] ?? null;
            $machine = InspectionTable::where('address', $validated['machine_address'])->first();
            if (!$lineName || !$machine || (string) $machine->line_name !== (string) $lineName) {
                return response()->json(['success' => false, 'message' => 'Akses Ditolak'], 403);
            }
        }

        $start = \Carbon\Carbon::parse($validated['start_date'])->startOfDay();
        $shift = $validated['shift'] ?? 'pagi';
        $createdOrUpdated = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $start->copy()->addDays($i);
            $payload = [
                'schedule_date' => $date->format('Y-m-d'),
                'machine_address' => $validated['machine_address'],
                'shift' => $shift,
                'target_quantity' => (int) $validated['target_quantity'],
                'ot_enabled' => false,
                'ot_duration_type' => null,
                'target_ot' => null,
                'status' => $this->scheduleStatusForDate($date),
            ];

            $row = MachineSchedule::updateOrCreate(
                [
                    'schedule_date' => $payload['schedule_date'],
                    'machine_address' => $payload['machine_address'],
                    'shift' => $payload['shift'],
                ],
                $payload
            );
            $createdOrUpdated[] = $row;
        }

        return response()->json([
            'success' => true,
            'message' => 'Schedule 1 minggu berhasil dibuat.',
            'data' => collect($createdOrUpdated)->map(function ($row) {
                return [
                    'id' => $row->id,
                    'schedule_date' => $row->schedule_date instanceof \Carbon\Carbon ? $row->schedule_date->format('Y-m-d') : (string) $row->schedule_date,
                    'machine_address' => $row->machine_address,
                    'shift' => $row->shift ?? 'pagi',
                    'target_quantity' => $row->target_quantity,
                    'ot_enabled' => (bool) $row->ot_enabled,
                    'ot_duration_type' => $row->ot_duration_type,
                    'target_ot' => $row->target_ot,
                    'status' => strtoupper($row->status ?? 'open'),
                ];
            })->values(),
        ], 201);
    }

    /**
     * PUT update schedule.
     */
    public function update(Request $request, $id)
    {
        if ($this->blockManagementWrite($request)) {
            return $this->blockManagementWrite($request);
        }
        $ctx = $this->getUserContextFromToken($request);
        $role = $ctx['role'] ?? null;
        if (!in_array($role, ['admin', 'leader'])) {
            return response()->json(['success' => false, 'message' => 'Akses Ditolak'], 403);
        }

        $schedule = MachineSchedule::findOrFail($id);

        if ($role === 'leader') {
            $lineName = $ctx['line_name'] ?? null;
            $machine = InspectionTable::where('address', $schedule->machine_address)->first();
            if (!$lineName || !$machine || (string) $machine->line_name !== (string) $lineName) {
                return response()->json(['success' => false, 'message' => 'Akses Ditolak'], 403);
            }
        }

        $validated = $request->validate([
            'schedule_date' => 'sometimes|date',
            'machine_address' => 'sometimes|string|max:20',
            'shift' => 'nullable|string|in:pagi,malam',
            'target_quantity' => 'sometimes|integer|min:0',
            'ot_enabled' => 'nullable',
            'ot_duration_type' => 'nullable|string|max:50',
            'target_ot' => 'nullable|integer|min:0',
        ]);

        if ($role === 'leader' && isset($validated['machine_address'])) {
            $lineName = $ctx['line_name'] ?? null;
            $machine = InspectionTable::where('address', $validated['machine_address'])->first();
            if (!$lineName || !$machine || (string) $machine->line_name !== (string) $lineName) {
                return response()->json(['success' => false, 'message' => 'Akses Ditolak'], 403);
            }
        }

        if (isset($validated['ot_enabled'])) {
            $validated['ot_enabled'] = filter_var($validated['ot_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($validated['ot_enabled']) && !$validated['ot_enabled']) {
            $validated['ot_duration_type'] = null;
            $validated['target_ot'] = null;
        }
        if (isset($validated['schedule_date'])) {
            $validated['status'] = $this->scheduleStatusForDate($validated['schedule_date']);
        }

        $mergedShift = strtolower((string) ($validated['shift'] ?? $schedule->shift ?? 'pagi'));
        if (!in_array($mergedShift, ['pagi', 'malam'], true)) {
            $mergedShift = 'pagi';
        }
        $mergedOtEnabled = array_key_exists('ot_enabled', $validated)
            ? filter_var($validated['ot_enabled'], FILTER_VALIDATE_BOOLEAN)
            : filter_var($schedule->ot_enabled ?? false, FILTER_VALIDATE_BOOLEAN);
        $mergedOtDuration = array_key_exists('ot_duration_type', $validated)
            ? $validated['ot_duration_type']
            : $schedule->ot_duration_type;
        if (isset($validated['ot_enabled']) && !$validated['ot_enabled']) {
            $mergedOtDuration = null;
        }
        if ($mergedOtEnabled) {
            $bad = $this->validateScheduleOtDurationForShift($mergedShift, $mergedOtDuration);
            if ($bad) {
                return $bad;
            }
        }

        $schedule->update($validated);
        return response()->json(['success' => true, 'data' => $schedule]);
    }

    /**
     * Durasi OT harus selaras dengan shift: pagi -> 2h_pagi | 3.5h_pagi, malam -> 2h_malam.
     */
    private function validateScheduleOtDurationForShift(string $shift, ?string $otDurationType): ?\Illuminate\Http\JsonResponse
    {
        $shift = strtolower($shift) === 'malam' ? 'malam' : 'pagi';
        $allowed = $shift === 'malam' ? ['2h_malam'] : ['2h_pagi', '3.5h_pagi'];
        $dur = $otDurationType !== null ? trim((string) $otDurationType) : '';
        if ($dur === '') {
            return response()->json([
                'success' => false,
                'message' => 'Pilih durasi OT yang sesuai shift (shift pagi: 2 jam / 3,5 jam; shift malam: 2 jam).',
            ], 422);
        }
        if (!in_array($dur, $allowed, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Durasi OT tidak sesuai shift. Shift pagi: 2 jam atau 3,5 jam. Shift malam: 2 jam.',
            ], 422);
        }

        return null;
    }

    /**
     * DELETE schedule.
     */
    public function destroy(Request $request, $id)
    {
        if ($this->blockManagementWrite($request)) {
            return $this->blockManagementWrite($request);
        }
        $ctx = $this->getUserContextFromToken($request);
        $role = $ctx['role'] ?? null;
        if (!in_array($role, ['admin', 'leader'])) {
            return response()->json(['success' => false, 'message' => 'Akses Ditolak'], 403);
        }

        $schedule = MachineSchedule::findOrFail($id);

        if ($role === 'leader') {
            $lineName = $ctx['line_name'] ?? null;
            $machine = InspectionTable::where('address', $schedule->machine_address)->first();
            if (!$lineName || !$machine || (string) $machine->line_name !== (string) $lineName) {
                return response()->json(['success' => false, 'message' => 'Akses Ditolak'], 403);
            }
        }

        $schedule->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Status for schedule: OPEN if date is today or future, CLOSED if past.
     */
    private function scheduleStatusForDate($scheduleDate): string
    {
        $date = $scheduleDate instanceof \Carbon\Carbon
            ? $scheduleDate
            : \Carbon\Carbon::parse($scheduleDate);
        $today = now()->startOfDay();
        return $date->startOfDay()->gte($today) ? 'open' : 'closed';
    }
}
