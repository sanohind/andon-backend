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
        Schema::create('production_downtime_data', function (Blueprint $table) {
            $table->id();
            $table->string('machine_name');
            $table->string('line_name')->nullable();
            $table->string('shift_key');
            $table->unsignedInteger('downtime_seconds')->default(0);
            $table->unsignedInteger('open_records_count')->default(0);
            $table->timestamp('snapshot_at');
            $table->timestamps();

            $table->index(['machine_name', 'shift_key']);
            $table->index('snapshot_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_downtime_data');
    }
};

