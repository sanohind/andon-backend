<?php

namespace App\Services;

use App\Models\BreakSchedule;
use App\Models\MachineShiftRuntime;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RealtimeOeeService
{
    /**
     * Shift pagi: 07:00-19:59, shift malam: 20:00-06:59 (next day).
     * Returns [ 'shift' => 'pagi'|'malam', 'shiftStart' => Carbon, 'shiftKey' => string ].
     */
    public function getShiftInfo(Carbon $now): array
    {
        $h = (int) $now->format('G');
        $m = (int) $now->format('i');
        $shift = 'malam';
        if ($h >= 7 && $h < 20) {
            $shift = 'pagi';
        } elseif ($h === 6 && $m <= 59) {
            $shift = 'malam';
        } elseif ($h < 7) {
            $shift = 'malam';
        } elseif ($h >= 20) {
            $shift = 'malam';
        }

        $shiftStart = $now->copy();
        if ($shift === 'pagi') {
            $shiftStart->setTime(7, 0, 0);
        } else {
            if ($h < 7) {
                $shiftStart->subDay()->setTime(20, 0, 0);
            } else {
                $shiftStart->setTime(20, 0, 0);
            }
        }

        $shiftKey = $shiftStart->format('Y-m-d') . '_' . $shift . '_' . $shiftStart->format('His');
        return [
            'shift' => $shift,
            'shiftStart' => $shiftStart,
            'shiftKey' => $shiftKey,
        ];
    }

    /**
     * Apakah tipe problem termasuk downtime (machine/quality/engineering).
     */
    public function isDowntimeProblemType(?string $problemType): bool
    {
        $t = strtolower(trim((string) ($problemType ?? '')));
        return $t === 'machine' || $t === 'quality' || $t === 'engineering'
            || $t === 'tipe machine' || $t === 'tipe mesin'
            || str_contains($t, 'machine') || str_contains($t, 'quality') || str_contains($t, 'engineering');
    }

    /**
     * Hitung Running Hour (detik) sampai now, dengan break schedule.
     */
    public function computeRunningHourSeconds(Carbon $now, Carbon $shiftStart, string $shift): int
    {
        if ($now->lt($shiftStart)) {
            return 0;
        }
        $elapsed = max(0, $shiftStart->diffInSeconds($now));
        $maxSeconds = 9 * 3600;

        $dayOfWeek = $shiftStart->isoWeekday();
        $schedule = BreakSchedule::where('day_of_week', $dayOfWeek)
            ->where('shift', $shift)
            ->first();

        if (!$schedule || !$schedule->work_start || !$schedule->work_end) {
            return (int) min($elapsed, $maxSeconds);
        }

        $base = $shiftStart->copy()->startOfDay();
        $ws = $this->parseTimeStringToHm($schedule->work_start);
        $we = $this->parseTimeStringToHm($schedule->work_end);
        if (!$ws || !$we) {
            return (int) min($elapsed, $maxSeconds);
        }

        [$wsH, $wsM] = $ws;
        [$weH, $weM] = $we;
        $workStartM = $base->copy()->setTime($wsH, $wsM, 0, 0);
        $workEndM = $base->copy()->setTime($weH, $weM, 0, 0);
        if ($weH < $wsH || ($weH === $wsH && $weM < $wsM)) {
            $workEndM->addDay();
        }

        $effectiveStart = $shiftStart->greaterThan($workStartM) ? $shiftStart->copy() : $workStartM;
        $effectiveEnd = $now->lessThan($workEndM) ? $now->copy() : $workEndM;

        if ($effectiveEnd->lte($effectiveStart)) {
            return 0;
        }
        $runningSec = max(0, $effectiveStart->diffInSeconds($effectiveEnd));

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
                $breakStartM->addDay();
                $breakEndM->addDay();
            } elseif ($beH < $bsH || ($beH === $bsH && $beM < $bsM)) {
                $breakEndM->addDay();
            }
            $overStart = $effectiveStart->greaterThan($breakStartM) ? $effectiveStart : $breakStartM;
            $overEnd = $effectiveEnd->lessThan($breakEndM) ? $effectiveEnd : $breakEndM;
            if ($overEnd->gt($overStart)) {
                $overSec = max(0, $overStart->diffInSeconds($overEnd));
                $runningSec -= $overSec;
            }
        }
        $runningSec = max(0, $runningSec);
        return (int) min($runningSec, $maxSeconds);
    }

    private function parseTimeStringToHm(?string $time): ?array
    {
        if (!$time) {
            return null;
        }
        $time = trim($time);
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
     * Update runtime state untuk satu mesin dan return runtime_seconds saat ini.
     */
    public function updateAndGetRuntime(
        string $address,
        string $shiftKey,
        Carbon $shiftStart,
        array $statusData,
        Carbon $now
    ): int {
        $statusNorm = strtolower(trim((string) ($statusData['status'] ?? 'normal')));
        $problemType = $statusData['problem_type'] ?? $statusData['tipe_problem'] ?? '';
        $isProblem = $statusNorm === 'problem';
        $isWarning = $statusNorm === 'warning';
        $isIdle = $statusNorm === 'idle' || !empty($statusData['is_idle']);
        $isIdleOrWarning = $isIdle || $isWarning;
        $isRuntimePaused = $isIdleOrWarning || $isProblem;
        $isDowntimeActive = $isProblem && $this->isDowntimeProblemType($problemType);

        $row = MachineShiftRuntime::where('address', $address)->where('shift_key', $shiftKey)->first();
        if (!$row) {
            $row = new MachineShiftRuntime([
                'address' => $address,
                'shift_key' => $shiftKey,
                'runtime_seconds_accumulated' => 0,
                'runtime_pause_started_at' => null,
                'last_resume_at' => null,
                'downtime_started_at' => null,
            ]);
        }

        if ($isRuntimePaused) {
            if ($row->runtime_pause_started_at === null) {
                if ($row->last_resume_at !== null) {
                    $delta = max(0, $now->diffInSeconds($row->last_resume_at));
                    $row->runtime_seconds_accumulated = $row->runtime_seconds_accumulated + $delta;
                }
                $row->runtime_pause_started_at = $now->copy();
                $row->last_resume_at = null;
            }
            if ($isDowntimeActive) {
                if ($row->downtime_started_at === null) {
                    $row->downtime_started_at = $now->copy();
                }
            } else {
                $row->downtime_started_at = null;
            }
            $row->save();
            return (int) $row->runtime_seconds_accumulated;
        }

        if ($row->runtime_pause_started_at !== null) {
            $row->runtime_pause_started_at = null;
            $row->last_resume_at = $now->copy();
        } else {
            if ($row->last_resume_at !== null) {
                $delta = max(0, $now->diffInSeconds($row->last_resume_at));
                $row->runtime_seconds_accumulated = $row->runtime_seconds_accumulated + $delta;
            }
            $row->last_resume_at = $now->copy();
        }
        $row->downtime_started_at = null;
        $row->save();

        $current = $row->runtime_seconds_accumulated;
        if ($row->last_resume_at !== null) {
            $current += max(0, $now->diffInSeconds($row->last_resume_at));
        }
        return (int) $current;
    }
}
