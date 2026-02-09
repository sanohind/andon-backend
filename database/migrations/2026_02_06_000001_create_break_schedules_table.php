<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Jam kerja dan jam istirahat per hari (Senin–Minggu) per shift (pagi/malam).
     * Senin–Kamis: 3 istirahat; Jumat: 4 istirahat; Sabtu–Minggu: 2 istirahat.
     */
    public function up(): void
    {
        Schema::create('break_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('day_of_week'); // 1=Senin .. 7=Minggu
            $table->string('shift', 10); // pagi | malam
            $table->time('work_start')->nullable();
            $table->time('work_end')->nullable();
            $table->time('break_1_start')->nullable();
            $table->time('break_1_end')->nullable();
            $table->time('break_2_start')->nullable();
            $table->time('break_2_end')->nullable();
            $table->time('break_3_start')->nullable();
            $table->time('break_3_end')->nullable();
            $table->time('break_4_start')->nullable();
            $table->time('break_4_end')->nullable();
            $table->timestamps();
            $table->unique(['day_of_week', 'shift']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('break_schedules');
    }
};
