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
        // Ubah kolom active dari boolean ke integer untuk kompatibilitas dengan Laravel
        DB::statement('ALTER TABLE users ALTER COLUMN active DROP DEFAULT');
        DB::statement('ALTER TABLE users ALTER COLUMN active TYPE integer USING active::integer');
        DB::statement('ALTER TABLE users ALTER COLUMN active SET DEFAULT 1');
        DB::statement('ALTER TABLE users ALTER COLUMN active SET NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Kembalikan ke boolean
        DB::statement('ALTER TABLE users ALTER COLUMN active DROP DEFAULT');
        DB::statement('ALTER TABLE users ALTER COLUMN active TYPE boolean USING (active::boolean)');
        DB::statement('ALTER TABLE users ALTER COLUMN active SET DEFAULT true');
    }
};