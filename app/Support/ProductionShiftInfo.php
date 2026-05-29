<?php

namespace App\Support;

use App\Models\BreakSchedule;
use Carbon\Carbon;

/**
 * Menentukan shift aktif dashboard production dan waktu reset Run Time / Running Hour / Ideal-Qty.
 * Reset terjadi pada jam awal shift (work_start dari break_schedules) + 30 detik,
 * bukan 1 menit sebelum jam awal shift (agar Availability tidak nol di akhir shift).
 */
final class ProductionShiftInfo
{
    public const RESET_DELAY_SECONDS = 30;

    private const DEFAULT_PAGI_WORK_START = '07:00';

    private const DEFAULT_MALAM_WORK_START = '20:00';

    /** @var array<int, array{pagi: array{work_start: ?string}, malam: array{work_start: ?string}}> */
    private static array $scheduleCache = [];

    /**
     * @return array{shift: string, shiftStart: Carbon, shiftKey: string}
     */
    public static function resolve(Carbon $now, ?string $timezone = null): array
    {
        $tz = $timezone ?? config('app.timezone', 'Asia/Jakarta');
        $now = $now->copy()->setTimezone($tz);

        $active = null;
        foreach (self::buildResetCandidates($now, $tz) as $candidate) {
            if ($candidate['resetAt']->gt($now)) {
                continue;
            }
            if ($active === null || $candidate['resetAt']->gt($active['resetAt'])) {
                $active = $candidate;
            }
        }

        if ($active === null) {
            $active = self::buildResetCandidates($now, $tz)[0];
        }

        $shiftStart = $active['resetAt']->copy();
        $shift = $active['shift'];
        $shiftKey = $shiftStart->format('Y-m-d') . '_' . $shift . '_' . $shiftStart->format('Hi');

        return [
            'shift' => $shift,
            'shiftStart' => $shiftStart,
            'shiftKey' => $shiftKey,
        ];
    }

    /**
     * @return array<int, array{shift: string, resetAt: Carbon}>
     */
    private static function buildResetCandidates(Carbon $now, string $tz): array
    {
        $candidates = [];
        $baseDay = $now->copy()->startOfDay();

        for ($dayOffset = -2; $dayOffset <= 0; $dayOffset++) {
            $day = $baseDay->copy()->addDays($dayOffset);
            $schedules = self::schedulesForDay($day->isoWeekday());

            foreach (['pagi', 'malam'] as $shift) {
                $workStart = $schedules[$shift]['work_start'] ?? null;
                $candidates[] = [
                    'shift' => $shift,
                    'resetAt' => self::resetTimeOnDate($day, $workStart, $shift, $tz),
                ];
            }
        }

        return $candidates;
    }

    private static function resetTimeOnDate(Carbon $day, ?string $workStart, string $shift, string $tz): Carbon
    {
        $default = $shift === 'pagi' ? self::DEFAULT_PAGI_WORK_START : self::DEFAULT_MALAM_WORK_START;
        [$h, $m] = self::parseHm($workStart ?? $default);

        return $day->copy()
            ->setTimezone($tz)
            ->setTime($h, $m, 0)
            ->addSeconds(self::RESET_DELAY_SECONDS);
    }

    /**
     * @return array{pagi: array{work_start: ?string}, malam: array{work_start: ?string}}
     */
    private static function schedulesForDay(int $dayOfWeek): array
    {
        if (!isset(self::$scheduleCache[$dayOfWeek])) {
            $result = [
                'pagi' => ['work_start' => null],
                'malam' => ['work_start' => null],
            ];

            $rows = BreakSchedule::where('day_of_week', $dayOfWeek)->get();
            foreach ($rows as $row) {
                $shift = (string) ($row->shift ?? '');
                if (!in_array($shift, ['pagi', 'malam'], true)) {
                    continue;
                }
                $result[$shift]['work_start'] = $row->work_start
                    ? substr((string) $row->work_start, 0, 5)
                    : null;
            }

            self::$scheduleCache[$dayOfWeek] = $result;
        }

        return self::$scheduleCache[$dayOfWeek];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function parseHm(string $time): array
    {
        $parts = explode(':', trim($time));
        if (count($parts) < 2) {
            return [0, 0];
        }

        return [(int) $parts[0], (int) $parts[1]];
    }
}
