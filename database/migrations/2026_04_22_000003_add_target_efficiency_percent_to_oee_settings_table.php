<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oee_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('oee_settings', 'target_efficiency_percent')) {
                $table->decimal('target_efficiency_percent', 5, 2)->default(96.00)->after('warning_threshold_percent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('oee_settings', function (Blueprint $table) {
            if (Schema::hasColumn('oee_settings', 'target_efficiency_percent')) {
                $table->dropColumn('target_efficiency_percent');
            }
        });
    }
};

