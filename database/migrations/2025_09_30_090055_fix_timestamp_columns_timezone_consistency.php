<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Pastikan timezone database sudah diatur ke Asia/Jakarta
        DB::statement("SET timezone = 'Asia/Jakarta'");
        
        // Modifikasi kolom timestamp untuk konsistensi timezone
        Schema::table('log', function (Blueprint $table) {
            // Ubah kolom timestamp yang sudah ada untuk menggunakan timezone yang konsisten
            $table->timestamp('timestamp')->useCurrent()->change();
            $table->timestamp('resolved_at')->nullable()->change();
            $table->timestamp('forwarded_at')->nullable()->change();
            $table->timestamp('received_at')->nullable()->change();
            $table->timestamp('feedback_resolved_at')->nullable()->change();
        });
        
        // Set default timezone untuk semua kolom timestamp
        DB::statement("
            ALTER TABLE log 
            ALTER COLUMN timestamp SET DEFAULT CURRENT_TIMESTAMP,
            ALTER COLUMN resolved_at SET DEFAULT NULL,
            ALTER COLUMN forwarded_at SET DEFAULT NULL,
            ALTER COLUMN received_at SET DEFAULT NULL,
            ALTER COLUMN feedback_resolved_at SET DEFAULT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Kembalikan ke konfigurasi sebelumnya
        Schema::table('log', function (Blueprint $table) {
            $table->timestamp('timestamp')->useCurrent()->change();
            $table->timestamp('resolved_at')->nullable()->change();
            $table->timestamp('forwarded_at')->nullable()->change();
            $table->timestamp('received_at')->nullable()->change();
            $table->timestamp('feedback_resolved_at')->nullable()->change();
        });
    }
};