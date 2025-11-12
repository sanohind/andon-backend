<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('part_configurations', 'line_name')) {
            Schema::table('part_configurations', function (Blueprint $table) {
                $table->string('line_name', 50)->nullable()->after('address');
            });
        }

        // Backfill line_name based on address -> inspection_tables.address match
        // Works on PostgreSQL syntax
        DB::statement("
            UPDATE part_configurations pc
            SET line_name = it.line_name
            FROM inspection_tables it
            WHERE pc.address = it.address
        ");
    }

    public function down(): void
    {
        if (Schema::hasColumn('part_configurations', 'line_name')) {
            Schema::table('part_configurations', function (Blueprint $table) {
                $table->dropColumn('line_name');
            });
        }
    }
};


