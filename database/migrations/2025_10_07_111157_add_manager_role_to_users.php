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
        // Drop the existing constraint
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        
        // Add the new constraint with manager role
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'manager', 'leader', 'maintenance', 'quality', 'engineering'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the constraint
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        
        // Add the old constraint back without manager
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'leader', 'maintenance', 'quality', 'engineering'))");
    }
};
