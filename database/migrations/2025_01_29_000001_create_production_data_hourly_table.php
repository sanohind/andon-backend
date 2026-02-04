<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_data_hourly', function (Blueprint $table) {
            $table->id();
            $table->dateTime('snapshot_at')->comment('Waktu snapshot (setiap jam XX:58)');
            $table->string('machine_name', 50);
            $table->string('line_name', 100)->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();

            $table->index(['snapshot_at', 'machine_name']);
            $table->index('snapshot_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_data_hourly');
    }
};
