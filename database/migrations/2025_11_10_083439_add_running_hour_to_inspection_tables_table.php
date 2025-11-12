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
        Schema::table('inspection_tables', function (Blueprint $table) {
            $table->decimal('running_hour', 5, 2)->nullable()->after('cycle_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inspection_tables', function (Blueprint $table) {
            $table->dropColumn('running_hour');
        });
    }
};
