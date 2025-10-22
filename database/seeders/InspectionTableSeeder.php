<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\InspectionTable;

class InspectionTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Sample inspection tables data
        $tables = [
            [
                'name' => 'Meja Inspect 1',
                'line_name' => 'Leak Test Inspection',
                'division' => 'Brazing',
                'address' => '101-01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Meja Inspect 2',
                'line_name' => 'Support',
                'division' => 'Brazing',
                'address' => '101-02',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Meja Inspect 3',
                'line_name' => 'Hand Bending',
                'division' => 'Brazing',
                'address' => '101-03',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Meja Inspect 4',
                'line_name' => 'Welding',
                'division' => 'Brazing',
                'address' => '101-04',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Meja Inspect 5',
                'line_name' => 'Cutting',
                'division' => 'Chassis',
                'address' => '102-01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Meja Inspect 6',
                'line_name' => 'Flaring',
                'division' => 'Chassis',
                'address' => '102-02',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Meja Inspect 7',
                'line_name' => 'MF/TK',
                'division' => 'Chassis',
                'address' => '102-03',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Meja Inspect 8',
                'line_name' => 'LRFD',
                'division' => 'Chassis',
                'address' => '102-04',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Meja Inspect 9',
                'line_name' => 'Assy',
                'division' => 'Chassis',
                'address' => '103-01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Meja Inspect 10',
                'line_name' => 'Injection/Extrude',
                'division' => 'Nylon',
                'address' => '103-02',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Meja Inspect 11',
                'line_name' => 'Roda Dua',
                'division' => 'Nylon',
                'address' => '103-03',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Meja Inspect 12',
                'line_name' => 'Roda Empat',
                'division' => 'Nylon',
                'address' => '103-04',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($tables as $table) {
            InspectionTable::create($table);
        }
    }
}
