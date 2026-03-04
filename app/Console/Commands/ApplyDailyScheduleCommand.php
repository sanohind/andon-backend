<?php

namespace App\Console\Commands;

use App\Models\MachineSchedule;
use App\Models\InspectionTable;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ApplyDailyScheduleCommand extends Command
{
    protected $signature = 'schedule:apply-daily 
                            {--date= : Tanggal (Y-m-d), default hari ini}
                            {--shift=pagi : Shift (pagi/malam)}';

    protected $description = 'Menerapkan schedule ke inspection_tables sesuai tanggal dan shift. Jalankan tiap hari untuk reset & isi ulang data mesin.';

    public function handle(): int
    {
        $dateStr = $this->option('date') ?: Carbon::now('Asia/Jakarta')->format('Y-m-d');
        $shift = $this->option('shift') ?: 'pagi';

        if (!in_array($shift, ['pagi', 'malam'])) {
            $this->error('Shift harus pagi atau malam.');
            return 1;
        }

        $date = Carbon::parse($dateStr, 'Asia/Jakarta')->startOfDay();

        // Reset target & OT untuk semua mesin terlebih dahulu (agar mesin tanpa schedule juga ter-reset)
        InspectionTable::query()->update([
            'target_quantity' => 0,
            'ot_enabled' => false,
            'ot_duration_type' => null,
            'target_ot' => null,
        ]);

        $schedules = MachineSchedule::whereDate('schedule_date', $date)
            ->where('shift', $shift)
            ->get();

        $count = 0;
        foreach ($schedules as $s) {
            $table = InspectionTable::where('address', $s->machine_address)->first();
            if (!$table) {
                $this->warn("Mesin {$s->machine_address} tidak ditemukan di inspection_tables.");
                continue;
            }
            $table->target_quantity = $s->target_quantity;
            $table->cavity = $s->cavity;
            $table->ot_enabled = (bool) $s->ot_enabled;
            $table->ot_duration_type = $s->ot_enabled ? $s->ot_duration_type : null;
            $table->target_ot = $s->ot_enabled ? $s->target_ot : null;
            $table->save();
            $count++;
            $this->info("Applied: {$table->name} ({$s->machine_address}) - Target {$s->target_quantity}, Cavity {$s->cavity}");
        }

        $this->info("Selesai. {$count} mesin diperbarui dari schedule {$dateStr} shift {$shift}.");
        return 0;
    }
}
