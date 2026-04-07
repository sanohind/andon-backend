<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot OEE per jam (sejajar dengan production_data_hourly).
     */
    public function up(): void
    {
        Schema::create('oee_records_hourly', function (Blueprint $table) {
            $table->id();
            $table->dateTime('snapshot_at');
            $table->string('machine_address', 100);
            $table->string('line_name', 100)->nullable();
            $table->string('division', 100)->nullable();
            $table->decimal('oee_percent', 8, 2)->nullable();
            $table->decimal('availability_percent', 8, 2)->nullable();
            $table->decimal('performance_percent', 8, 2)->nullable();
            $table->decimal('quality_percent', 6, 2)->default(100);
            $table->unsignedInteger('runtime_seconds')->default(0);
            $table->unsignedInteger('running_hour_seconds')->default(0);
            $table->unsignedInteger('total_product')->default(0);
            $table->timestamps();

            $table->index(['snapshot_at', 'machine_address'], 'oee_hourly_snap_machine_idx');
            $table->index('snapshot_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oee_records_hourly');
    }
};
