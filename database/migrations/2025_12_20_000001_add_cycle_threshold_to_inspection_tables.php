<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_tables', function (Blueprint $table) {
            $table->integer('warning_cycle_count')->nullable()->after('cycle_time')->comment('Number of cycle times before warning status');
            $table->integer('problem_cycle_count')->nullable()->after('warning_cycle_count')->comment('Number of cycle times before problem status');
        });
    }

    public function down(): void
    {
        Schema::table('inspection_tables', function (Blueprint $table) {
            $table->dropColumn(['warning_cycle_count', 'problem_cycle_count']);
        });
    }
};

