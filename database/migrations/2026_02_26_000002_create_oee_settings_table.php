<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oee_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('warning_threshold_percent', 5, 2)->default(85.00);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oee_settings');
    }
};

