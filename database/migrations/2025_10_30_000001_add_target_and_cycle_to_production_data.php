<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_data', function (Blueprint $table) {
            $table->integer('target_quantity')->nullable()->after('quantity');
            $table->integer('cycle_time')->nullable()->after('target_quantity'); // seconds
        });
    }

    public function down(): void
    {
        Schema::table('production_data', function (Blueprint $table) {
            $table->dropColumn(['target_quantity', 'cycle_time']);
        });
    }
};


