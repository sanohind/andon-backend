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
        Schema::table('log', function (Blueprint $table) {
            $table->boolean('is_forwarded')->default(false)->after('status');
            $table->string('forwarded_to_role')->nullable()->after('is_forwarded');
            $table->unsignedBigInteger('forwarded_by_user_id')->nullable()->after('forwarded_to_role');
            $table->timestamp('forwarded_at')->nullable()->after('forwarded_by_user_id');
            $table->text('forward_message')->nullable()->after('forwarded_at');
            
            // Tambahkan foreign key jika diperlukan
            $table->foreign('forwarded_by_user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('log', function (Blueprint $table) {
            $table->dropForeign(['forwarded_by_user_id']);
            $table->dropColumn([
                'is_forwarded',
                'forwarded_to_role', 
                'forwarded_by_user_id',
                'forwarded_at',
                'forward_message'
            ]);
        });
    }
};