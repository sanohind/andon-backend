<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Tambahkan kolom ke inspection_tables
        Schema::table('inspection_tables', function (Blueprint $table) {
            $table->integer('target_quantity')->nullable()->after('oee');
            $table->integer('cycle_time')->nullable()->after('target_quantity'); // seconds
        });

        // Migrasi data dari production_data ke inspection_tables (jika ada)
        // Ambil nilai terbaru per address dan update ke inspection_tables
        // Menggunakan syntax PostgreSQL yang benar
        DB::statement("
            UPDATE inspection_tables it
            SET target_quantity = pd.target_quantity,
                cycle_time = pd.cycle_time
            FROM (
                SELECT pd1.machine_name, pd1.target_quantity, pd1.cycle_time
                FROM production_data pd1
                INNER JOIN (
                    SELECT machine_name, MAX(timestamp) as max_timestamp
                    FROM production_data
                    WHERE target_quantity IS NOT NULL OR cycle_time IS NOT NULL
                    GROUP BY machine_name
                ) pd_max ON pd1.machine_name = pd_max.machine_name 
                    AND pd1.timestamp = pd_max.max_timestamp
            ) pd
            WHERE it.address = pd.machine_name
        ");

        // Hapus kolom dari production_data
        Schema::table('production_data', function (Blueprint $table) {
            $table->dropColumn(['target_quantity', 'cycle_time']);
        });
    }

    public function down(): void
    {
        // Kembalikan kolom ke production_data
        Schema::table('production_data', function (Blueprint $table) {
            $table->integer('target_quantity')->nullable()->after('quantity');
            $table->integer('cycle_time')->nullable()->after('target_quantity');
        });

        // Migrasi data kembali (jika diperlukan)
        // Hapus kolom dari inspection_tables
        Schema::table('inspection_tables', function (Blueprint $table) {
            $table->dropColumn(['target_quantity', 'cycle_time']);
        });
    }
};

