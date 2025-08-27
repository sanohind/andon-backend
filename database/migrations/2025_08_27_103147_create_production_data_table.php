<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_data', function (Blueprint $table) {
            $table->id();
            $table->timestamp('timestamp')->useCurrent();
            $table->string('machine_name', 50);
            $table->integer('quantity');
            $table->index('machine_name'); // Menambahkan indeks untuk pencarian cepat
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_data');
    }
};