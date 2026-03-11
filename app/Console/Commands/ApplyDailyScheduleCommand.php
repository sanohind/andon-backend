<?php

namespace App\Console\Commands;

use App\Models\MachineSchedule;
use App\Models\InspectionTable;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ApplyDailyScheduleCommand extends Command
{
    protected $signature = 'schedule:apply-daily 
                            {--date= : Tanggal (Y-m-d), default hari ini}
                            {--shift=pagi : Shift (pagi/malam)}';

    protected $description = 'Menerapkan schedule ke inspection_tables sesuai tanggal dan shift. Reset target/OT mesin lalu isi dari schedule. Jadwalkan via Laravel Scheduler (php artisan schedule:run setiap menit).';

    public function handle(): int
    {
        $dateStr = $this->option('date') ?: Carbon::now('Asia/Jakarta')->format('Y-m-d');
        $shift = $this->option('shift') ?: 'pagi';

        if (!in_array($shift, ['pagi', 'malam'])) {
            $this->error('Shift harus pagi atau malam.');
            return 1;
        }

        $date = Carbon::parse($dateStr, 'Asia/Jakarta')->startOfDay();
        $today = Carbon::now('Asia/Jakarta')->startOfDay();

        // Ambil schedule untuk tanggal + shift ini
        $schedules = MachineSchedule::whereDate('schedule_date', $date)
            ->where('shift', $shift)
            ->get();

        // 1. Reset target & OT untuk semua mesin (mesin tanpa schedule untuk hari ini jadi 0)
        $machinesAddress = $schedules->pluck('machine_address')->toArray();
        InspectionTable::query()->whereNotIn('address', $machinesAddress)
        ->update([
            'target_quantity' => 0,
            'ot_enabled' => false,
            'ot_duration_type' => null,
            'target_ot' => null,
        ]);

        // 2. Terapkan schedule (target + OT) untuk tanggal + shift ini ke inspection_tables
        $count = 0;
        foreach ($schedules as $s) {
            $table = InspectionTable::where('address', $s->machine_address)->first();
            if (!$table) {
                $this->warn("Mesin {$s->machine_address} tidak ditemukan di inspection_tables.");
                continue;
            }
            $table->target_quantity = $s->target_quantity;
            $table->ot_enabled = (bool) $s->ot_enabled;
            $table->ot_duration_type = $s->ot_enabled ? $s->ot_duration_type : null;
            $table->target_ot = $s->ot_enabled ? $s->target_ot : null;
            $table->save();
            $count++;
            $this->info("Applied: {$table->name} ({$s->machine_address}) - Target {$s->target_quantity}");
        }

        // 3. Tandai schedule yang sudah lewat sebagai closed (data tidak dihapus, hanya status)
        if (Schema::hasColumn((new MachineSchedule)->getTable(), 'status')) {
            $closed = MachineSchedule::where('schedule_date', '<', $today)->where('status', 'open')->update(['status' => 'closed']);
            if ($closed > 0) {
                $this->info("Schedule yang sudah lewat diupdate status closed: {$closed} record.");
            }
        }

        $this->info("Selesai. {$count} mesin diperbarui dari schedule {$dateStr} shift {$shift}.");
        return 0;
    }
}
