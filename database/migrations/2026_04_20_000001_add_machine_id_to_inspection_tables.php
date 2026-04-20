<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('inspection_tables', 'machine_id')) {
            return;
        }

        Schema::table('inspection_tables', function (Blueprint $table) {
            $table->string('machine_id', 100)->nullable()->after('id');
        });

        $driver = Schema::getConnection()->getDriverName();
        $sql = match ($driver) {
            'pgsql' => "UPDATE inspection_tables SET machine_id = 'TEMP-' || id::text WHERE machine_id IS NULL OR TRIM(COALESCE(machine_id, '')) = ''",
            'mysql' => "UPDATE inspection_tables SET machine_id = CONCAT('TEMP-', id) WHERE machine_id IS NULL OR machine_id = ''",
            default => "UPDATE inspection_tables SET machine_id = 'TEMP-' || CAST(id AS TEXT) WHERE machine_id IS NULL OR TRIM(COALESCE(machine_id, '')) = ''",
        };
        DB::statement($sql);

        Schema::table('inspection_tables', function (Blueprint $table) {
            $table->unique('machine_id');
        });

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE inspection_tables ALTER COLUMN machine_id SET NOT NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE inspection_tables MODIFY machine_id VARCHAR(100) NOT NULL');
        } elseif ($driver === 'sqlite') {
            // SQLite: recreate not needed if column already has values; enforce NOT NULL via table rebuild is heavy — leave NOT NULL implicit for tests
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('inspection_tables', 'machine_id')) {
            return;
        }

        Schema::table('inspection_tables', function (Blueprint $table) {
            $table->dropUnique(['machine_id']);
        });

        Schema::table('inspection_tables', function (Blueprint $table) {
            $table->dropColumn('machine_id');
        });
    }
};
