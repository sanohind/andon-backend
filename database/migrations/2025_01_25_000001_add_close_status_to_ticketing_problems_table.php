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
        // Untuk PostgreSQL, kita perlu mengubah enum dengan cara khusus
        // Karena enum di PostgreSQL tidak bisa langsung diubah, kita perlu:
        // 1. Menambahkan nilai baru ke enum menggunakan ALTER TYPE
        
        // Cek apakah menggunakan PostgreSQL
        if (DB::getDriverName() === 'pgsql') {
            try {
                // Cek apakah enum type sudah ada
                $enumTypeExists = DB::select("
                    SELECT 1 
                    FROM pg_type 
                    WHERE typname = 'ticketing_problems_status_enum'
                ");
                
                if (!empty($enumTypeExists)) {
                    // Cek apakah nilai 'close' sudah ada di enum
                    $enumExists = DB::select("
                        SELECT 1 
                        FROM pg_enum 
                        WHERE enumlabel = 'close' 
                        AND enumtypid = (
                            SELECT oid 
                            FROM pg_type 
                            WHERE typname = 'ticketing_problems_status_enum'
                        )
                    ");
                    
                    if (empty($enumExists)) {
                        // Tambahkan nilai 'close' ke enum
                        // Catatan: ADD VALUE tidak bisa di-rollback, jadi kita gunakan IF NOT EXISTS pattern
                        DB::statement("DO $$ BEGIN
                            IF NOT EXISTS (
                                SELECT 1 FROM pg_enum 
                                WHERE enumlabel = 'close' 
                                AND enumtypid = (SELECT oid FROM pg_type WHERE typname = 'ticketing_problems_status_enum')
                            ) THEN
                                ALTER TYPE ticketing_problems_status_enum ADD VALUE 'close';
                            END IF;
                        END $$;");
                    }
                }
            } catch (\Exception $e) {
                // Jika error, log dan lanjutkan (mungkin enum sudah ada atau struktur berbeda)
                \Log::warning('Error adding close status to enum: ' . $e->getMessage());
            }
        } else {
            // Untuk MySQL/MariaDB, enum bisa langsung diubah
            try {
                Schema::table('ticketing_problems', function (Blueprint $table) {
                    $table->enum('status', ['open', 'in_progress', 'close', 'completed', 'cancelled'])
                        ->default('open')
                        ->change();
                });
            } catch (\Exception $e) {
                \Log::warning('Error changing enum in MySQL: ' . $e->getMessage());
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Tidak perlu rollback karena 'close' adalah nilai baru yang tidak akan merusak data existing
        // Jika perlu rollback, bisa hapus nilai 'close' dari enum, tapi ini berisiko jika ada data yang menggunakan 'close'
    }
};

