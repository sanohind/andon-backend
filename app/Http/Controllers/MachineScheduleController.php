<?php

namespace App\Http\Controllers;

use App\Models\MachineSchedule;
use App\Models\InspectionTable;
use Illuminate\Http\Request;

class MachineScheduleController extends Controller
{
    /**
     * GET list schedule. Optional: search, per_page. Returns with machine name from inspection_tables.
     */
    public function index(Request $request)
    {
        $role = $this->getUserRoleFromToken($request);
        if (!in_array($role, ['admin', 'management'])) {
            return response()->json(['success' => false, 'message' => 'Akses Ditolak'], 403);
        }

        $query = MachineSchedule::query()->orderBy('schedule_date', 'desc')->orderBy('machine_address');

        // Optional filter by status (open / closed); if not set, return all
        if ($request->filled('status') && in_array(strtolower($request->status), ['open', 'closed'])) {
            $query->where('status', strtolower($request->status));
        }

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
        $role = $this->getUserRoleFromToken($request);
        if ($role !== 'admin') {
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

        $validated['shift'] = $validated['shift'] ?? 'pagi';
        $validated['ot_enabled'] = filter_var($validated['ot_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$validated['ot_enabled']) {
            $validated['ot_duration_type'] = null;
            $validated['target_ot'] = null;
        }
        $validated['status'] = $this->scheduleStatusForDate($validated['schedule_date']);

        $schedule = MachineSchedule::create($validated);
        return response()->json(['success' => true, 'data' => $schedule], 201);
    }

    /**
     * PUT update schedule.
     */
    public function update(Request $request, $id)
    {
        if ($this->blockManagementWrite($request)) {
            return $this->blockManagementWrite($request);
        }
        $role = $this->getUserRoleFromToken($request);
        if ($role !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Akses Ditolak'], 403);
        }

        $schedule = MachineSchedule::findOrFail($id);
        $validated = $request->validate([
            'schedule_date' => 'sometimes|date',
            'machine_address' => 'sometimes|string|max:20',
            'shift' => 'nullable|string|in:pagi,malam',
            'target_quantity' => 'sometimes|integer|min:0',
            'ot_enabled' => 'nullable',
            'ot_duration_type' => 'nullable|string|max:50',
            'target_ot' => 'nullable|integer|min:0',
        ]);

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

        $schedule->update($validated);
        return response()->json(['success' => true, 'data' => $schedule]);
    }

    /**
     * DELETE schedule.
     */
    public function destroy(Request $request, $id)
    {
        if ($this->blockManagementWrite($request)) {
            return $this->blockManagementWrite($request);
        }
        $role = $this->getUserRoleFromToken($request);
        if ($role !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Akses Ditolak'], 403);
        }

        $schedule = MachineSchedule::findOrFail($id);
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
