<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Pastikan timezone database sudah diatur ke Asia/Jakarta
        DB::statement("SET timezone = 'Asia/Jakarta'");
        
        // Set timezone untuk session saat ini
        DB::statement("SET SESSION timezone = 'Asia/Jakarta'");
        
        // Update semua timestamp yang ada untuk menggunakan timezone Asia/Jakarta
        DB::statement("
            UPDATE log 
            SET timestamp = timestamp AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Jakarta'
            WHERE timestamp IS NOT NULL
        ");
        
        // Update resolved_at jika ada
        DB::statement("
            UPDATE log 
            SET resolved_at = resolved_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Jakarta'
            WHERE resolved_at IS NOT NULL
        ");
        
        // Update forwarded_at jika ada
        DB::statement("
            UPDATE log 
            SET forwarded_at = forwarded_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Jakarta'
            WHERE forwarded_at IS NOT NULL
        ");
        
        // Update received_at jika ada
        DB::statement("
            UPDATE log 
            SET received_at = received_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Jakarta'
            WHERE received_at IS NOT NULL
        ");
        
        // Update feedback_resolved_at jika ada
        DB::statement("
            UPDATE log 
            SET feedback_resolved_at = feedback_resolved_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Jakarta'
            WHERE feedback_resolved_at IS NOT NULL
        ");
        
        // Set default timezone untuk semua kolom timestamp
        DB::statement("
            ALTER TABLE log 
            ALTER COLUMN timestamp SET DEFAULT (NOW() AT TIME ZONE 'Asia/Jakarta'),
            ALTER COLUMN resolved_at SET DEFAULT NULL,
            ALTER COLUMN forwarded_at SET DEFAULT NULL,
            ALTER COLUMN received_at SET DEFAULT NULL,
            ALTER COLUMN feedback_resolved_at SET DEFAULT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Kembalikan ke UTC
        DB::statement("SET timezone = 'UTC'");
        DB::statement("SET SESSION timezone = 'UTC'");
        
        // Update semua timestamp kembali ke UTC
        DB::statement("
            UPDATE log 
            SET timestamp = timestamp AT TIME ZONE 'Asia/Jakarta' AT TIME ZONE 'UTC'
            WHERE timestamp IS NOT NULL
        ");
        
        DB::statement("
            UPDATE log 
            SET resolved_at = resolved_at AT TIME ZONE 'Asia/Jakarta' AT TIME ZONE 'UTC'
            WHERE resolved_at IS NOT NULL
        ");
        
        DB::statement("
            UPDATE log 
            SET forwarded_at = forwarded_at AT TIME ZONE 'Asia/Jakarta' AT TIME ZONE 'UTC'
            WHERE forwarded_at IS NOT NULL
        ");
        
        DB::statement("
            UPDATE log 
            SET received_at = received_at AT TIME ZONE 'Asia/Jakarta' AT TIME ZONE 'UTC'
            WHERE received_at IS NOT NULL
        ");
        
        DB::statement("
            UPDATE log 
            SET feedback_resolved_at = feedback_resolved_at AT TIME ZONE 'Asia/Jakarta' AT TIME ZONE 'UTC'
            WHERE feedback_resolved_at IS NOT NULL
        ");
    }
};
