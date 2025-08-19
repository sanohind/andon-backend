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
            // Indeks ini akan mempercepat pencarian masalah aktif berdasarkan mesin
            $table->index(['tipe_mesin', 'status']);

            // Indeks ini akan mempercepat query yang hanya mencari berdasarkan status
            $table->index('status');

            // Indeks pada timestamp juga berguna untuk query berbasis waktu
            $table->index('timestamp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('log', function (Blueprint $table) {
            $table->dropIndex(['tipe_mesin', 'status']);
            $table->dropIndex(['status']);
            $table->dropIndex(['timestamp']);
        });
    }
};