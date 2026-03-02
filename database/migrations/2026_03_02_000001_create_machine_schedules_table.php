<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_schedules', function (Blueprint $table) {
            $table->id();
            $table->date('schedule_date');
            $table->string('machine_address', 20);
            $table->unsignedInteger('target_quantity')->default(0);
            $table->unsignedInteger('cavity')->default(1);
            $table->boolean('ot_enabled')->default(false);
            $table->string('ot_duration_type', 50)->nullable();
            $table->unsignedInteger('target_ot')->nullable();
            $table->timestamps();
            $table->index(['schedule_date', 'machine_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_schedules');
    }
};
