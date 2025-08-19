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
        Schema::table('log', function (Blueprint $table) {
            // Kapan masalah diselesaikan. Nullable karena masalah aktif belum punya waktu selesai.
            $table->timestamp('resolved_at')->nullable()->after('status');

            // Durasi dalam detik untuk mempermudah kalkulasi.
            $table->integer('duration_in_seconds')->nullable()->after('resolved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('log', function (Blueprint $table) {
            $table->dropColumn(['resolved_at', 'duration_in_seconds']);
        });
    }
};