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
        Schema::create('forward_problem_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('problem_id'); // ID dari tabel log utama
            $table->string('event_type'); // 'forward', 'receive', 'feedback_resolved', 'final_resolved'
            $table->unsignedBigInteger('user_id'); // ID user yang melakukan aksi
            $table->string('user_role'); // Role user yang melakukan aksi
            $table->string('target_role')->nullable(); // Role target (untuk forward)
            $table->text('message')->nullable(); // Pesan atau catatan
            $table->timestamp('event_timestamp'); // Timestamp event
            $table->json('metadata')->nullable(); // Data tambahan dalam format JSON
            $table->timestamps();
            
            // Foreign key constraints
            $table->foreign('problem_id')->references('id')->on('log')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Indexes untuk performa
            $table->index(['problem_id', 'event_type']);
            $table->index(['user_id', 'event_timestamp']);
            $table->index('event_timestamp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forward_problem_logs');
    }
};
