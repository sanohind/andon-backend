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
        Schema::create('production_downtime_records', function (Blueprint $table) {
            $table->id();
            $table->string('mongo_id')->unique();
            $table->string('machine_name');
            $table->string('line_process_id');
            $table->string('line_process_code')->nullable();
            $table->string('line_process_name')->nullable();
            $table->string('cause')->nullable();
            $table->timestamp('start_at');
            $table->timestamp('end_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->boolean('is_closed')->default(false);
            $table->string('shift_key');
            $table->timestamps();

            $table->index(['machine_name', 'shift_key']);
            $table->index('is_closed');
            $table->index('start_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_downtime_records');
    }
};

