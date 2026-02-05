<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom pengaturan OT (Over Time / Lembur) ke inspection_tables.
     * Kompatibel PostgreSQL & MySQL; aman dijalankan ulang (hanya tambah jika belum ada).
     */
    public function up(): void
    {
        Schema::table('inspection_tables', function (Blueprint $table) {
            if (!Schema::hasColumn('inspection_tables', 'ot_enabled')) {
                $table->boolean('ot_enabled')->default(false);
            }
            if (!Schema::hasColumn('inspection_tables', 'ot_duration_type')) {
                $table->string('ot_duration_type', 20)->nullable();
            }
            if (!Schema::hasColumn('inspection_tables', 'target_ot')) {
                $table->unsignedInteger('target_ot')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('inspection_tables', function (Blueprint $table) {
            if (Schema::hasColumn('inspection_tables', 'ot_enabled')) {
                $table->dropColumn('ot_enabled');
            }
            if (Schema::hasColumn('inspection_tables', 'ot_duration_type')) {
                $table->dropColumn('ot_duration_type');
            }
            if (Schema::hasColumn('inspection_tables', 'target_ot')) {
                $table->dropColumn('target_ot');
            }
        });
    }
};
