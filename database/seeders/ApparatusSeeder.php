<?php

namespace Database\Seeders;

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
            ['vehicle_number' => 'E1', 'designation' => 'Engine 1', 'assignment' => 'FS 1', 'current_location' => 'FS 1', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Engines'],
            ['vehicle_number' => 'E2', 'designation' => 'Engine 2', 'assignment' => 'FS 2', 'current_location' => 'FS 2', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Engines'],
            ['vehicle_number' => 'E3', 'designation' => 'Engine 3', 'assignment' => 'FS 3', 'current_location' => 'FS 3', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Engines'],
            ['vehicle_number' => 'E4', 'designation' => 'Engine 4', 'assignment' => 'FS 4', 'current_location' => 'FS 4', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Engines'],
            ['vehicle_number' => 'L1', 'designation' => 'Ladder 1', 'assignment' => 'FS 1', 'current_location' => 'FS 1', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Ladders'],
            ['vehicle_number' => 'L2', 'designation' => 'Ladder 2', 'assignment' => 'FS 2', 'current_location' => 'FS 2', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Ladders'],
            ['vehicle_number' => 'L3', 'designation' => 'Ladder 3', 'assignment' => 'FS 3', 'current_location' => 'FS 3', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Ladders'],
            ['vehicle_number' => 'L4', 'designation' => 'Ladder 4', 'assignment' => 'FS 4', 'current_location' => 'FS 4', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Ladders'],
            ['vehicle_number' => 'R1', 'designation' => 'Rescue 1', 'assignment' => 'FS 1', 'current_location' => 'FS 1', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Rescues'],
            ['vehicle_number' => 'R2', 'designation' => 'Rescue 2', 'assignment' => 'FS 2', 'current_location' => 'FS 2', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Rescues'],
            ['vehicle_number' => 'R3', 'designation' => 'Rescue 3', 'assignment' => 'FS 3', 'current_location' => 'FS 3', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Rescues'],
            ['vehicle_number' => 'R4', 'designation' => 'Rescue 4', 'assignment' => 'FS 4', 'current_location' => 'FS 4', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Rescues'],
            ['vehicle_number' => 'SQ1', 'designation' => 'Squad 1', 'assignment' => 'FS 1', 'current_location' => 'FS 1', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Squads'],
            ['vehicle_number' => 'SQ2', 'designation' => 'Squad 2', 'assignment' => 'FS 2', 'current_location' => 'FS 2', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Squads'],
            ['vehicle_number' => 'T1', 'designation' => 'Tanker 1', 'assignment' => 'FS 1', 'current_location' => 'FS 1', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Tankers'],
            ['vehicle_number' => 'T2', 'designation' => 'Tanker 2', 'assignment' => 'FS 3', 'current_location' => 'FS 3', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Tankers'],
            ['vehicle_number' => 'B1', 'designation' => 'Brush 1', 'assignment' => 'FS 2', 'current_location' => 'FS 2', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Brush Trucks'],
            ['vehicle_number' => 'B2', 'designation' => 'Brush 2', 'assignment' => 'FS 4', 'current_location' => 'FS 4', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Brush Trucks'],
            ['vehicle_number' => 'M1', 'designation' => 'Marine 1', 'assignment' => 'FS 2', 'current_location' => 'FS 2', 'status' => 'Active', 'notes' => null, 'class_description' => 'Fire Boats'],
            ['vehicle_number' => 'BC1', 'designation' => 'Battalion Chief 1', 'assignment' => 'FS 1', 'current_location' => 'FS 1', 'status' => 'Active', 'notes' => null, 'class_description' => 'Command Vehicles'],
            ['vehicle_number' => 'BC2', 'designation' => 'Battalion Chief 2', 'assignment' => 'FS 3', 'current_location' => 'FS 3', 'status' => 'Active', 'notes' => null, 'class_description' => 'Command Vehicles'],
            ['vehicle_number' => 'U1', 'designation' => 'Utility 1', 'assignment' => 'FS 1', 'current_location' => 'FS 1', 'status' => 'Active', 'notes' => null, 'class_description' => 'Utility Vehicles'],
            ['vehicle_number' => 'U2', 'designation' => 'Utility 2', 'assignment' => 'FS 3', 'current_location' => 'FS 3', 'status' => 'Active', 'notes' => null, 'class_description' => 'Utility Vehicles'],
            ['vehicle_number' => 'AIR1', 'designation' => 'Air Unit 1', 'assignment' => 'FS 2', 'current_location' => 'FS 2', 'status' => 'Active', 'notes' => null, 'class_description' => 'Air/Light Units'],
        ];

        foreach ($apparatuses as $data) {
            Apparatus::updateOrCreate(
                ['vehicle_number' => $data['vehicle_number']],
                array_merge($data, [
                    'name' => $data['designation'],
                    'type' => $this->getTypeFromDesignation($data['designation']),
                    'slug' => strtolower(str_replace(' ', '-', $data['designation'])),
                ])
            );
        }
    }

    private function getTypeFromDesignation(string $designation): string
    {
        if (str_starts_with($designation, 'Engine')) return 'Engine';
        if (str_starts_with($designation, 'Ladder')) return 'Ladder';
        if (str_starts_with($designation, 'Rescue')) return 'Rescue';
        if (str_starts_with($designation, 'Squad')) return 'Squad';
        if (str_starts_with($designation, 'Tanker')) return 'Tanker';
        if (str_starts_with($designation, 'Brush')) return 'Brush';
        if (str_starts_with($designation, 'Marine')) return 'Marine';
        if (str_starts_with($designation, 'Battalion')) return 'Command';
        if (str_starts_with($designation, 'Utility')) return 'Utility';
        if (str_starts_with($designation, 'Air')) return 'Air';
        return 'Other';
    }
}