<?php

namespace App\Console\Commands;

use App\Models\MachineSchedule;
use App\Models\InspectionTable;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        if ($schedules->isEmpty()) {
            $this->warn("Tidak ada schedule untuk tanggal {$dateStr} shift {$shift}.");
            Log::warning('Schedule Kosong', ['date' => $dateStr, 'shift' => $shift]);
            return 0;
        }

        // 1. Reset target & OT untuk semua mesin (mesin tanpa schedule untuk hari ini jadi 0)
        $machinesAddress = $schedules->pluck('machine_address')->toArray();
        $count = 0;

        DB::transaction(function () use ($schedules, $machinesAddress, $dateStr, $shift, &$count) {
            InspectionTable::query()
                ->whereNotIn('address', $machinesAddress)
                ->update([
                    'target_quantity' => 0,
                    // PostgreSQL butuh literal boolean true/false, bukan 0/1
                    'ot_enabled' => DB::raw('false'),
                    'ot_duration_type' => null,
                    'target_ot' => null,
                ]);

            // 2. Terapkan schedule ke inspection_tables
            foreach ($schedules as $s) {

                $table = InspectionTable::where('address', $s->machine_address)->first();

                if (!$table) {

                    $this->warn("Mesin {$s->machine_address} tidak ditemukan di inspection_tables.");
                    // Logging jika mesin tidak ditemukan
                    Log::error("Machine address tidak ditemukan", [
                        'machine_address' => $s->machine_address
                    ]);
                

                    continue;
                }

                $otEnabled = filter_var($s->ot_enabled ?? false, FILTER_VALIDATE_BOOLEAN);

                // PostgreSQL boolean tidak menerima integer 0/1 pada update query.
                // Pakai literal true/false agar aman di semua kondisi (enable/disable).
                DB::table('inspection_tables')
                    ->where('id', $table->id)
                    ->update([
                        'target_quantity' => (int) ($s->target_quantity ?? 0),
                        'ot_enabled' => DB::raw($otEnabled ? 'true' : 'false'),
                        'ot_duration_type' => $otEnabled ? ($s->ot_duration_type ?? null) : null,
                        'target_ot' => $otEnabled ? ($s->target_ot ?? null) : null,
                        'updated_at' => now(),
                    ]);

                $count++;

                $this->info("Applied: {$table->name} ({$s->machine_address}) - Target {$s->target_quantity}");
            }
            // Logging hasil schedule
            Log::info("Schedule applied", [
                'date' => $dateStr,
                'shift' => $shift,
                'machines_updated' => $count
            ]);

        });
        // 3. Tandai schedule yang sudah lewat sebagai closed (data tidak dihapus, hanya status)
        if (Schema::hasColumn((new MachineSchedule)->getTable(), 'status')) {
            $closed = MachineSchedule::where('schedule_date', '<', $today)->where('status', 'open')->update(['status' => 'closed']);
            if ($closed > 0) {
                $this->info("Schedule yang sudah lewat diupdate status closed: {$closed} record.");
                Log::info("Schedule closed otomatis", ['records' => $closed]);
            }
        }

        $this->info("Selesai. {$count} mesin diperbarui dari schedule {$dateStr} shift {$shift}.");
        return 0;
    }
}
