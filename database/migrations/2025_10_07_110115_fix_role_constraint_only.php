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
        
        // Update existing users with role 'warehouse' to 'engineering'
        DB::table('users')
            ->where('role', 'warehouse')
            ->update(['role' => 'engineering']);
        
        // Add the new constraint with engineering instead of warehouse
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'leader', 'maintenance', 'quality', 'engineering'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the constraint
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        
        // Update users back to warehouse
        DB::table('users')
            ->where('role', 'engineering')
            ->update(['role' => 'warehouse']);
        
        // Add the old constraint back
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'leader', 'maintenance', 'quality', 'warehouse'))");
    }
};
