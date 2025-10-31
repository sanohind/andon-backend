<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_tables', function (Blueprint $table) {
            $table->decimal('oee', 5, 2)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('inspection_tables', function (Blueprint $table) {
            $table->dropColumn('oee');
        });
    }
};


