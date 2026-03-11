<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_schedules', function (Blueprint $table) {
            if (Schema::hasColumn('machine_schedules', 'cavity')) {
                $table->dropColumn('cavity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('machine_schedules', function (Blueprint $table) {
            if (!Schema::hasColumn('machine_schedules', 'cavity')) {
                $table->unsignedInteger('cavity')->default(1)->after('target_quantity');
            }
        });
    }
};

