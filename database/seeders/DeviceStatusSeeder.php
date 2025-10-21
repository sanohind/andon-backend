<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DeviceStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('device_status')->insert([
            [
                'device_id' => 'PLC1',
                'device_name' => 'PLC Line 1',
                'status' => 'UNKNOWN',
                'last_seen' => now(),
                'details' => 'Menunggu status pertama...',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'device_id' => 'PLC2',
                'device_name' => 'PLC Line 2',
                'status' => 'UNKNOWN',
                'last_seen' => now(),
                'details' => 'Menunggu status pertama...',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'device_id' => 'NODE_RED_PI',
                'device_name' => 'Node-RED Server (Raspberry Pi)',
                'status' => 'STARTING',
                'last_seen' => now(),
                'details' => 'Node-RED baru saja dimulai.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
