<?php

namespace Database\Seeders;

use App\Models\Apparatus;
use App\Models\EquipmentItem;
use App\Models\InventoryLocation;
use App\Models\Station;
use Appstract\Stock\StockMutation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MBFD Data Update Seeder
 * 
 * Updates stations, apparatus, and inventory data from January 2026 data files.
 * Run with: php artisan db:seed --class=MBFDDataUpdateSeeder
 */
class MBFDDataUpdateSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Starting MBFD Data Update...');
        
        DB::beginTransaction();
        
        try {
            $this->updateStations();
            $this->updateApparatuses();
            $this->updateInventory();
            
            DB::commit();
            $this->command->info('✅ MBFD Data Update completed successfully!');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('❌ Error updating data: ' . $e->getMessage());
            Log::error('MBFDDataUpdateSeeder failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update station addresses from screenshot data
     */
    private function updateStations(): void
    {
        $this->command->info('Updating stations...');
        
        $stations = [
            [
                'station_number' => 1,
                'address' => '2300 Pine Tree Dr.',
                'city' => 'Miami Beach',
                'state' => 'FL',
                'zip_code' => '33140',
                'phone' => '(305) 673-7110',
            ],
            [
                'station_number' => 2,
                'address' => '5303 Collins Ave.',
                'city' => 'Miami Beach',
                'state' => 'FL',
                'zip_code' => '33140',
                'phone' => '(305) 673-7110',
            ],
            [
                'station_number' => 3,
                'address' => '1301 - 14 Place',
                'city' => 'Miami Beach',
                'state' => 'FL',
                'zip_code' => '33139',
                'phone' => '(305) 673-7110',
            ],
            [
                'station_number' => 4,
                'address' => '6565 Collins Ave.',
                'city' => 'Miami Beach',
                'state' => 'FL',
                'zip_code' => '33141',
                'phone' => '(305) 673-7110',
            ],
        ];

        foreach ($stations as $data) {
            Station::updateOrCreate(
                ['station_number' => $data['station_number']],
                $data
            );
        }
        
        $this->command->info('  ✓ Updated ' . count($stations) . ' stations');
    }

    /**
     * Update apparatus data from Excel report (1-23-2026)
     */
    private function updateApparatuses(): void
    {
        $this->command->info('Updating apparatus...');
        
        // Data from Apparatus Status Report 1-23-2026
        $apparatuses = [
            ['vehicle_number' => '011', 'designation' => 'R1', 'assignment' => 'Rescue 1', 'location' => 'Station 1', 'status' => 'In Service'],
            ['vehicle_number' => '013', 'designation' => 'E3', 'assignment' => 'Engine 3', 'location' => 'Station 3', 'status' => 'In Service'],
            ['vehicle_number' => '015', 'designation' => 'T1', 'assignment' => 'Truck 1', 'location' => 'Station 2', 'status' => 'In Service'],
            ['vehicle_number' => '017', 'designation' => 'E4', 'assignment' => 'Engine 4', 'location' => 'Station 4', 'status' => 'In Service'],
            ['vehicle_number' => '018', 'designation' => 'E1', 'assignment' => 'Engine 1', 'location' => 'Station 1', 'status' => 'In Service'],
            ['vehicle_number' => '019', 'designation' => 'R2', 'assignment' => 'Rescue 2', 'location' => 'Station 2', 'status' => 'In Service'],
            ['vehicle_number' => '020', 'designation' => 'L3', 'assignment' => 'Ladder 3', 'location' => 'Station 3', 'status' => 'In Service'],
            ['vehicle_number' => '021', 'designation' => 'E2', 'assignment' => 'Engine 2', 'location' => 'Station 2', 'status' => 'In Service'],
            ['vehicle_number' => '022', 'designation' => 'T4', 'assignment' => 'Truck 4', 'location' => 'Station 4', 'status' => 'In Service'],
            ['vehicle_number' => '023', 'designation' => 'USAR7', 'assignment' => 'USAR 7', 'location' => 'Station 2', 'status' => 'In Service'],
            ['vehicle_number' => '024', 'designation' => 'HM7', 'assignment' => 'Hazmat 7', 'location' => 'Station 3', 'status' => 'In Service'],
            ['vehicle_number' => '025', 'designation' => 'MB7', 'assignment' => 'Fireboat', 'location' => 'Station 1', 'status' => 'In Service'],
            ['vehicle_number' => '026', 'designation' => 'ATV7', 'assignment' => 'ATV Unit', 'location' => 'Station 1', 'status' => 'Available'],
            ['vehicle_number' => '027', 'designation' => 'RB7', 'assignment' => 'Rescue Boat', 'location' => 'Station 1', 'status' => 'Available'],
            ['vehicle_number' => '028', 'designation' => 'BC1', 'assignment' => 'Battalion Chief 1', 'location' => 'Station 1', 'status' => 'In Service'],
            ['vehicle_number' => '029', 'designation' => 'DC1', 'assignment' => 'Division Chief', 'location' => 'Station 1', 'status' => 'In Service'],
            ['vehicle_number' => '006', 'designation' => 'SR1', 'assignment' => 'Spare Rescue 1', 'location' => 'Station 1', 'status' => 'Available', 'notes' => 'Reserve unit'],
            ['vehicle_number' => '007', 'designation' => 'SE1', 'assignment' => 'Spare Engine 1', 'location' => 'Station 2', 'status' => 'Available', 'notes' => 'Reserve unit'],
            ['vehicle_number' => '008', 'designation' => 'SE2', 'assignment' => 'Spare Engine 2', 'location' => 'Station 3', 'status' => 'Out of Service', 'notes' => 'Mechanical issues'],
            ['vehicle_number' => '009', 'designation' => 'SL1', 'assignment' => 'Spare Ladder 1', 'location' => 'Station 4', 'status' => 'Available', 'notes' => 'Reserve unit'],
            ['vehicle_number' => '030', 'designation' => 'AIR7', 'assignment' => 'Air Unit', 'location' => 'Station 2', 'status' => 'In Service'],
            ['vehicle_number' => '031', 'designation' => 'REHAB7', 'assignment' => 'Rehab Unit', 'location' => 'Station 3', 'status' => 'Available'],
            ['vehicle_number' => '032', 'designation' => 'FOAM7', 'assignment' => 'Foam Unit', 'location' => 'Station 4', 'status' => 'Available'],
            ['vehicle_number' => '033', 'designation' => 'LIGHT7', 'assignment' => 'Light Tower', 'location' => 'Station 1', 'status' => 'Available'],
        ];

        $statusMap = [
            'In Service' => 'active',
            'Available' => 'available',
            'Out of Service' => 'out_of_service',
        ];

        $updated = 0;
        $created = 0;

        foreach ($apparatuses as $data) {
            // Extract station number from location
            preg_match('/Station (\d+)/', $data['location'], $matches);
            $stationNumber = $matches[1] ?? null;
            $station = $stationNumber ? Station::where('station_number', $stationNumber)->first() : null;
            
            $apparatus = Apparatus::where('designation', $data['designation'])->first();
            
            $apparatusData = [
                'designation' => $data['designation'],
                'vehicle_number' => $data['vehicle_number'],
                'assignment' => $data['assignment'],
                'status' => $statusMap[$data['status']] ?? 'active',
                'current_station_id' => $station?->id,
                'notes' => $data['notes'] ?? null,
            ];
            
            if ($apparatus) {
                $apparatus->update($apparatusData);
                $updated++;
            } else {
                // Set apparatus type based on designation
                $type = $this->determineApparatusType($data['designation']);
                $apparatusData['type'] = $type;
                $apparatusData['name'] = $data['assignment'];
                Apparatus::create($apparatusData);
                $created++;
            }
        }
        
        $this->command->info("  ✓ Updated {$updated} apparatus, created {$created} new");
    }

    /**
     * Determine apparatus type from designation
     */
    private function determineApparatusType(string $designation): string
    {
        $prefix = strtoupper(preg_replace('/[0-9]+/', '', $designation));
        
        return match($prefix) {
            'E', 'SE' => 'Engine',
            'L', 'T', 'SL' => 'Ladder/Truck',
            'R', 'SR' => 'Rescue',
            'BC', 'DC' => 'Command',
            'USAR' => 'USAR',
            'HM' => 'Hazmat',
            'MB', 'RB' => 'Marine',
            'ATV' => 'ATV',
            'AIR' => 'Air/Supply',
            'REHAB' => 'Rehab',
            'FOAM' => 'Foam',
            'LIGHT' => 'Support',
            default => 'Other',
        };
    }

    /**
     * Update inventory from CSV data
     */
    private function updateInventory(): void
    {
        $this->command->info('Updating inventory...');
        
        // First, ensure Supply Closet location exists
        $supplyCloset = InventoryLocation::firstOrCreate(
            ['name' => 'Supply Closet'],
            [
                'description' => 'Main supply closet for equipment storage',
                'is_active' => true,
            ]
        );

        // Create shelf locations A-F
        $shelfLocations = [];
        foreach (range('A', 'F') as $shelf) {
            $shelfLocations[$shelf] = InventoryLocation::firstOrCreate(
                ['name' => "Supply Closet - Shelf {$shelf}"],
                [
                    'description' => "Shelf {$shelf} in Supply Closet",
                    'is_active' => true,
                ]
            );
        }

        // Inventory data from CSV (cleaned up)
        $inventoryItems = [
            // Shelf A
            ['shelf' => 'A', 'name' => 'Mounts', 'qty' => 2],
            ['shelf' => 'A', 'name' => 'Aerial Master Stream Tips', 'qty' => 4],
            ['shelf' => 'A', 'name' => 'Stream Straightener', 'qty' => 1],
            ['shelf' => 'A', 'name' => 'Nozzle Teeth Packs', 'qty' => 17],
            ['shelf' => 'A', 'name' => 'Stortz Caps', 'qty' => 6],
            ['shelf' => 'A', 'name' => '4" Cap', 'qty' => 1],
            ['shelf' => 'A', 'name' => '5" Caps', 'qty' => 4],
            ['shelf' => 'A', 'name' => '6" Caps', 'qty' => 8],
            ['shelf' => 'A', 'name' => '6" Gaskets', 'qty' => 4],
            ['shelf' => 'A', 'name' => '5" Gaskets', 'qty' => 16],
            ['shelf' => 'A', 'name' => '5" Suction Gaskets', 'qty' => 10],
            ['shelf' => 'A', 'name' => '2 1/2" Gaskets', 'qty' => 18],
            ['shelf' => 'A', 'name' => '1 1/2" Gaskets', 'qty' => 9],
            ['shelf' => 'A', 'name' => 'Misc. Gaskets', 'qty' => 4],
            ['shelf' => 'A', 'name' => '6" to 4" Reducers', 'qty' => 4],
            ['shelf' => 'A', 'name' => '6" to 2" Reducer', 'qty' => 1],
            ['shelf' => 'A', 'name' => '4" to 2 1/2" Reducers', 'qty' => 2],
            ['shelf' => 'A', 'name' => 'Stortz Connection with 4" Male', 'qty' => 6],
            ['shelf' => 'A', 'name' => 'Stortz Connection with 5" Male', 'qty' => 4],
            ['shelf' => 'A', 'name' => 'Stortz Connection with 6" Male', 'qty' => 1],
            ['shelf' => 'A', 'name' => 'Stortz Connection with 6" Female', 'qty' => 2],
            ['shelf' => 'A', 'name' => '5" to 4" Reducers', 'qty' => 3],
            ['shelf' => 'A', 'name' => '4 1/2" Adapter', 'qty' => 1],
            ['shelf' => 'A', 'name' => 'Stortz Connection with 4" Female', 'qty' => 1],
            ['shelf' => 'A', 'name' => 'Hydrant Assist Valve', 'qty' => 1],
            ['shelf' => 'A', 'name' => 'Intake', 'qty' => 1],
            ['shelf' => 'A', 'name' => 'Stortz Elbow to 4" Female', 'qty' => 5],
            ['shelf' => 'A', 'name' => 'Misc. Adapters', 'qty' => 2],
            
            // Shelf B
            ['shelf' => 'B', 'name' => 'Foam Boot', 'qty' => 8],
            ['shelf' => 'B', 'name' => '75psi 175gpm Fog Tips', 'qty' => 10],
            ['shelf' => 'B', 'name' => '100psi 325gpm Fog Tips', 'qty' => 1],
            ['shelf' => 'B', 'name' => '75psi 200gpm Fog Tips', 'qty' => 1],
            ['shelf' => 'B', 'name' => 'Selectomatic Nozzle Tip', 'qty' => 1],
            ['shelf' => 'B', 'name' => 'Other Fog Tips', 'qty' => 5],
            ['shelf' => 'B', 'name' => 'Glow in the Dark Stream Adjusters', 'qty' => 2],
            ['shelf' => 'B', 'name' => 'Bag of Brass Set Screws', 'qty' => 1],
            ['shelf' => 'B', 'name' => 'Red Box Misc.', 'qty' => 1],
            ['shelf' => 'B', 'name' => 'Appliance Mounts', 'qty' => 9],
            ['shelf' => 'B', 'name' => 'Handle Playpipes', 'qty' => 3],
            ['shelf' => 'B', 'name' => 'Incline Gates', 'qty' => 3],
            ['shelf' => 'B', 'name' => '1" Breakaways Bails', 'qty' => 6],
            ['shelf' => 'B', 'name' => '1 1/2" Breakaway Bails', 'qty' => 6],
            ['shelf' => 'B', 'name' => 'Water Thiefs', 'qty' => 6],
            ['shelf' => 'B', 'name' => 'Ground Y Supply', 'qty' => 3],
            ['shelf' => 'B', 'name' => 'Ground Supply', 'qty' => 3],
            
            // Shelf C
            ['shelf' => 'C', 'name' => 'Blitzfire', 'qty' => 1],
            ['shelf' => 'C', 'name' => 'Strainers', 'qty' => 3],
            ['shelf' => 'C', 'name' => 'Hose Edge Protectors', 'qty' => 3],
            ['shelf' => 'C', 'name' => '1 1/4" Nozzle Tips', 'qty' => 3],
            ['shelf' => 'C', 'name' => '1" Nozzle Tip', 'qty' => 3],
            ['shelf' => 'C', 'name' => '1 3/8" Nozzle Tips', 'qty' => 2],
            ['shelf' => 'C', 'name' => '1 1/2" Nozzle Tip', 'qty' => 1],
            ['shelf' => 'C', 'name' => '1 3/4" Nozzle Tip', 'qty' => 1],
            ['shelf' => 'C', 'name' => '2" Nozzle Tip', 'qty' => 1],
            ['shelf' => 'C', 'name' => '1 1/8" Nozzle Tip', 'qty' => 1],
            ['shelf' => 'C', 'name' => '2 1/2" Elbows', 'qty' => 7],
            ['shelf' => 'C', 'name' => '1 1/2" Double Males', 'qty' => 9],
            ['shelf' => 'C', 'name' => '1 1/2" Couplings', 'qty' => 7],
            ['shelf' => 'C', 'name' => '2 1/2 Cap Pressure Gauge', 'qty' => 3],
            ['shelf' => 'C', 'name' => 'Inline Pressure Gauge', 'qty' => 1],
            ['shelf' => 'C', 'name' => '2 1/2" to 1/2" Reducer', 'qty' => 1],
            ['shelf' => 'C', 'name' => '2 1/2" Female Caps', 'qty' => 5],
            ['shelf' => 'C', 'name' => '2 1/2" Male Caps', 'qty' => 3],
            ['shelf' => 'C', 'name' => '1 1/2" Female Caps', 'qty' => 3],
            ['shelf' => 'C', 'name' => '2 1/2 to 1" Reducers', 'qty' => 12],
            ['shelf' => 'C', 'name' => 'Gated Wye', 'qty' => 4],
            ['shelf' => 'C', 'name' => 'Gate Valves', 'qty' => 2],
            ['shelf' => 'C', 'name' => 'Double Male 2 1/2"', 'qty' => 21],
            ['shelf' => 'C', 'name' => 'Double Female 2 1/2"', 'qty' => 15],
            ['shelf' => 'C', 'name' => '2 1/2" Couplings', 'qty' => 2],
            ['shelf' => 'C', 'name' => 'Siamese 2.5" with clapper valves', 'qty' => 3, 'manufacturer' => 'Akron'],
            ['shelf' => 'C', 'name' => 'Siamese with 5" storz connection', 'qty' => 2],
            ['shelf' => 'C', 'name' => 'Trimese 2.5"', 'qty' => 1],
            ['shelf' => 'C', 'name' => 'Wye 2.5"', 'qty' => 2],
            ['shelf' => 'C', 'name' => 'Hose Jacket', 'qty' => 1],
            ['shelf' => 'C', 'name' => 'Foam Pick up tubes', 'qty' => 2],
            ['shelf' => 'C', 'name' => 'Turbo draft (small)', 'qty' => 1],
            ['shelf' => 'C', 'name' => 'Drafting appliances', 'qty' => 2],
            
            // Shelf D
            ['shelf' => 'D', 'name' => 'Training Foam', 'qty' => 5],
            ['shelf' => 'D', 'name' => 'Auto Wash', 'qty' => 1],
            ['shelf' => 'D', 'name' => 'Fog Fluid', 'qty' => 2],
            ['shelf' => 'D', 'name' => 'TK Charger', 'qty' => 5],
            ['shelf' => 'D', 'name' => 'Vector Fog Machine', 'qty' => 3],
            ['shelf' => 'D', 'name' => 'Marq Fog Machine', 'qty' => 2],
            ['shelf' => 'D', 'name' => '4" PVC Pipe', 'qty' => 4],
            ['shelf' => 'D', 'name' => '8" PVC Pipe', 'qty' => 1],
            ['shelf' => 'D', 'name' => 'Sprinkler Wedge', 'qty' => 7],
            ['shelf' => 'D', 'name' => 'Pipe Clamp', 'qty' => 4],
            ['shelf' => 'D', 'name' => 'Male/Female Threaded PVC Caps 1" - 3/4"', 'qty' => 1, 'unit' => 'bag'],
            ['shelf' => 'D', 'name' => 'Glue on PVC Caps', 'qty' => 5],
            ['shelf' => 'D', 'name' => 'Cone Pipe Plug', 'qty' => 1],
            ['shelf' => 'D', 'name' => 'Well Test', 'qty' => 1],
            ['shelf' => 'D', 'name' => 'Crowbar (Shelf D)', 'qty' => 5],
            ['shelf' => 'D', 'name' => 'Ball-peen Hammer', 'qty' => 1],
            ['shelf' => 'D', 'name' => 'Hammer (Shelf D)', 'qty' => 1],
            ['shelf' => 'D', 'name' => '511 Tool', 'qty' => 1],
            ['shelf' => 'D', 'name' => 'Spanner Wrench (Shelf D)', 'qty' => 1],
            ['shelf' => 'D', 'name' => 'Assortment of Allen Wrenches', 'qty' => 1],
            
            // Shelf E
            ['shelf' => 'E', 'name' => 'Decon System', 'qty' => 25],
            ['shelf' => 'E', 'name' => 'Blankets', 'qty' => 4],
            ['shelf' => 'E', 'name' => 'Duffle Bag', 'qty' => 5],
            ['shelf' => 'E', 'name' => 'Struts with Attachments', 'qty' => 8],
            ['shelf' => 'E', 'name' => 'Tool box', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'AAA Batteries', 'qty' => 24],
            ['shelf' => 'E', 'name' => 'AA Batteries', 'qty' => 28],
            ['shelf' => 'E', 'name' => 'D Battery', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Allen Wrench Set Metric', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Allen Wrench Set SAE', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Allen Wrench Set Metric/SAE', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Box Cutter', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Frangible Bulb Sprinkler Head', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Flat-headed Screwdriver', 'qty' => 2],
            ['shelf' => 'E', 'name' => 'Philips Screwdriver', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Mini Philips Screwdriver', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Torx Screwdriver', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Lockout', 'qty' => 8],
            ['shelf' => 'E', 'name' => 'Open-ended Wrench', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Slip-Joint Pliers', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Dyke Cutters', 'qty' => 2],
            ['shelf' => 'E', 'name' => 'Mini Hacksaw', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Vice Grips', 'qty' => 3],
            ['shelf' => 'E', 'name' => 'Adjustable Wrench', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Wire Cutter', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Crowbar (Shelf E)', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Hammer (Shelf E)', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Pipe-Wrench', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Air Cutting Chisel', 'qty' => 1],
            ['shelf' => 'E', 'name' => '20in Chainsaw Blade', 'qty' => 4],
            ['shelf' => 'E', 'name' => 'Chainsaw Chain', 'qty' => 1],
            ['shelf' => 'E', 'name' => '9in Sawzaw Blade', 'qty' => 1, 'unit' => 'box'],
            ['shelf' => 'E', 'name' => 'Dremel', 'qty' => 1],
            ['shelf' => 'E', 'name' => '12in Hacksaw Blade', 'qty' => 15],
            ['shelf' => 'E', 'name' => 'Carbide Sawzaw Blade', 'qty' => 12],
            ['shelf' => 'E', 'name' => 'Air Lube', 'qty' => 2],
            ['shelf' => 'E', 'name' => '4-6in Spanner Wrench', 'qty' => 8],
            ['shelf' => 'E', 'name' => '5in Spanner Wrench', 'qty' => 13],
            ['shelf' => 'E', 'name' => 'Smoke Trainer', 'qty' => 1, 'unit' => 'box'],
            ['shelf' => 'E', 'name' => 'Come-along', 'qty' => 2],
            ['shelf' => 'E', 'name' => 'Come-along Bar', 'qty' => 6],
            ['shelf' => 'E', 'name' => 'Rope Edge Protection', 'qty' => 2],
            ['shelf' => 'E', 'name' => 'Dewalt Carrying Bag', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Hydraram', 'qty' => 1],
            ['shelf' => 'E', 'name' => 'Rescue Tech Bag', 'qty' => 1],
            
            // Shelf F
            ['shelf' => 'F', 'name' => 'Chainsaw Safety Chap', 'qty' => 1, 'unit' => 'box'],
            ['shelf' => 'F', 'name' => 'Yates', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Frisbees', 'qty' => 1, 'unit' => 'box'],
            ['shelf' => 'F', 'name' => '12x15 4-mil Clear Bags', 'qty' => 2, 'unit' => 'boxes'],
            ['shelf' => 'F', 'name' => 'Big Easy Carrying Bag', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Pop-up Traffic Cone with Carrying Bag', 'qty' => 4],
            ['shelf' => 'F', 'name' => 'Universal Lockout Tool Set', 'qty' => 4],
            ['shelf' => 'F', 'name' => 'Air Wedge', 'qty' => 6],
            ['shelf' => 'F', 'name' => 'Glassmaster', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'K-Tool', 'qty' => 3],
            ['shelf' => 'F', 'name' => 'Search and Rescue Gloves', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Flag Pole Mount', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Conversion Kit', 'qty' => 2],
            ['shelf' => 'F', 'name' => 'Spring Rope Hook', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Wedge Pack', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Cooler Cable', 'qty' => 5],
            ['shelf' => 'F', 'name' => 'Access Tool', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Access Tool Kit', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Cutters Edge Tool Sling', 'qty' => 5],
            ['shelf' => 'F', 'name' => 'Hot Stick', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'AC Voltage Detector', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Red Case with Steel Rods', 'qty' => 2],
            ['shelf' => 'F', 'name' => 'Access Tool Bag', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Pick Headed Axe', 'qty' => 6],
            ['shelf' => 'F', 'name' => 'Flat Headed Axe', 'qty' => 5],
            ['shelf' => 'F', 'name' => 'Sledge Hammer', 'qty' => 6],
            ['shelf' => 'F', 'name' => 'Mini Sledge', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Rubber Mallet', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Crowbar (Shelf F)', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Style-50 Bar', 'qty' => 11],
            ['shelf' => 'F', 'name' => 'Mini Shovel', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Mini Halligan', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Storm Drain Tool', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Hacksaw', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Quick Strap Mounting System', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Box of Forcible Entry Tool Straps', 'qty' => 1],
            ['shelf' => 'F', 'name' => '36in Bolt Cutters', 'qty' => 3],
            ['shelf' => 'F', 'name' => '2 Sided Spannered Hydrant Wrench', 'qty' => 4],
            ['shelf' => 'F', 'name' => '1 Sided Spannered Hydrant Wrench', 'qty' => 3],
            ['shelf' => 'F', 'name' => 'Hydrant Wrench', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'FLIR TIC Case', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Carpenter Square', 'qty' => 3],
            ['shelf' => 'F', 'name' => 'Keiser Deadblow 10lb', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Sprinkler Assortment in Ammo Can', 'qty' => 1],
            ['shelf' => 'F', 'name' => 'Water Can', 'qty' => 5],
            ['shelf' => 'F', 'name' => 'CO2 Can', 'qty' => 1],
        ];

        $updated = 0;
        $created = 0;

        foreach ($inventoryItems as $item) {
            $location = $shelfLocations[$item['shelf']] ?? $supplyCloset;
            
            // Determine category based on item name
            $category = $this->determineCategory($item['name']);
            
            $equipmentItem = EquipmentItem::updateOrCreate(
                ['name' => $item['name']],
                [
                    'category' => $category,
                    'location_id' => $location->id,
                    'manufacturer' => $item['manufacturer'] ?? null,
                    'unit_of_measure' => $item['unit'] ?? 'each',
                    'is_active' => true,
                ]
            );
            
            if ($equipmentItem->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
            
            // Update stock levels using laravel-stock
            $currentStock = $equipmentItem->stock ?? 0;
            $targetStock = $item['qty'];
            
            if ($currentStock != $targetStock) {
                // Clear existing stock and set new value
                if ($currentStock > 0) {
                    $equipmentItem->decrementStock($currentStock, ['reason' => 'Inventory reset for update']);
                }
                if ($targetStock > 0) {
                    $equipmentItem->incrementStock($targetStock, ['reason' => 'Inventory count - January 2026']);
                }
            }
        }
        
        $this->command->info("  ✓ Updated {$updated} items, created {$created} new");
        $this->command->info("  ✓ Total inventory items: " . count($inventoryItems));
    }

    /**
     * Determine equipment category from name
     */
    private function determineCategory(string $name): string
    {
        $name = strtolower($name);
        
        if (str_contains($name, 'nozzle') || str_contains($name, 'tip') || str_contains($name, 'fog')) {
            return 'Nozzles & Tips';
        }
        if (str_contains($name, 'gasket') || str_contains($name, 'coupling') || str_contains($name, 'adapter')) {
            return 'Fittings & Adapters';
        }
        if (str_contains($name, 'reducer') || str_contains($name, 'stortz')) {
            return 'Fittings & Adapters';
        }
        if (str_contains($name, 'cap') && !str_contains($name, 'pvc')) {
            return 'Fittings & Adapters';
        }
        if (str_contains($name, 'hose') || str_contains($name, 'wye') || str_contains($name, 'siamese')) {
            return 'Hose Appliances';
        }
        if (str_contains($name, 'axe') || str_contains($name, 'halligan') || str_contains($name, 'crowbar') || str_contains($name, 'bar')) {
            return 'Forcible Entry';
        }
        if (str_contains($name, 'wrench') || str_contains($name, 'screwdriver') || str_contains($name, 'plier')) {
            return 'Hand Tools';
        }
        if (str_contains($name, 'hammer') || str_contains($name, 'mallet')) {
            return 'Hand Tools';
        }
        if (str_contains($name, 'saw') || str_contains($name, 'cutter') || str_contains($name, 'blade')) {
            return 'Cutting Tools';
        }
        if (str_contains($name, 'hydrant')) {
            return 'Hydrant Tools';
        }
        if (str_contains($name, 'battery')) {
            return 'Electrical';
        }
        if (str_contains($name, 'foam') || str_contains($name, 'training')) {
            return 'Training';
        }
        if (str_contains($name, 'rescue') || str_contains($name, 'strut')) {
            return 'Rescue Equipment';
        }
        if (str_contains($name, 'sprinkler') || str_contains($name, 'pvc') || str_contains($name, 'pipe')) {
            return 'Sprinkler/Plumbing';
        }
        if (str_contains($name, 'lockout') || str_contains($name, 'access') || str_contains($name, 'wedge')) {
            return 'Lockout/Entry';
        }
        if (str_contains($name, 'bag') || str_contains($name, 'case') || str_contains($name, 'box')) {
            return 'Storage/Containers';
        }
        
        return 'General Equipment';
    }
}
