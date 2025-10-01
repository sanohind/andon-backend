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
        // Hapus default terlebih dahulu
        DB::statement('ALTER TABLE log ALTER COLUMN is_forwarded DROP DEFAULT');
        DB::statement('ALTER TABLE log ALTER COLUMN is_received DROP DEFAULT');
        DB::statement('ALTER TABLE log ALTER COLUMN has_feedback_resolved DROP DEFAULT');
        
        // Ubah kolom boolean menjadi integer menggunakan SQL langsung
        DB::statement('ALTER TABLE log ALTER COLUMN is_forwarded TYPE integer USING is_forwarded::integer');
        DB::statement('ALTER TABLE log ALTER COLUMN is_forwarded SET DEFAULT 0');
        DB::statement('ALTER TABLE log ALTER COLUMN is_forwarded SET NOT NULL');
        
        DB::statement('ALTER TABLE log ALTER COLUMN is_received TYPE integer USING is_received::integer');
        DB::statement('ALTER TABLE log ALTER COLUMN is_received SET DEFAULT 0');
        DB::statement('ALTER TABLE log ALTER COLUMN is_received SET NOT NULL');
        
        DB::statement('ALTER TABLE log ALTER COLUMN has_feedback_resolved TYPE integer USING has_feedback_resolved::integer');
        DB::statement('ALTER TABLE log ALTER COLUMN has_feedback_resolved SET DEFAULT 0');
        DB::statement('ALTER TABLE log ALTER COLUMN has_feedback_resolved SET NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Kembalikan ke boolean menggunakan SQL langsung
        DB::statement('ALTER TABLE log ALTER COLUMN is_forwarded TYPE boolean USING (is_forwarded::boolean)');
        DB::statement('ALTER TABLE log ALTER COLUMN is_forwarded SET DEFAULT false');
        
        DB::statement('ALTER TABLE log ALTER COLUMN is_received TYPE boolean USING (is_received::boolean)');
        DB::statement('ALTER TABLE log ALTER COLUMN is_received SET DEFAULT false');
        
        DB::statement('ALTER TABLE log ALTER COLUMN has_feedback_resolved TYPE boolean USING (has_feedback_resolved::boolean)');
        DB::statement('ALTER TABLE log ALTER COLUMN has_feedback_resolved SET DEFAULT false');
    }
};