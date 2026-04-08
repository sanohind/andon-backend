<?php

namespace App\Support;

/**
 * Tambahan detik akhir jendela kerja (running hour) saat OT aktif,
 * selaras dengan InspectionTableController: 2h_pagi, 3.5h_pagi, 2h_malam.
 */
final class RunningHourOtExtension
{
    public static function extraSeconds(bool $otEnabled, ?string $otDurationType, string $shift): int
    {
        if (!$otEnabled || $otDurationType === null || $otDurationType === '') {
            return 0;
        }

        $t = trim($otDurationType);

        if ($shift === 'pagi') {
            if ($t === '2h_pagi') {
                return 2 * 3600;
            }
            if ($t === '3.5h_pagi') {
                return (int) (3.5 * 3600);
            }
        } elseif ($shift === 'malam' && $t === '2h_malam') {
            return 2 * 3600;
        }

        return 0;
    }
}
