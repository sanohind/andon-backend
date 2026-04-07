<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ringkasan OEE per mesin per tanggal shift (nilai terakhir dari snapshot jam-an).
     */
    public function up(): void
    {
        Schema::create('oee_records', function (Blueprint $table) {
            $table->id();
            $table->date('shift_date');
            $table->string('shift', 10);
            $table->string('machine_address', 100);
            $table->string('machine_name', 150)->nullable();
            $table->string('line_name', 100)->nullable();
            $table->string('division', 100)->nullable();
            $table->decimal('oee_percent', 8, 2)->nullable();
            $table->decimal('availability_percent', 8, 2)->nullable();
            $table->decimal('performance_percent', 8, 2)->nullable();
            $table->decimal('quality_percent', 6, 2)->default(100);
            $table->unsignedInteger('runtime_seconds')->default(0);
            $table->unsignedInteger('running_hour_seconds')->default(0);
            $table->unsignedInteger('total_product')->default(0);
            $table->dateTime('snapshot_at')->nullable();
            $table->timestamps();

            $table->unique(['shift_date', 'shift', 'machine_address'], 'oee_records_shift_machine_unique');
            $table->index(['shift_date', 'shift']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oee_records');
    }
};
