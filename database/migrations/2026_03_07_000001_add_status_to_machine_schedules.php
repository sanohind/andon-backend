<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add status column (open/closed). Schedule records are never deleted when date passes;
     * status is set to 'closed' so data remains visible.
     */
    public function up(): void
    {
        Schema::table('machine_schedules', function (Blueprint $table) {
            $table->string('status', 20)->default('open')->after('target_ot');
        });

        // Set existing past schedules to closed
        $today = now()->startOfDay()->format('Y-m-d');
        DB::table('machine_schedules')
            ->where('schedule_date', '<', $today)
            ->update(['status' => 'closed']);
    }

    public function down(): void
    {
        Schema::table('machine_schedules', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
