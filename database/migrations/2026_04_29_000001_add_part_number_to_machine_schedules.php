<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_schedules', function (Blueprint $table) {
            $table->string('part_number', 255)->nullable()->after('machine_address');
            $table->index('part_number');
        });
    }

    public function down(): void
    {
        Schema::table('machine_schedules', function (Blueprint $table) {
            $table->dropIndex(['part_number']);
            $table->dropColumn('part_number');
        });
    }
};
