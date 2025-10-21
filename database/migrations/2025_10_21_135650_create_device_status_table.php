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
        Schema::create('device_status', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 50)->unique(); // ID unik, e.g., 'PLC1', 'PLC2', 'NODE_RED_PI'
            $table->string('device_name', 100)->nullable(); // Nama yang mudah dibaca, e.g., 'PLC Line 1'
            $table->string('status', 20); // 'ONLINE', 'OFFLINE', 'STARTING'
            $table->timestamp('last_seen'); // Timestamp kapan terakhir terlihat
            $table->text('details')->nullable(); // Untuk pesan error atau info tambahan
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_status');
    }
};
