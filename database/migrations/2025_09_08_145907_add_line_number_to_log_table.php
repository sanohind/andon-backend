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
            // Tambahkan kolom setelah 'tipe_problem' agar logis
            $table->integer('line_number')->nullable()->after('tipe_problem');
        });
    }

    public function down(): void
    {
        Schema::table('log', function (Blueprint $table) {
            $table->dropColumn('line_number');
        });
    }
};
