<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\EquipmentItem;
use App\Models\InventoryLocation;
use App\Models\Apparatus;
use App\Models\CapitalProject;
use App\Models\Station;
use DB;
use Str;

class ImportMBFDData extends Command
{
    protected $signature = 'mbfd:import {type?}';
    protected $description = 'Import MBFD data from embedded sources';

    public function handle()
    {
        $type = $this->argument('type');

        if (!$type || $type === 'inventory') {
            $this->importInventory();
        }

        if (!$type || $type === 'apparatus') {
            $this->importApparatus();
        }

        if (!$type || $type === 'projects') {
            $this->importCapitalProjects();
        }

        return 0;
    }

    private function importInventory()
    {
        $this->info('Importing Supply Inventory...');
        
        // Supply inventory data
        $inventoryData = $this->getSupplyInventoryData();
        
        DB::transaction(function () use ($inventoryData) {
            foreach ($inventoryData as $row) {
                if (empty($row['equipment_type'])) {
                    continue;
                }

                // Create or get location
                $location = InventoryLocation::firstOrCreate([
                    'location_name' => $row['location'],
                    'shelf' => $row['shelf'],
                    'row' => $row['row']
                ], [
                    'bin' => null,
                    'notes' => null
                ]);

                // Create normalized name
                $normalizedName = strtolower(trim(preg_replace('/[^\w\s]/', '', $row['equipment_type'])));

                // Upsert equipment item
                EquipmentItem::updateOrCreate([
                    'normalized_name' => $normalizedName,
                    'location_id' => $location->id
                ], [
                    'name' => $row['equipment_type'],
                    'category' => $this->categorizeEquipment($row['equipment_type']),
                    'manufacturer' => $row['manufacturer'],
                    'description' => $row['description'],
                    'unit_of_measure' => $this->determineUnitOfMeasure($row['equipment_type']),
                    'reorder_min' => max(1, intval(intval($row['quantity']) * 0.25)),
                    'reorder_max' => intval($row['quantity']),
                    'is_active' => true
                ]);
            }
        });

        $count = EquipmentItem::count();
        $this->info("✓ Imported supply inventory: {$count} items");
    }

    private function importApparatus()
    {
        $this->info('Importing Apparatus Status (Latest Report)...');
        
        // Latest apparatus status data
        $apparatusData = $this->getLatestApparatusData();
        
        DB::transaction(function () use ($apparatusData) {
            // First ensure stations exist
            $this->ensureStations();

            foreach ($apparatusData as $row) {
                if (empty($row['vehicle_no'])) {
                    continue;
                }

                // Parse station from assignment or location
                $stationId = $this->parseStationId($row['assignment']) ?? $this->parseStationId($row['current_location']) ?? 1;

                // Determine status
                $status = match(strtolower($row['status'])) {
                    'in service' => 'Active',
                    'available' => 'Active',
                    'out of service' => 'Out of Service',
                    default => 'Unknown'
                };

                // Upsert apparatus
                Apparatus::updateOrCreate([
                    'vehicle_number' => $row['vehicle_no']
                ], [
                    'name' => $row['designation'] ?: $row['vehicle_no'],
                    'type' => $this->parseApparatusType($row['class']),
                    'unit_id' => $stationId,
                    'slug' => Str::slug($row['designation'] ?: $row['vehicle_no']),
                    'status' => $status,
                    'notes' => $row['notes'] ?: '',
                    'make' => '',
                    'model' => '',
                    'year' => null,
                    'vin' => '',
                    'mileage' => 0,
                    'last_service_date' => null
                ]);
            }
        });

        $count = Apparatus::count();
        $this->info("✓ Imported apparatus: {$count} units");
    }

