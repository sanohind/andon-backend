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
        Schema::table('inspection_tables', function (Blueprint $table) {
            $table->string('address', 20)->nullable()->after('name');
        });
        
        // Update existing records with address values
        DB::table('inspection_tables')->whereNull('address')->update([
            'address' => DB::raw("'101-' || LPAD(id::text, 2, '0')")
        ]);
        
        // Make address column unique and not null
        Schema::table('inspection_tables', function (Blueprint $table) {
            $table->string('address', 20)->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inspection_tables', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }
};
