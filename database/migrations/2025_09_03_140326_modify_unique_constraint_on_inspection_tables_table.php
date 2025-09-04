<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_tables', function (Blueprint $table) {
            // Hapus aturan unik yang lama
            $table->dropUnique(['name']);

            // Buat aturan unik gabungan yang baru
            $table->unique(['name', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::table('inspection_tables', function (Blueprint $table) {
            $table->dropUnique(['name', 'line_number']);
            $table->unique('name');
        });
    }
};