    private function importCapitalProjects()
    {
        $this->info('Importing Capital Improvement Projects...');
        
        $projectsData = $this->getCapitalProjectsData();
        
        DB::transaction(function () use ($projectsData) {
            foreach ($projectsData as $row) {
                if (empty($row['project_name'])) {
                    continue;
                }

                // Upsert capital project
                CapitalProject::updateOrCreate([
                    'project_number' => $row['project_number']
                ], [
                    'name' => $row['project_name'],
                    'description' => $row['project_name'],
                    'budget' => $row['amount'],
                    'spend' => 0,
                    'status' => 'pending',
                    'start_date' => null,
                    'estimated_completion' => null,
                    'actual_completion' => null,
                    'project_manager' => null,
                    'notes' => null
                ]);
            }
        });

        $count = CapitalProject::count();
        $this->info("✓ Imported capital projects: {$count} projects");
    }

    private function ensureStations()
    {
        $stations = [
            ['station_number' => '1', 'address' => '140 MacArthur Causeway', 'city' => 'Miami Beach', 'state' => 'FL', 'zip_code' => '33139'],
            ['station_number' => '2', 'address' => '1455 West Avenue', 'city' => 'Miami Beach', 'state' => 'FL', 'zip_code' => '33139'],
            ['station_number' => '3', 'address' => '1255 72nd Street', 'city' => 'Miami Beach', 'state' => 'FL', 'zip_code' => '33141'],
            ['station_number' => '4', 'address' => '7300 Harding Avenue', 'city' => 'Miami Beach', 'state' => 'FL', 'zip_code' => '33141'],
        ];

        foreach ($stations as $station) {
            Station::firstOrCreate(
                ['station_number' => $station['station_number']],
                $station
            );
        }
    }

    private function parseStationId($text)
    {
        if (preg_match('/Station\s*(\d)/i', $text, $matches)) {
            return intval($matches[1]);
        }
        return null;
    }

    private function parseApparatusType($class)
    {
        $class = strtoupper(trim($class));
        if (str_contains($class, 'ENGINE')) return 'Engine';
        if (str_contains($class, 'LADDER')) return 'Ladder';
        if (str_contains($class, 'RESCUE')) return 'Rescue';
        if (str_contains($class, 'AIR')) return 'Air Truck';
        return 'Other';
    }

    private function categorizeEquipment($name)
    {
        $name = strtolower($name);
        if (str_contains($name, 'nozzle') || str_contains($name, 'tip')) return 'Nozzles & Tips';
        if (str_contains($name, 'hose') || str_contains($name, 'coupling')) return 'Hose & Fittings';
        if (str_contains($name, 'gasket') || str_contains($name, 'cap')) return 'Fittings & Caps';
        if (str_contains($name, 'adapter') || str_contains($name, 'reducer')) return 'Adapters & Reducers';
        if (str_contains($name, 'tool') || str_contains($name, 'wrench') || str_contains($name, 'hammer')) return 'Hand Tools';
        if (str_contains($name, 'axe') || str_contains($name, 'bar') || str_contains($name, 'crowbar')) return 'Forcible Entry Tools';
        if (str_contains($name, 'battery')) return 'Batteries';
        if (str_contains($name, 'ppe') || str_contains($name, 'glove')) return 'PPE';
        return 'General Equipment';
    }

    private function determineUnitOfMeasure($name)
    {
        $name = strtolower($name);
        if (str_contains($name, 'box') || str_contains($name, ' bag')) return 'box';
        if (str_contains($name, 'batteries')) return 'pack';
        return 'each';
    }

    // EMBEDDED DATA
    
