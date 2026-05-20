<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu tabel snapshot per 5 menit: produksi (pcs), ideal qty (dashboard), dan metrik OEE.
     * Terpisah dari snapshot per jam (production_data_hourly / oee_records_hourly).
     */
    public function up(): void
    {
        Schema::create('production_oee_snapshots_five_minute', function (Blueprint $table) {
            $table->id();
            $table->dateTime('snapshot_at');
            $table->string('machine_name', 100);
            $table->string('line_name', 100)->nullable();
            $table->string('division', 100)->nullable();
            $table->unsignedInteger('shot_quantity')->default(0)->comment('Counter cetakan dari production_data');
            $table->unsignedInteger('total_product')->default(0)->comment('Pcs = shot_quantity × cavity');
            $table->unsignedInteger('ideal_quantity')->default(0)->comment('Ideal Qty dashboard: floor(running_hour/cycle)×cavity');
            $table->decimal('oee_percent', 8, 2)->nullable();
            $table->decimal('availability_percent', 8, 2)->nullable();
            $table->decimal('performance_percent', 8, 2)->nullable();
            $table->decimal('quality_percent', 6, 2)->default(100);
            $table->unsignedInteger('runtime_seconds')->default(0);
            $table->unsignedInteger('running_hour_seconds')->default(0);
            $table->timestamps();

            $table->index(['snapshot_at', 'machine_name'], 'poee5_snap_machine_idx');
            $table->index('snapshot_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_oee_snapshots_five_minute');
    }
};
