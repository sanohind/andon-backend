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
        Schema::create('ticketing_problems', function (Blueprint $table) {
            $table->id();
            
            // Foreign key ke tabel log (problem utama)
            $table->unsignedBigInteger('problem_id');
            $table->foreign('problem_id')->references('id')->on('log')->onDelete('cascade');
            
            // PIC/Teknisi yang menangani problem
            $table->string('pic_technician', 100);
            
            // Diagnosa/analisis masalah
            $table->text('diagnosis');
            
            // Result/perbaikan yang dilakukan
            $table->text('result_repair');
            
            // Timestamps untuk perhitungan waktu
            $table->timestamp('problem_received_at')->nullable(); // Waktu problem di-receive
            $table->timestamp('diagnosis_started_at')->nullable(); // Waktu mulai diagnosa
            $table->timestamp('repair_started_at')->nullable(); // Waktu mulai perbaikan
            $table->timestamp('repair_completed_at')->nullable(); // Waktu selesai perbaikan
            
            // Perhitungan waktu (dalam detik)
            $table->integer('downtime_seconds')->nullable(); // Total downtime
            $table->integer('mttr_seconds')->nullable(); // Mean Time To Repair
            $table->integer('mttd_seconds')->nullable(); // Mean Time To Diagnose
            $table->integer('mtbf_seconds')->nullable(); // Mean Time Between Failures
            
            // Status ticketing
            $table->enum('status', ['open', 'in_progress', 'close', 'completed', 'cancelled'])->default('open');
            
            // User yang membuat ticketing
            $table->unsignedBigInteger('created_by_user_id');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('cascade');
            
            // User yang mengupdate terakhir
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->foreign('updated_by_user_id')->references('id')->on('users')->onDelete('set null');
            
            // Metadata tambahan
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Indexes untuk performa
            $table->index(['problem_id', 'status']);
            $table->index(['created_by_user_id', 'created_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticketing_problems');
    }
};
