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
        Schema::table('log', function (Blueprint $table) {
            // Tambahkan kolom receive jika belum ada
            if (!Schema::hasColumn('log', 'is_received')) {
                $table->boolean('is_received')->default(false)->after('forward_message');
            }
            if (!Schema::hasColumn('log', 'received_by_user_id')) {
                $table->unsignedBigInteger('received_by_user_id')->nullable()->after('is_received');
            }
            if (!Schema::hasColumn('log', 'received_at')) {
                $table->timestamp('received_at')->nullable()->after('received_by_user_id');
            }
            
            // Tambahkan kolom feedback resolved jika belum ada
            if (!Schema::hasColumn('log', 'has_feedback_resolved')) {
                $table->boolean('has_feedback_resolved')->default(false)->after('received_at');
            }
            if (!Schema::hasColumn('log', 'feedback_resolved_by_user_id')) {
                $table->unsignedBigInteger('feedback_resolved_by_user_id')->nullable()->after('has_feedback_resolved');
            }
            if (!Schema::hasColumn('log', 'feedback_resolved_at')) {
                $table->timestamp('feedback_resolved_at')->nullable()->after('feedback_resolved_by_user_id');
            }
            if (!Schema::hasColumn('log', 'feedback_message')) {
                $table->text('feedback_message')->nullable()->after('feedback_resolved_at');
            }
        });
        
        // Tambahkan foreign keys jika belum ada
        Schema::table('log', function (Blueprint $table) {
            // Check if foreign key exists before adding
            $foreignKeys = DB::select("
                SELECT constraint_name 
                FROM information_schema.table_constraints 
                WHERE table_name = 'log' 
                AND constraint_type = 'FOREIGN KEY'
                AND constraint_name LIKE '%received_by_user_id%'
            ");
            
            if (empty($foreignKeys)) {
                $table->foreign('received_by_user_id')->references('id')->on('users')->onDelete('set null');
            }
            
            $foreignKeys = DB::select("
                SELECT constraint_name 
                FROM information_schema.table_constraints 
                WHERE table_name = 'log' 
                AND constraint_type = 'FOREIGN KEY'
                AND constraint_name LIKE '%feedback_resolved_by_user_id%'
            ");
            
            if (empty($foreignKeys)) {
                $table->foreign('feedback_resolved_by_user_id')->references('id')->on('users')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('log', function (Blueprint $table) {
            // Drop foreign keys first - check if they exist
            $foreignKeys = DB::select("
                SELECT constraint_name 
                FROM information_schema.table_constraints 
                WHERE table_name = 'log' 
                AND constraint_type = 'FOREIGN KEY'
                AND constraint_name LIKE '%received_by_user_id%'
            ");
            
            if (!empty($foreignKeys)) {
                $table->dropForeign(['received_by_user_id']);
            }
            
            $foreignKeys = DB::select("
                SELECT constraint_name 
                FROM information_schema.table_constraints 
                WHERE table_name = 'log' 
                AND constraint_type = 'FOREIGN KEY'
                AND constraint_name LIKE '%feedback_resolved_by_user_id%'
            ");
            
            if (!empty($foreignKeys)) {
                $table->dropForeign(['feedback_resolved_by_user_id']);
            }
            
            // Drop columns if they exist
            $columnsToDrop = [
                'is_received',
                'received_by_user_id',
                'received_at',
                'has_feedback_resolved',
                'feedback_resolved_by_user_id',
                'feedback_resolved_at',
                'feedback_message'
            ];
            
            foreach ($columnsToDrop as $column) {
                if (Schema::hasColumn('log', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
