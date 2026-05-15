<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Downtime “problem cycle time” per interval snapshot: max(0, Δrunning_hour − Δruntime),
     * selaras dengan dashboard (running hour − run time). Diisi saat oee:hourly-snapshot.
     */
    public function up(): void
    {
        Schema::table('oee_records_hourly', function (Blueprint $table) {
            $table->unsignedInteger('cycle_time_downtime_seconds')
                ->default(0)
                ->after('running_hour_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('oee_records_hourly', function (Blueprint $table) {
            $table->dropColumn('cycle_time_downtime_seconds');
        });
    }
};
