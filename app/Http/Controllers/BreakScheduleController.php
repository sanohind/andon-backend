<?php

namespace App\Http\Controllers;

use App\Models\BreakSchedule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BreakScheduleController extends Controller
{
    /**
     * GET semua jadwal jam kerja & istirahat (untuk dashboard production & halaman manage tables).
     */
    public function index(Request $request)
    {
        try {
            $rows = BreakSchedule::orderBy('day_of_week')->orderBy('shift')->get();
            $data = $rows->map(function ($row) {
                return [
                    'id' => $row->id,
                    'day_of_week' => (int) $row->day_of_week,
                    'shift' => $row->shift,
                    'work_start' => $row->work_start ? substr((string) $row->work_start, 0, 5) : null,
                    'work_end' => $row->work_end ? substr((string) $row->work_end, 0, 5) : null,
                    'break_1_start' => $row->break_1_start ? substr((string) $row->break_1_start, 0, 5) : null,
                    'break_1_end' => $row->break_1_end ? substr((string) $row->break_1_end, 0, 5) : null,
                    'break_2_start' => $row->break_2_start ? substr((string) $row->break_2_start, 0, 5) : null,
                    'break_2_end' => $row->break_2_end ? substr((string) $row->break_2_end, 0, 5) : null,
                    'break_3_start' => $row->break_3_start ? substr((string) $row->break_3_start, 0, 5) : null,
                    'break_3_end' => $row->break_3_end ? substr((string) $row->break_3_end, 0, 5) : null,
                    'break_4_start' => $row->break_4_start ? substr((string) $row->break_4_start, 0, 5) : null,
                    'break_4_end' => $row->break_4_end ? substr((string) $row->break_4_end, 0, 5) : null,
                    'breaks' => $row->getBreaksArray(),
                ];
            });
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil jadwal istirahat: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT update semua jadwal. Hanya admin.
     */
    public function update(Request $request)
    {
        $userRole = $this->getUserRoleFromToken($request);
        if ($userRole !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya admin yang dapat mengatur jam istirahat.',
            ], 403);
        }

        $validated = $request->validate([
            'schedules' => 'required|array',
            'schedules.*.day_of_week' => 'required|integer|min:1|max:7',
            'schedules.*.shift' => ['required', Rule::in(['pagi', 'malam'])],
            'schedules.*.work_start' => 'nullable|string|regex:/^\d{1,2}:\d{2}$/',
            'schedules.*.work_end' => 'nullable|string|regex:/^\d{1,2}:\d{2}$/',
            'schedules.*.break_1_start' => 'nullable|string|regex:/^\d{1,2}:\d{2}$/',
            'schedules.*.break_1_end' => 'nullable|string|regex:/^\d{1,2}:\d{2}$/',
            'schedules.*.break_2_start' => 'nullable|string|regex:/^\d{1,2}:\d{2}$/',
            'schedules.*.break_2_end' => 'nullable|string|regex:/^\d{1,2}:\d{2}$/',
            'schedules.*.break_3_start' => 'nullable|string|regex:/^\d{1,2}:\d{2}$/',
            'schedules.*.break_3_end' => 'nullable|string|regex:/^\d{1,2}:\d{2}$/',
            'schedules.*.break_4_start' => 'nullable|string|regex:/^\d{1,2}:\d{2}$/',
            'schedules.*.break_4_end' => 'nullable|string|regex:/^\d{1,2}:\d{2}$/',
        ]);

        try {
            foreach ($validated['schedules'] as $item) {
                $attrs = [
                    'work_start' => $item['work_start'] ?? null,
                    'work_end' => $item['work_end'] ?? null,
                    'break_1_start' => $item['break_1_start'] ?? null,
                    'break_1_end' => $item['break_1_end'] ?? null,
                    'break_2_start' => $item['break_2_start'] ?? null,
                    'break_2_end' => $item['break_2_end'] ?? null,
                    'break_3_start' => $item['break_3_start'] ?? null,
                    'break_3_end' => $item['break_3_end'] ?? null,
                    'break_4_start' => $item['break_4_start'] ?? null,
                    'break_4_end' => $item['break_4_end'] ?? null,
                ];
                foreach (['work_start', 'work_end', 'break_1_start', 'break_1_end', 'break_2_start', 'break_2_end', 'break_3_start', 'break_3_end', 'break_4_start', 'break_4_end'] as $k) {
                    if (isset($attrs[$k]) && $attrs[$k] !== null && strlen($attrs[$k]) === 5) {
                        $attrs[$k] = $attrs[$k] . ':00';
                    }
                }
                BreakSchedule::updateOrCreate(
                    [
                        'day_of_week' => $item['day_of_week'],
                        'shift' => $item['shift'],
                    ],
                    $attrs
                );
            }
            $rows = BreakSchedule::orderBy('day_of_week')->orderBy('shift')->get();
            $data = $rows->map(function ($row) {
                return [
                    'id' => $row->id,
                    'day_of_week' => (int) $row->day_of_week,
                    'shift' => $row->shift,
                    'work_start' => $row->work_start ? substr((string) $row->work_start, 0, 5) : null,
                    'work_end' => $row->work_end ? substr((string) $row->work_end, 0, 5) : null,
                    'break_1_start' => $row->break_1_start ? substr((string) $row->break_1_start, 0, 5) : null,
                    'break_1_end' => $row->break_1_end ? substr((string) $row->break_1_end, 0, 5) : null,
                    'break_2_start' => $row->break_2_start ? substr((string) $row->break_2_start, 0, 5) : null,
                    'break_2_end' => $row->break_2_end ? substr((string) $row->break_2_end, 0, 5) : null,
                    'break_3_start' => $row->break_3_start ? substr((string) $row->break_3_start, 0, 5) : null,
                    'break_3_end' => $row->break_3_end ? substr((string) $row->break_3_end, 0, 5) : null,
                    'break_4_start' => $row->break_4_start ? substr((string) $row->break_4_start, 0, 5) : null,
                    'break_4_end' => $row->break_4_end ? substr((string) $row->break_4_end, 0, 5) : null,
                    'breaks' => $row->getBreaksArray(),
                ];
            });
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan jadwal istirahat: ' . $e->getMessage(),
            ], 500);
        }
    }
}
