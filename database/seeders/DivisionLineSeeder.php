<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Division;
use App\Models\Line;
use Illuminate\Support\Facades\DB;

class DivisionLineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if divisions already exist
        if (Division::count() > 0) {
            $this->command->info('Divisions and lines already exist. Skipping seed.');
            return;
        }

        $mapping = [
            'Brazing' => [
                'Leak Test Inspection',
                'Support',
                'Hand Bending',
                'Welding'
            ],
            'Chassis' => [
                'Cutting',
                'Flaring',
                'MF/TK',
                'LRFD',
                'Assy'
            ],
            'Nylon' => [
                'Injection/Extrude',
                'Roda Dua',
                'Roda Empat'
            ]
        ];

        foreach ($mapping as $divisionName => $lines) {
            $division = Division::create(['name' => $divisionName]);
            
            foreach ($lines as $lineName) {
                Line::create([
                    'division_id' => $division->id,
                    'name' => $lineName
                ]);
            }
        }

        $this->command->info('Divisions and lines seeded successfully.');
    }
}

