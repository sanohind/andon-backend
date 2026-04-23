<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('part_configurations')) {
            return;
        }

        Schema::table('part_configurations', function (Blueprint $table) {
            if (!Schema::hasColumn('part_configurations', 'channel')) {
                $table->integer('channel')->nullable()->after('part_name');
            }
            if (!Schema::hasColumn('part_configurations', 'line_name')) {
                $table->string('line_name', 50)->nullable()->after('channel');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('part_configurations')) {
            return;
        }

        Schema::table('part_configurations', function (Blueprint $table) {
            if (Schema::hasColumn('part_configurations', 'line_name')) {
                $table->dropColumn('line_name');
            }
            if (Schema::hasColumn('part_configurations', 'channel')) {
                $table->dropColumn('channel');
            }
        });
    }
};
