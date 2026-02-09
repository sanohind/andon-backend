<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BreakSchedule extends Model
{
    protected $table = 'break_schedules';

    protected $fillable = [
        'day_of_week',
        'shift',
        'work_start',
        'work_end',
        'break_1_start', 'break_1_end',
        'break_2_start', 'break_2_end',
        'break_3_start', 'break_3_end',
        'break_4_start', 'break_4_end',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
    ];

    /**
     * Jumlah slot istirahat per hari: Senin–Kamis 3, Jumat 4, Sabtu–Minggu 2.
     */
    public static function breakSlotsForDay(int $dayOfWeek): int
    {
        if ($dayOfWeek === 5) {
            return 4; // Jumat
        }
        if ($dayOfWeek === 6 || $dayOfWeek === 7) {
            return 2; // Sabtu, Minggu
        }
        return 3; // Senin–Kamis (1-4)
    }

    /**
     * Return breaks as array of [start, end] (time strings) for the given slot count.
     */
    public function getBreaksArray(): array
    {
        $slots = self::breakSlotsForDay((int) $this->day_of_week);
        $out = [];
        for ($i = 1; $i <= $slots; $i++) {
            $start = $this->{"break_{$i}_start"};
            $end = $this->{"break_{$i}_end"};
            if ($start && $end) {
                $out[] = [
                    'start' => substr((string) $start, 0, 5),
                    'end' => substr((string) $end, 0, 5),
                ];
            }
        }
        return $out;
    }
}
