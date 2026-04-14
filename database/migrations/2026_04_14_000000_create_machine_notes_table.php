<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_notes', function (Blueprint $table) {
            $table->id();
            $table->string('machine_name');
            $table->string('line_name')->nullable();
            $table->string('shift_key'); // YYYY-MM-DD_{pagi|malam}_HHmm (shift start)
            $table->string('shift', 16); // pagi|malam
            $table->dateTime('shift_start_at');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['machine_name', 'line_name', 'shift_key'], 'machine_notes_unique_machine_line_shift');
            $table->index(['line_name', 'shift_key'], 'machine_notes_line_shift_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_notes');
    }
};

