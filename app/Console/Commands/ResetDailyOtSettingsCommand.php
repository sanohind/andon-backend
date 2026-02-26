<?php

namespace App\Console\Commands;

use App\Models\InspectionTable;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ResetDailyOtSettingsCommand extends Command
{
    protected $signature = 'production:reset-ot-daily';

    protected $description = 'Reset / nonaktifkan semua pengaturan OT pada inspection_tables setiap hari (jam 07:00).';

    public function handle(): int
    {
        $now = Carbon::now(config('app.timezone', 'Asia/Jakarta'));

        $updated = InspectionTable::query()->update([
            'ot_enabled' => false,
            'ot_duration_type' => null,
        ]);

        $this->info("Reset OT harian selesai pada {$now->format('Y-m-d H:i:s')} - {$updated} mesin di-nonaktifkan OT-nya.");

        return self::SUCCESS;
    }
}

