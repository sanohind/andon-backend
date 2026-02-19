<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('machine_runtime_states', function (Blueprint $table) {
            $table->id();
            $table->string('machine_address', 100)->index()->comment('Address mesin dari inspection_tables');
            $table->string('shift_key', 100)->index()->comment('Format: YYYY-MM-DD_shift_HHmm (contoh: 2026-02-19_pagi_0700)');
            $table->dateTime('downtime_start_at')->nullable()->comment('Timestamp mulai downtime (untuk problem machine/quality/engineering)');
            $table->dateTime('runtime_pause_start_at')->nullable()->comment('Timestamp mulai runtime pause (idle/problem)');
            $table->unsignedInteger('runtime_pause_accumulated_seconds')->default(0)->comment('Total detik yang sudah di-accumulate dari pause sebelumnya');
            $table->unsignedInteger('paused_runtime_value')->nullable()->comment('Nilai runtime saat dibekukan (untuk persist saat reload)');
            $table->timestamps();

            // Unique constraint: satu mesin hanya punya satu state per shift
            $table->unique(['machine_address', 'shift_key'], 'machine_shift_unique');
            
            // Index untuk query cepat
            $table->index(['machine_address', 'shift_key', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('machine_runtime_states');
    }
};
