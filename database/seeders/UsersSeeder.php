<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class UsersSeeder extends Seeder
{
    /**
     * Seed default users untuk akses dashboard
     */
    public function run()
    {
        // Default users yang bisa login ke dashboard
        $defaultUsers = [
            [
                'username' => 'admin',
                'name' => 'Administrator',
                'password' => Hash::make('Sanoh!nd'), // GANTI PASSWORD INI!
                'role' => 'admin',
                'active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ],
            [
                'username' => 'leader',
                'name' => 'Leader',
                'password' => Hash::make('blackbox'), // GANTI PASSWORD INI!
                'role' => 'leader',
                'active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ],
            [
                'username' => 'maintenance',
                'name' => 'Maintenance',
                'password' => Hash::make('blackbox'), // GANTI PASSWORD INI!
                'role' => 'maintenance',
                'active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ],
            [
                'username' => 'quality',
                'name' => 'Quality Control',
                'password' => Hash::make('blackbox'), // GANTI PASSWORD INI!
                'role' => 'quality',
                'active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ],
            [
                'username' => 'warehouse',
                'name' => 'Warehouse',
                'password' => Hash::make('blackbox'), // GANTI PASSWORD INI!
                'role' => 'warehouse',
                'active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ]
        ];

        // Insert users ke database
        foreach ($defaultUsers as $user) {
            DB::table('users')->updateOrInsert(
                ['username' => $user['username']],
                $user
            );
        }

        $this->command->info('✅ Default users berhasil ditambahkan ke database.');
    }
}