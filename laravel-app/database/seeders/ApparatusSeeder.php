<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Apparatus;

class ApparatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $apparatuses = [
            [
                'name' => 'Engine 1',
                'type' => 'Engine',
                'vehicle_number' => 'E1',
                'slug' => 'engine-1',
            ],
            [
                'name' => 'Ladder 1',
                'type' => 'Ladder1',
                'vehicle_number' => 'L1',
                'slug' => 'ladder-1',
            ],
            [
                'name' => 'Ladder 3',
                'type' => 'Ladder3',
                'vehicle_number' => 'L3',
                'slug' => 'ladder-3',
            ],
            [
                'name' => 'Rescue 1',
                'type' => 'Rescue',
                'vehicle_number' => 'R1',
                'slug' => 'rescue-1',
            ],
            [
                'name' => 'Rope 1',
                'type' => 'Rope',
                'vehicle_number' => 'RP1',
                'slug' => 'rope-1',
            ],
        ];

        foreach ($apparatuses as $apparatus) {
            Apparatus::create($apparatus);
        }
    }
}