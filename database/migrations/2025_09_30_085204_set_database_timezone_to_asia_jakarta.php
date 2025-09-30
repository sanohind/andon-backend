<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Set timezone database PostgreSQL ke Asia/Jakarta
        DB::statement("SET timezone = 'Asia/Jakarta'");
        
        // Set timezone untuk session saat ini
        DB::statement("SET SESSION timezone = 'Asia/Jakarta'");
        
        // Set timezone default untuk database
        DB::statement("ALTER DATABASE " . env('DB_DATABASE', 'iot_project') . " SET timezone = 'Asia/Jakarta'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Kembalikan ke UTC
        DB::statement("SET timezone = 'UTC'");
        DB::statement("SET SESSION timezone = 'UTC'");
        DB::statement("ALTER DATABASE " . env('DB_DATABASE', 'iot_project') . " SET timezone = 'UTC'");
    }
};