    private function getSupplyInventoryData()
    {
        return [
            ['shelf' => 'A', 'row' => 1, 'equipment_type' => 'Mounts', 'quantity' => 2, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 1, 'equipment_type' => 'Aerial Master Stream Tips', 'quantity' => 4, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 1, 'equipment_type' => 'Stream Straightener', 'quantity' => 1, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 1, 'equipment_type' => 'Nozzle Teeth Packs', 'quantity' => 17, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 2, 'equipment_type' => 'Stortz Caps', 'quantity' => 6, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 2, 'equipment_type' => '4" Cap', 'quantity' => 1, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 2, 'equipment_type' => '5" Caps', 'quantity' => 4, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 2, 'equipment_type' => '6" Caps', 'quantity' => 8, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 2, 'equipment_type' => '6" Gaskets', 'quantity' => 4, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 2, 'equipment_type' => '5" Gaskets', 'quantity' => 16, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 2, 'equipment_type' => '5" Suction Gaskets', 'quantity' => 10, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 2, 'equipment_type' => '2 1/2" Gaskets', 'quantity' => 18, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 2, 'equipment_type' => '1 1/2" Gaskets', 'quantity' => 9, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 2, 'equipment_type' => 'Misc. Gaskets', 'quantity' => 4, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 3, 'equipment_type' => '6" to 4" Reducers', 'quantity' => 4, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 3, 'equipment_type' => '6" to 2" Reducer', 'quantity' => 1, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 3, 'equipment_type' => '4" to 2 1/2" Reducers', 'quantity' => 2, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 3, 'equipment_type' => 'Stortz Connection with 4" Male', 'quantity' => 6, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 3, 'equipment_type' => 'Stortz Connection with 5" Male', 'quantity' => 4, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 3, 'equipment_type' => 'Stortz Connection with 6" Male', 'quantity' => 1, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 3, 'equipment_type' => 'Stortz Connection with 6" Female', 'quantity' => 2, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 3, 'equipment_type' => '5" to 4" Reducers', 'quantity' => 3, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 3, 'equipment_type' => '4 1/2" Adapter', 'quantity' => 1, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 3, 'equipment_type' => 'Stortz Connection with 4" Female', 'quantity' => 1, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 4, 'equipment_type' => 'Hydrant Assist Valve', 'quantity' => 1, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 4, 'equipment_type' => 'Intake', 'quantity' => 1, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 4, 'equipment_type' => 'Stortz Elbow to 4" Female', 'quantity' => 5, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'A', 'row' => 4, 'equipment_type' => 'Misc. Adapters', 'quantity' => 2, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'B', 'row' => 1, 'equipment_type' => 'Foam Boot', 'quantity' => 8, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'B', 'row' => 2, 'equipment_type' => '75psi 175gpm Fog Tips', 'quantity' => 10, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'B', 'row' => 2, 'equipment_type' => '100psi 325gpm Fog Tips', 'quantity' => 1, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'B', 'row' => 2, 'equipment_type' => '75psi 200gpm Fog Tips', 'quantity' => 1, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'B', 'row' => 2, 'equipment_type' => 'Selectomatic Nozzle Tip', 'quantity' => 1, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'B', 'row' => 2, 'equipment_type' => 'Other Fog Tips', 'quantity' => 5, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'B', 'row' => 2, 'equipment_type' => 'Glow in the Dark Stream Adjusters', 'quantity' => 2, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'B', 'row' => 2, 'equipment_type' => 'Bag of Brass Set Screws', 'quantity' => 1, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'B', 'row' => 2, 'equipment_type' => 'Red Box Misc.', 'quantity' => 1, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'B', 'row' => 2, 'equipment_type' => 'Appliance Mounts', 'quantity' => 9, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'B', 'row' => 3, 'equipment_type' => 'Handle Playpipes', 'quantity' => 3, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'B', 'row' => 3, 'equipment_type' => 'Incline Gates', 'quantity' => 3, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'B', 'row' => 3, 'equipment_type' => '1" Breakaways Bails', 'quantity' => 6, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'B', 'row' => 3, 'equipment_type' => '1 1/2" Breakaway Bails', 'quantity' => 6, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'B', 'row' => 4, 'equipment_type' => 'Water Thiefs', 'quantity' => 6, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'B', 'row' => 4, 'equipment_type' => 'Ground Y Supply', 'quantity' => 3, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'B', 'row' => 4, 'equipment_type' => 'Ground Supply', 'quantity' => 3, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'C', 'row' => 1, 'equipment_type' => 'Blitzfire', 'quantity' => 1, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'C', 'row' => 1, 'equipment_type' => 'Strainers', 'quantity' => 3, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'C', 'row' => 1, 'equipment_type' => 'Hose Edge Protectors', 'quantity' => 3, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'F', 'row' => 3, 'equipment_type' => 'Pick Headed Axe', 'quantity' => 6, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'F', 'row' => 3, 'equipment_type' => 'Flat Headed Axe', 'quantity' => 5, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
            ['shelf' => 'F', 'row' => 3, 'equipment_type' => 'Sledge Hammer', 'quantity' => 6, 'manufacturer' => null, 'location' => 'Supply Closet', 'description' => null],
        ];
    }

    private function getLatestApparatusData()
    {
        // Latest report: 1-23-2026
        return [
            ['vehicle_no' => '002-16', 'designation' => 'E 21', 'assignment' => 'Reserve', 'current_location' => 'Fire Fleet', 'status' => 'Available', 'notes' => '', 'class' => 'ENGINE'],
            ['vehicle_no' => '002-14', 'designation' => 'E 11', 'assignment' => 'Reserve', 'current_location' => 'Station 2', 'status' => 'Out of Service', 'notes' => 'Out of service for Fuel leak', 'class' => 'ENGINE'],
            ['vehicle_no' => '002-10', 'designation' => 'E 31', 'assignment' => 'Reserve', 'current_location' => 'Station 2', 'status' => 'Available', 'notes' => '', 'class' => 'ENGINE'],
            ['vehicle_no' => '1033', 'designation' => '', 'assignment' => 'Reserve', 'current_location' => 'Station 2', 'status' => 'Out of Service', 'notes' => 'Radiator failed after overheat', 'class' => 'RESCUE'],
            ['vehicle_no' => '1034', 'designation' => '', 'assignment' => 'Reserve', 'current_location' => 'Station 2', 'status' => 'In Service', 'notes' => 'In service Sunday for event', 'class' => 'RESCUE'],
            ['vehicle_no' => '1035', 'designation' => '', 'assignment' => 'Reserve', 'current_location' => 'Station 1', 'status' => 'Available', 'notes' => '', 'class' => 'RESCUE'],
            ['vehicle_no' => '1036', 'designation' => '', 'assignment' => 'Reserve', 'current_location' => 'Station 2', 'status' => 'In Service', 'notes' => 'In service Sunday for event', 'class' => 'RESCUE'],
            ['vehicle_no' => '14500', 'designation' => '', 'assignment' => 'Reserve', 'current_location' => 'Station 2', 'status' => 'Available', 'notes' => '', 'class' => 'RESCUE'],
            ['vehicle_no' => '002-6', 'designation' => 'L 11', 'assignment' => 'Reserve', 'current_location' => 'Station 1', 'status' => 'In Service', 'notes' => 'In service as L1 now', 'class' => 'LADDER'],
            ['vehicle_no' => '14501', 'designation' => '', 'assignment' => 'Reserve', 'current_location' => 'Station 4', 'status' => 'In Service', 'notes' => 'In service as rescue 4', 'class' => 'RESCUE'],
            ['vehicle_no' => '20503', 'designation' => 'E 1', 'assignment' => 'Station 1', 'current_location' => 'Station 1', 'status' => 'In Service', 'notes' => '', 'class' => 'ENGINE'],
            ['vehicle_no' => '002-12', 'designation' => 'L 1', 'assignment' => 'Station 1', 'current_location' => 'Fire Fleet', 'status' => 'Out of Service', 'notes' => 'Back from expert. Can be used as spare. Needs one more repair', 'class' => 'LADDER'],
            ['vehicle_no' => '16508', 'designation' => 'R 1', 'assignment' => 'Station 1', 'current_location' => 'Station 1', 'status' => 'In Service', 'notes' => '', 'class' => 'RESCUE'],
            ['vehicle_no' => '19502', 'designation' => 'R 11', 'assignment' => 'Station 1', 'current_location' => 'Station 1', 'status' => 'In Service', 'notes' => '', 'class' => 'RESCUE'],
            ['vehicle_no' => '002-20', 'designation' => 'A 1', 'assignment' => 'Station 2', 'current_location' => 'Station 2', 'status' => 'In Service', 'notes' => '', 'class' => 'AIR TRUCK'],
            ['vehicle_no' => '18500', 'designation' => 'A 2', 'assignment' => 'Station 2', 'current_location' => 'Station 2', 'status' => 'In Service', 'notes' => '', 'class' => 'AIR TRUCK'],
            ['vehicle_no' => '24509', 'designation' => 'E 2', 'assignment' => 'Station 2', 'current_location' => 'Station 2', 'status' => 'In Service', 'notes' => '', 'class' => 'ENGINE'],
            ['vehicle_no' => '16507', 'designation' => 'R 2', 'assignment' => 'Station 2', 'current_location' => 'Station 2', 'status' => 'In Service', 'notes' => '', 'class' => 'RESCUE'],
            ['vehicle_no' => '19503', 'designation' => 'R 22', 'assignment' => 'Station 2', 'current_location' => 'Station 2', 'status' => 'In Service', 'notes' => '', 'class' => 'RESCUE'],
            ['vehicle_no' => '002-22', 'designation' => 'E 3', 'assignment' => 'Station 3', 'current_location' => 'Station 3', 'status' => 'In Service', 'notes' => '', 'class' => 'ENGINE'],
            ['vehicle_no' => '17505', 'designation' => 'L 3', 'assignment' => 'Station 3', 'current_location' => 'Station 3', 'status' => 'In Service', 'notes' => '', 'class' => 'LADDER'],
            ['vehicle_no' => '17501', 'designation' => 'R 3', 'assignment' => 'Station 3', 'current_location' => 'Station 3', 'status' => 'In Service', 'notes' => '', 'class' => 'RESCUE'],
            ['vehicle_no' => '17503', 'designation' => 'R 44', 'assignment' => 'Station 4', 'current_location' => 'Station 4', 'status' => 'In Service', 'notes' => '', 'class' => 'RESCUE'],
            ['vehicle_no' => '20504', 'designation' => 'E 4', 'assignment' => 'Station 4', 'current_location' => 'Station 4', 'status' => 'In Service', 'notes' => '', 'class' => 'ENGINE'],
            ['vehicle_no' => '17502', 'designation' => 'R 4', 'assignment' => 'Station 4', 'current_location' => 'Station 4', 'status' => 'In Service', 'notes' => 'In service Sunday', 'class' => 'RESCUE'],
        ];
    }

    private function getCapitalProjectsData()
    {
        return [
            ['project_number' => '66727', 'project_name' => 'FIRE STATION #4 – REPL. EXHAUST SYS', 'amount' => 22946],
            ['project_number' => '67927', 'project_name' => 'FIRE STATION #1 – REPL. EXHAUST SYS', 'amount' => 285000],
            ['project_number' => '63631', 'project_name' => 'FIRE STATION #2 – RESTROOM/PLUMBING', 'amount' => 255000],
            ['project_number' => '63731', 'project_name' => 'FIRE STATION #4 – ROOF REPLACEMENT', 'amount' => 357000],
            ['project_number' => '65127', 'project_name' => 'FIRE STATION #2 – REPL. EXHAUST SYS', 'amount' => 200000],
            ['project_number' => '66527', 'project_name' => 'FIRE STATION #3 – REPL. EXHAUST SYS', 'amount' => 228000],
            ['project_number' => '60626', 'project_name' => 'FIRE STATION #2 – VEHICLE AWNING REPL', 'amount' => 237357],
        ];
    }
}
