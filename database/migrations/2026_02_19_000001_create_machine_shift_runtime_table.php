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
        Schema::create('machine_shift_runtime', function (Blueprint $table) {
            $table->id();
            $table->string('address', 100)->index();
            $table->string('shift_key', 64)->index();
            $table->unsignedInteger('runtime_seconds_accumulated')->default(0);
            $table->timestamp('runtime_pause_started_at')->nullable();
            $table->timestamp('last_resume_at')->nullable();
            $table->timestamp('downtime_started_at')->nullable();
            $table->timestamps();
            $table->unique(['address', 'shift_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('machine_shift_runtime');
    }
};
