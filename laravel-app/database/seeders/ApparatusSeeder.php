<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Apparatus;

class ApparatusSeeder extends Seeder
{
    /**
     * Run the database seeds - Data from Apparatus Status Report 11/21/25
     */
    public function run(): void
    {
        $apparatuses = [
            // Reserve Engines
            ['unit_id' => 'E 21', 'make' => 'Pierce', 'model' => 'Reserve Engine', 'year' => 2002, 'status' => 'In Service', 'notes' => 'Reserve - Vehicle No: 002-16'],
            ['unit_id' => 'E 11', 'make' => 'Pierce', 'model' => 'Reserve Engine', 'year' => 2002, 'status' => 'In Service', 'notes' => 'Reserve - Vehicle No: 002-14'],
            ['unit_id' => 'E 31', 'make' => 'Pierce', 'model' => 'Reserve Engine', 'year' => 2002, 'status' => 'In Service', 'notes' => 'Reserve - Vehicle No: 002-10'],

            // Reserve Rescue
            ['unit_id' => '1033', 'make' => 'Ford', 'model' => 'Rescue', 'year' => 2010, 'status' => 'In Service', 'notes' => 'Reserve - Station 1'],
            ['unit_id' => '1034', 'make' => 'Ford', 'model' => 'Rescue', 'year' => 2010, 'status' => 'In Service', 'notes' => 'Reserve - Station 2'],
            ['unit_id' => '1035', 'make' => 'Ford', 'model' => 'Rescue', 'year' => 2010, 'status' => 'In Service', 'notes' => 'Reserve - In service as R1'],
            ['unit_id' => '1036', 'make' => 'Ford', 'model' => 'Rescue', 'year' => 2010, 'status' => 'In Service', 'notes' => 'Reserve - Station 2'],
            ['unit_id' => '14500', 'make' => 'Ford', 'model' => 'Rescue', 'year' => 2014, 'status' => 'In Service', 'notes' => 'Reserve - LAST OUT RESERVE'],
            ['unit_id' => '14501', 'make' => 'Ford', 'model' => 'Rescue', 'year' => 2014, 'status' => 'In Service', 'notes' => 'Reserve - Station 3'],

            // Reserve Ladder
            ['unit_id' => 'L 11', 'make' => 'Pierce', 'model' => 'Ladder', 'year' => 2002, 'status' => 'In Service', 'notes' => 'Reserve - Vehicle No: 002-6 - In service as L1'],

            // Front Line - Station 1
            ['unit_id' => 'E 1', 'make' => 'Pierce', 'model' => 'Engine', 'year' => 2020, 'status' => 'In Service', 'notes' => 'Station 1 - Vehicle No: 20503'],
            ['unit_id' => 'L 1', 'make' => 'Pierce', 'model' => 'Ladder', 'year' => 2002, 'status' => 'Out of Service', 'notes' => 'Station 1 - Vehicle No: 002-12 - Engine is damaged from the overheating'],
            ['unit_id' => 'R 1', 'make' => 'Ford', 'model' => 'Rescue', 'year' => 2016, 'status' => 'Out of Service', 'notes' => 'Station 1 - Vehicle No: 16508 - Rear AC failure / Subfloor repairs'],
            ['unit_id' => 'R 11', 'make' => 'Ford', 'model' => 'Rescue', 'year' => 2019, 'status' => 'In Service', 'notes' => 'Station 1 - Vehicle No: 19502'],

            // Front Line - Station 2
            ['unit_id' => 'A 1', 'make' => 'Pierce', 'model' => 'Air Truck', 'year' => 2002, 'status' => 'In Service', 'notes' => 'Station 2 - Vehicle No: 002-20'],
            ['unit_id' => 'A 2', 'make' => 'Pierce', 'model' => 'Air Truck', 'year' => 2018, 'status' => 'In Service', 'notes' => 'Station 2 - Vehicle No: 18500'],
            ['unit_id' => 'E 2', 'make' => 'Pierce', 'model' => 'Engine', 'year' => 2024, 'status' => 'In Service', 'notes' => 'Station 2 - Vehicle No: 24509'],
            ['unit_id' => 'R 2', 'make' => 'Ford', 'model' => 'Rescue', 'year' => 2016, 'status' => 'In Service', 'notes' => 'Station 2 - Vehicle No: 16507'],
            ['unit_id' => 'R 22', 'make' => 'Ford', 'model' => 'Rescue', 'year' => 2019, 'status' => 'In Service', 'notes' => 'Station 2 - Vehicle No: 19503'],

            // Front Line - Station 3
            ['unit_id' => 'E 3', 'make' => 'Pierce', 'model' => 'Engine', 'year' => 2002, 'status' => 'In Service', 'notes' => 'Station 3 - Vehicle No: 002-22 - Pump has been re ordered'],
            ['unit_id' => 'L 3', 'make' => 'Pierce', 'model' => 'Ladder', 'year' => 2017, 'status' => 'In Service', 'notes' => 'Station 3 - Vehicle No: 17505'],
            ['unit_id' => 'R 3', 'make' => 'Ford', 'model' => 'Rescue', 'year' => 2017, 'status' => 'In Service', 'notes' => 'Station 3 - Vehicle No: 17501'],

            // Front Line - Station 4
            ['unit_id' => 'R 44', 'make' => 'Ford', 'model' => 'Rescue', 'year' => 2017, 'status' => 'In Service', 'notes' => 'Station 4 - Vehicle No: 17503'],
            ['unit_id' => 'E 4', 'make' => 'Pierce', 'model' => 'Engine', 'year' => 2020, 'status' => 'In Service', 'notes' => 'Station 4 - Vehicle No: 20504'],
            ['unit_id' => 'R 4', 'make' => 'Ford', 'model' => 'Rescue', 'year' => 2017, 'status' => 'In Service', 'notes' => 'Station 4 - Vehicle No: 17502'],
        ];

        foreach ($apparatuses as $data) {
            Apparatus::updateOrCreate(
                ['unit_id' => $data['unit_id']],
                $data
            );
        }
    }
}