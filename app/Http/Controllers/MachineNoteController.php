<?php

namespace App\Http\Controllers;

use App\Models\MachineNote;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MachineNoteController extends Controller
{
    private const SHIFT_TZ = 'Asia/Jakarta';

    private function authenticate(Request $request): ?object
    {
        $token = $request->bearerToken() ?? $request->header('Authorization');
        if (!$token) return null;

        $token = str_replace('Bearer ', '', $token);
        if (!$token) return null;

        return DB::table('user_sessions')
            ->join('users', 'user_sessions.user_id', '=', 'users.id')
            ->where('user_sessions.token', $token)
            ->where('users.active', true)
            ->where('user_sessions.expires_at', '>', Carbon::now())
            ->select('users.id', 'users.name', 'users.role', 'users.line_name', 'users.division')
            ->first();
    }

    private function computeShiftInfo(Carbon $now): array
    {
        $now = $now->copy()->setTimezone(self::SHIFT_TZ);
        $h = (int) $now->format('H');
        $m = (int) $now->format('i');

        $shift = ($h >= 7 && $h < 20) ? 'pagi' : 'malam';

        if ($shift === 'pagi') {
            $shiftStart = $now->copy()->setTime(7, 0, 0);
        } else {
            // malam: starts 20:00 of shift start date
            if ($h < 7 || ($h === 6 && $m <= 59)) {
                $shiftStart = $now->copy()->subDay()->setTime(20, 0, 0);
            } else {
                $shiftStart = $now->copy()->setTime(20, 0, 0);
            }
        }

        $shiftKey = $shiftStart->format('Y-m-d') . '_' . $shift . '_' . $shiftStart->format('Hi');
        return ['shift' => $shift, 'shift_start_at' => $shiftStart, 'shift_key' => $shiftKey];
    }

    private function computeShiftInfoFromDateShift(string $date, string $shift): array
    {
        $shift = strtolower(trim($shift));
        if (!in_array($shift, ['pagi', 'malam'], true)) {
            throw new \InvalidArgumentException('Invalid shift');
        }

        $d = Carbon::createFromFormat('Y-m-d', $date, self::SHIFT_TZ);
        $shiftStart = $shift === 'pagi'
            ? $d->copy()->setTime(7, 0, 0)
            : $d->copy()->setTime(20, 0, 0);

        $shiftKey = $shiftStart->format('Y-m-d') . '_' . $shift . '_' . $shiftStart->format('Hi');
        return ['shift' => $shift, 'shift_start_at' => $shiftStart, 'shift_key' => $shiftKey];
    }

    /**
     * GET /api/machine-notes/current?machine_name=...&line_name=...
     * - returns note for current shift + basic shift info + history
     */
    public function current(Request $request)
    {
        $user = $this->authenticate($request);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $machineName = trim((string) $request->query('machine_name', ''));
        $lineName = $request->query('line_name');
        $lineName = ($lineName === null) ? null : trim((string) $lineName);

        if ($machineName === '') {
            return response()->json(['success' => false, 'message' => 'machine_name is required'], 422);
        }

        $shiftInfo = $this->computeShiftInfo(Carbon::now(self::SHIFT_TZ));

        $current = MachineNote::query()
            ->where('machine_name', $machineName)
            ->when($lineName !== null && $lineName !== '', fn ($q) => $q->where('line_name', $lineName))
            ->where('shift_key', $shiftInfo['shift_key'])
            ->first();

        $history = MachineNote::query()
            ->where('machine_name', $machineName)
            ->when($lineName !== null && $lineName !== '', fn ($q) => $q->where('line_name', $lineName))
            ->orderByDesc('shift_start_at')
            ->limit(15)
            ->get()
            ->map(function (MachineNote $n) {
                return [
                    'id' => $n->id,
                    'shift' => $n->shift,
                    'shift_key' => $n->shift_key,
                    'shift_start_at' => optional($n->shift_start_at)->toISOString(),
                    'note' => $n->note,
                    'updated_at' => optional($n->updated_at)->toISOString(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'machine_name' => $machineName,
                'line_name' => $lineName,
                'shift' => $shiftInfo['shift'],
                'shift_key' => $shiftInfo['shift_key'],
                'shift_start_at' => $shiftInfo['shift_start_at']->toISOString(),
                'note' => $current ? ($current->note ?? '') : '',
                'updated_at' => $current ? optional($current->updated_at)->toISOString() : null,
                'history' => $history,
            ],
        ]);
    }

    /**
     * PUT /api/machine-notes/current
     * body: { machine_name, line_name?, note }
     * - leader only
     */
    public function upsertCurrent(Request $request)
    {
        $user = $this->authenticate($request);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        if ($user->role !== 'leader') {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $machineName = trim((string) $request->input('machine_name', ''));
        $lineName = $request->input('line_name');
        $lineName = ($lineName === null) ? null : trim((string) $lineName);
        $note = $request->input('note');

        if ($machineName === '') {
            return response()->json(['success' => false, 'message' => 'machine_name is required'], 422);
        }

        // note can be empty string to allow clearing
        $noteText = ($note === null) ? '' : (string) $note;

        $shiftInfo = $this->computeShiftInfo(Carbon::now(self::SHIFT_TZ));

        $record = MachineNote::query()->updateOrCreate(
            [
                'machine_name' => $machineName,
                'line_name' => ($lineName === '') ? null : $lineName,
                'shift_key' => $shiftInfo['shift_key'],
            ],
            [
                'shift' => $shiftInfo['shift'],
                'shift_start_at' => $shiftInfo['shift_start_at'],
                'note' => $noteText,
                'updated_by' => $user->id,
                'created_by' => $user->id,
            ]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $record->id,
                'machine_name' => $record->machine_name,
                'line_name' => $record->line_name,
                'shift' => $record->shift,
                'shift_key' => $record->shift_key,
                'shift_start_at' => optional($record->shift_start_at)->toISOString(),
                'note' => $record->note ?? '',
                'updated_at' => optional($record->updated_at)->toISOString(),
            ],
        ]);
    }

    /**
     * GET /api/machine-notes/by-shift?date=YYYY-MM-DD&shift=pagi|malam&line_name=... (optional)
     * - returns map-friendly list for tooltips
     */
    public function byShift(Request $request)
    {
        $user = $this->authenticate($request);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $date = trim((string) $request->query('date', ''));
        $shift = trim((string) $request->query('shift', ''));
        $lineName = $request->query('line_name');
        $lineName = ($lineName === null) ? null : trim((string) $lineName);

        if ($date === '' || $shift === '') {
            return response()->json(['success' => false, 'message' => 'date and shift are required'], 422);
        }

        try {
            $shiftInfo = $this->computeShiftInfoFromDateShift($date, $shift);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Invalid date/shift'], 422);
        }

        $rows = MachineNote::query()
            ->where('shift_key', $shiftInfo['shift_key'])
            ->when($lineName !== null && $lineName !== '', fn ($q) => $q->where('line_name', $lineName))
            ->whereNotNull('note')
            ->where('note', '!=', '')
            ->get()
            ->map(function (MachineNote $n) {
                return [
                    'machine_name' => $n->machine_name,
                    'line_name' => $n->line_name,
                    'shift_key' => $n->shift_key,
                    'shift' => $n->shift,
                    'shift_start_at' => optional($n->shift_start_at)->toISOString(),
                    'note' => $n->note,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'shift_key' => $shiftInfo['shift_key'],
                'shift' => $shiftInfo['shift'],
                'shift_start_at' => $shiftInfo['shift_start_at']->toISOString(),
                'notes' => $rows,
            ],
        ]);
    }
}

