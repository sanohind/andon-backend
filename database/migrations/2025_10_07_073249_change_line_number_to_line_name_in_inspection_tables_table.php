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
        Schema::table('inspection_tables', function (Blueprint $table) {
            // Drop existing line_number column
            $table->dropColumn('line_number');
        });
        
        Schema::table('inspection_tables', function (Blueprint $table) {
            // Add new line_name column
            $table->string('line_name', 50)->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inspection_tables', function (Blueprint $table) {
            // Drop line_name column
            $table->dropColumn('line_name');
        });
        
        Schema::table('inspection_tables', function (Blueprint $table) {
            // Restore line_number column
            $table->integer('line_number')->after('name');
        });
    }
};
