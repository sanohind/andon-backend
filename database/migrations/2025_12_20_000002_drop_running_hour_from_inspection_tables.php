<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('inspection_tables', 'running_hour')) {
            Schema::table('inspection_tables', function (Blueprint $table) {
                $table->dropColumn('running_hour');
            });
        }
    }

    public function down(): void
    {
        Schema::table('inspection_tables', function (Blueprint $table) {
            $table->decimal('running_hour', 5, 2)->nullable()->after('cycle_time');
        });
    }
};


