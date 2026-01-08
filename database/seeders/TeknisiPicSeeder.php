<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\TeknisiPic;

class TeknisiPicSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $maintenancePICs = [
            'Wawan Hermawan',
            'Sudarsono',
            'Febrianto',
            'Rizal Bayu S',
            'Roby Eka S',
            'Alvin Sanata A'
        ];

        foreach ($maintenancePICs as $nama) {
            TeknisiPic::create([
                'nama' => $nama,
                'departement' => 'maintenance'
            ]);
        }
    }
}
