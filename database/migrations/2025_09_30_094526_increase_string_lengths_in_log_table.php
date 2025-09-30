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
            // Perbesar ukuran kolom string untuk menampung data yang lebih panjang
            $table->string('tipe_mesin', 100)->change();
            $table->string('tipe_problem', 100)->change();
            $table->string('status', 50)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('log', function (Blueprint $table) {
            // Kembalikan ke ukuran semula
            $table->string('tipe_mesin', 20)->change();
            $table->string('tipe_problem', 20)->change();
            $table->string('status', 20)->change();
        });
    }
};
