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
        Schema::create('part_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('address', 20);
            $table->integer('channel');
            $table->string('part_number');
            $table->integer('cycle_time')->nullable();
            $table->integer('jumlah_bending');
            $table->integer('cavity');
            $table->timestamps();
            
            // Foreign key constraint ke inspection_tables
            $table->foreign('address')->references('address')->on('inspection_tables')->onDelete('cascade');
            
            // Index untuk performa
            $table->index('address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('part_configurations');
    }
};
