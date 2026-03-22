<?php
/**
 * Update Equipment Item Quantities from CSV
 * Run with: php artisan tinker update_equipment_quantities.php
 * Or: docker compose exec laravel php update_equipment_quantities.php
 */

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\EquipmentItem;
use App\Models\InventoryLocation;
use Illuminate\Support\Facades\DB;

// CSV data parsed - Equipment Name => Quantity
$csvData = [
    // Shelf A
    ['name' => 'Mounts', 'shelf' => 'A', 'row' => 1, 'qty' => 2],
    ['name' => 'Aerial Master Stream Tips', 'shelf' => 'A', 'row' => 1, 'qty' => 4],
    ['name' => 'Stream Straightener', 'shelf' => 'A', 'row' => 1, 'qty' => 1],
    ['name' => 'Nozzle Teeth Packs', 'shelf' => 'A', 'row' => 1, 'qty' => 17],
    ['name' => 'Stortz Caps', 'shelf' => 'A', 'row' => 2, 'qty' => 6],
    ['name' => '4" Cap', 'shelf' => 'A', 'row' => 2, 'qty' => 1],
    ['name' => '5" Caps', 'shelf' => 'A', 'row' => 2, 'qty' => 4],
    ['name' => '6" Caps', 'shelf' => 'A', 'row' => 2, 'qty' => 8],
    ['name' => '6" Gaskets', 'shelf' => 'A', 'row' => 2, 'qty' => 4],
    ['name' => '5" Gaskets', 'shelf' => 'A', 'row' => 2, 'qty' => 16],
    ['name' => '5" Suction Gaskets', 'shelf' => 'A', 'row' => 2, 'qty' => 10],
    ['name' => '2 1/2" Gaskets', 'shelf' => 'A', 'row' => 2, 'qty' => 18],
    ['name' => '1 1/2" Gaskets', 'shelf' => 'A', 'row' => 2, 'qty' => 9],
    ['name' => 'Misc. Gaskets', 'shelf' => 'A', 'row' => 2, 'qty' => 4],
    ['name' => '6" to 4" Reducers', 'shelf' => 'A', 'row' => 3, 'qty' => 4],
    ['name' => '6" to 2" Reducer', 'shelf' => 'A', 'row' => 3, 'qty' => 1],
    ['name' => '4" to 2 1/2" Reducers', 'shelf' => 'A', 'row' => 3, 'qty' => 2],
    ['name' => 'Stortz Connection with 4" Male', 'shelf' => 'A', 'row' => 3, 'qty' => 6],
    ['name' => 'Stortz Connection with 5" Male', 'shelf' => 'A', 'row' => 3, 'qty' => 4],
    ['name' => 'Stortz Connection with 6" Male', 'shelf' => 'A', 'row' => 3, 'qty' => 1],
    ['name' => 'Stortz Connection with 6" Female', 'shelf' => 'A', 'row' => 3, 'qty' => 2],
    ['name' => '5" to 4" Reducers', 'shelf' => 'A', 'row' => 3, 'qty' => 3],
    ['name' => '4 1/2" Adapter', 'shelf' => 'A', 'row' => 3, 'qty' => 1],
    ['name' => 'Stortz Connection with 4" Female', 'shelf' => 'A', 'row' => 3, 'qty' => 1],
    ['name' => 'Hydrant Assist Valve', 'shelf' => 'A', 'row' => 4, 'qty' => 1],
    ['name' => 'Intake', 'shelf' => 'A', 'row' => 4, 'qty' => 1],
    ['name' => 'Stortz Elbow to 4" Female', 'shelf' => 'A', 'row' => 4, 'qty' => 5],
    ['name' => 'Misc. Adapters', 'shelf' => 'A', 'row' => 4, 'qty' => 2],
    
    // Shelf B
    ['name' => 'Foam Boot', 'shelf' => 'B', 'row' => 1, 'qty' => 8],
    ['name' => '75psi 175gpm Fog Tips', 'shelf' => 'B', 'row' => 2, 'qty' => 10],
    ['name' => '100psi 325gpm Fog Tips', 'shelf' => 'B', 'row' => 2, 'qty' => 1],
    ['name' => '75psi 200gpm Fog Tips', 'shelf' => 'B', 'row' => 2, 'qty' => 1],
    ['name' => 'Selectomatic Nozzle Tip', 'shelf' => 'B', 'row' => 2, 'qty' => 1],
    ['name' => 'Other Fog Tips', 'shelf' => 'B', 'row' => 2, 'qty' => 5],
    ['name' => 'Glow in the Dark Stream Adjusters', 'shelf' => 'B', 'row' => 2, 'qty' => 2],
    ['name' => 'Bag of Brass Set Screws', 'shelf' => 'B', 'row' => 2, 'qty' => 1],
    ['name' => 'Red Box Misc.', 'shelf' => 'B', 'row' => 2, 'qty' => 1],
    ['name' => 'Appliance Mounts', 'shelf' => 'B', 'row' => 2, 'qty' => 9],
    ['name' => 'Handle Playpipes', 'shelf' => 'B', 'row' => 3, 'qty' => 3],
    ['name' => 'Incline Gates', 'shelf' => 'B', 'row' => 3, 'qty' => 3],
    ['name' => '1" Breakaways Bails', 'shelf' => 'B', 'row' => 3, 'qty' => 6],
    ['name' => '1 1/2" Breakaway Bails', 'shelf' => 'B', 'row' => 3, 'qty' => 6],
    ['name' => 'Water Thiefs', 'shelf' => 'B', 'row' => 4, 'qty' => 6],
    ['name' => 'Ground Y Supply', 'shelf' => 'B', 'row' => 4, 'qty' => 3],
    ['name' => 'Ground Supply', 'shelf' => 'B', 'row' => 4, 'qty' => 3],
    
    // Shelf C
    ['name' => 'Blitzfire', 'shelf' => 'C', 'row' => 1, 'qty' => 1],
    ['name' => 'Strainers', 'shelf' => 'C', 'row' => 1, 'qty' => 3],
    ['name' => 'Hose Edge Protectors', 'shelf' => 'C', 'row' => 1, 'qty' => 3],
    ['name' => '1 1/4" Nozzle Tips', 'shelf' => 'C', 'row' => 2, 'qty' => 3],
    ['name' => '1" Nozzle Tip', 'shelf' => 'C', 'row' => 2, 'qty' => 3],
    ['name' => '1 3/8" Nozzle Tips', 'shelf' => 'C', 'row' => 2, 'qty' => 2],
    ['name' => '1 1/2" Nozzle Tip', 'shelf' => 'C', 'row' => 2, 'qty' => 1],
    ['name' => '1 3/4" Nozzle Tip', 'shelf' => 'C', 'row' => 2, 'qty' => 1],
    ['name' => '2" Nozzle Tip', 'shelf' => 'C', 'row' => 2, 'qty' => 1],
    ['name' => '1 1/8" Nozzle Tip', 'shelf' => 'C', 'row' => 2, 'qty' => 1],
    ['name' => '2 1/2" Elbows', 'shelf' => 'C', 'row' => 2, 'qty' => 7],
    ['name' => '1 1/2" Double Males', 'shelf' => 'C', 'row' => 2, 'qty' => 9],
    ['name' => '1 1/2" Couplings', 'shelf' => 'C', 'row' => 2, 'qty' => 7],
    ['name' => '2 1/2 Cap Pressure Gauge', 'shelf' => 'C', 'row' => 2, 'qty' => 3],
    ['name' => 'Inline Pressure Gauge', 'shelf' => 'C', 'row' => 2, 'qty' => 1],
    ['name' => '2 1/2" to 1/2" Reducer', 'shelf' => 'C', 'row' => 2, 'qty' => 1],
    ['name' => '2 1/2" Female Caps', 'shelf' => 'C', 'row' => 2, 'qty' => 5],
    ['name' => '2 1/2" Male Caps', 'shelf' => 'C', 'row' => 2, 'qty' => 3],
    ['name' => '1 1/2" Female Caps', 'shelf' => 'C', 'row' => 2, 'qty' => 3],
    ['name' => '2 1/2 to 1" Reducers', 'shelf' => 'C', 'row' => 2, 'qty' => 12],
    ['name' => 'Gated Wye', 'shelf' => 'C', 'row' => 3, 'qty' => 4],
    ['name' => 'Gate Valves', 'shelf' => 'C', 'row' => 3, 'qty' => 2],
    ['name' => 'Double Male 2 1/2"', 'shelf' => 'C', 'row' => 3, 'qty' => 21],
    ['name' => 'Double Female 2 1/2"', 'shelf' => 'C', 'row' => 3, 'qty' => 15],
    ['name' => '2 1/2" Couplings', 'shelf' => 'C', 'row' => 3, 'qty' => 2],
    ['name' => 'Siamese 2.5" with clapper valves', 'shelf' => 'C', 'row' => 4, 'qty' => 3],
    ['name' => 'Siamese with 5" storz connection', 'shelf' => 'C', 'row' => 4, 'qty' => 2],
    ['name' => 'Trimese 2.5"', 'shelf' => 'C', 'row' => 4, 'qty' => 1],
    ['name' => 'Wye 2.5"', 'shelf' => 'C', 'row' => 4, 'qty' => 2],
    ['name' => 'Hose Jacket', 'shelf' => 'C', 'row' => 4, 'qty' => 1],
    ['name' => 'Foam Pick up tubes', 'shelf' => 'C', 'row' => 4, 'qty' => 2],
    ['name' => 'Turbo draft (small)', 'shelf' => 'C', 'row' => 4, 'qty' => 1],
    ['name' => 'Drafting appliances', 'shelf' => 'C', 'row' => 4, 'qty' => 2],
    
    // Shelf D
    ['name' => 'Training Foam', 'shelf' => 'D', 'row' => 4, 'qty' => 5],
    ['name' => 'Auto Wash', 'shelf' => 'D', 'row' => 4, 'qty' => 1],
    ['name' => 'Fog Fluid', 'shelf' => 'D', 'row' => 4, 'qty' => 2],
    ['name' => 'TK Charger', 'shelf' => 'D', 'row' => 4, 'qty' => 5],
    ['name' => 'Vector Fog Machine', 'shelf' => 'D', 'row' => 4, 'qty' => 3],
    ['name' => 'Marq Fog Machine', 'shelf' => 'D', 'row' => 4, 'qty' => 2],
    ['name' => '4" PVC Pipe', 'shelf' => 'D', 'row' => 3, 'qty' => 4],
    ['name' => '8" PVC Pipe', 'shelf' => 'D', 'row' => 3, 'qty' => 1],
    ['name' => 'Sprinkler Wedge', 'shelf' => 'D', 'row' => 3, 'qty' => 7],
    ['name' => 'Pipe Clamp', 'shelf' => 'D', 'row' => 3, 'qty' => 4],
    ['name' => 'Male/Female Threaded PVC Caps 1" - 3/4"', 'shelf' => 'D', 'row' => 3, 'qty' => 1],
    ['name' => 'Glue on PVC Caps', 'shelf' => 'D', 'row' => 3, 'qty' => 5],
    ['name' => 'Cone Pipe Plug', 'shelf' => 'D', 'row' => 3, 'qty' => 1],
    ['name' => 'Well Test', 'shelf' => 'D', 'row' => 3, 'qty' => 1],
    ['name' => 'Crowbar (Shelf D)', 'shelf' => 'D', 'row' => 3, 'qty' => 5],
    ['name' => 'Ball-peen Hammer', 'shelf' => 'D', 'row' => 3, 'qty' => 1],
    ['name' => 'Hammer (Shelf D)', 'shelf' => 'D', 'row' => 3, 'qty' => 1],
    ['name' => '511 Tool', 'shelf' => 'D', 'row' => 3, 'qty' => 1],
    ['name' => 'Spanner Wrench (Shelf D)', 'shelf' => 'D', 'row' => 3, 'qty' => 1],
    ['name' => 'Assortment of Allen Wrenches', 'shelf' => 'D', 'row' => 3, 'qty' => 1],
    
    // Shelf E
    ['name' => 'Decon System', 'shelf' => 'E', 'row' => 1, 'qty' => 25],
    ['name' => 'Blankets', 'shelf' => 'E', 'row' => 1, 'qty' => 4],
    ['name' => 'Duffle Bag', 'shelf' => 'E', 'row' => 1, 'qty' => 5],
    ['name' => 'Struts with Attachments', 'shelf' => 'E', 'row' => 2, 'qty' => 8],
    ['name' => 'Tool box', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'AAA Batteries', 'shelf' => 'E', 'row' => 3, 'qty' => 24],
    ['name' => 'AA Batteries', 'shelf' => 'E', 'row' => 3, 'qty' => 28],
    ['name' => 'D Battery', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Allen Wrench Set Metric', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Allen Wrench Set SAE', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Allen Wrench Set Metric/SAE', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Box Cutter', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Frangible Bulb Sprinkler Head', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Flat-headed Screwdriver', 'shelf' => 'E', 'row' => 3, 'qty' => 2],
    ['name' => 'Philips Screwdriver', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Mini Philips Screwdriver', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Torx Screwdriver', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Lockout', 'shelf' => 'E', 'row' => 3, 'qty' => 8],
    ['name' => 'Open-ended Wrench', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Slip-Joint Pliers', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Dyke Cutters', 'shelf' => 'E', 'row' => 3, 'qty' => 2],
    ['name' => 'Mini Hacksaw', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Vice Grips', 'shelf' => 'E', 'row' => 3, 'qty' => 3],
    ['name' => 'Adjustable Wrench', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Wire Cutter', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Crowbar (Shelf E)', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Hammer (Shelf E)', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Pipe-Wrench', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Air Cutting Chisel', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => '20in Chainsaw Blade', 'shelf' => 'E', 'row' => 3, 'qty' => 4],
    ['name' => 'Chainsaw Chain', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => '9in Sawzaw Blade', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Dremel', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => '12in Hacksaw Blade', 'shelf' => 'E', 'row' => 3, 'qty' => 15],
    ['name' => 'Carbide Sawzaw Blade', 'shelf' => 'E', 'row' => 3, 'qty' => 12],
    ['name' => 'Air Lube', 'shelf' => 'E', 'row' => 3, 'qty' => 2],
    ['name' => '4-6in Spanner Wrench', 'shelf' => 'E', 'row' => 3, 'qty' => 8],
    ['name' => '5in Spanner Wrench', 'shelf' => 'E', 'row' => 3, 'qty' => 13],
    ['name' => 'Smoke Trainer', 'shelf' => 'E', 'row' => 3, 'qty' => 1],
    ['name' => 'Come-along', 'shelf' => 'E', 'row' => 3, 'qty' => 2],
    ['name' => 'Come-along Bar', 'shelf' => 'E', 'row' => 3, 'qty' => 6],
    ['name' => 'Rope Edge Protection', 'shelf' => 'E', 'row' => 3, 'qty' => 2],
    ['name' => 'Dewalt Carrying Bag', 'shelf' => 'E', 'row' => 4, 'qty' => 1],
    ['name' => 'Hydraram', 'shelf' => 'E', 'row' => 4, 'qty' => 1],
    ['name' => 'Rescue Tech Bag', 'shelf' => 'E', 'row' => 4, 'qty' => 1],
    
    // Shelf F
    ['name' => 'Chainsaw Safety Chap', 'shelf' => 'F', 'row' => 1, 'qty' => 1],
    ['name' => 'Yates', 'shelf' => 'F', 'row' => 1, 'qty' => 1],
    ['name' => 'Frisbees', 'shelf' => 'F', 'row' => 1, 'qty' => 1],
    ['name' => '12x15 4-mil Clear Bags', 'shelf' => 'F', 'row' => 1, 'qty' => 2],
    ['name' => 'Big Easy Carrying Bag', 'shelf' => 'F', 'row' => 1, 'qty' => 1],
    ['name' => 'Pop-up Traffic Cone with Carrying Bag', 'shelf' => 'F', 'row' => 1, 'qty' => 4],
    ['name' => 'Universal Lockout Tool Set', 'shelf' => 'F', 'row' => 2, 'qty' => 4],
    ['name' => 'Air Wedge', 'shelf' => 'F', 'row' => 2, 'qty' => 6],
    ['name' => 'Glassmaster', 'shelf' => 'F', 'row' => 2, 'qty' => 1],
    ['name' => 'K-Tool', 'shelf' => 'F', 'row' => 2, 'qty' => 3],
    ['name' => 'Search and Rescue Gloves', 'shelf' => 'F', 'row' => 2, 'qty' => 1],
    ['name' => 'Flag Pole Mount', 'shelf' => 'F', 'row' => 2, 'qty' => 1],
    ['name' => 'Conversion Kit', 'shelf' => 'F', 'row' => 2, 'qty' => 2],
    ['name' => 'Spring Rope Hook', 'shelf' => 'F', 'row' => 2, 'qty' => 1],
    ['name' => 'Wedge Pack', 'shelf' => 'F', 'row' => 2, 'qty' => 1],
    ['name' => 'Cooler Cable', 'shelf' => 'F', 'row' => 2, 'qty' => 5],
    ['name' => 'Access Tool', 'shelf' => 'F', 'row' => 2, 'qty' => 1],
    ['name' => 'Access Tool Kit', 'shelf' => 'F', 'row' => 2, 'qty' => 1],
    ['name' => 'Cutters Edge Tool Sling', 'shelf' => 'F', 'row' => 2, 'qty' => 5],
    ['name' => 'Hot Stick', 'shelf' => 'F', 'row' => 2, 'qty' => 1],
    ['name' => 'AC Voltage Detector', 'shelf' => 'F', 'row' => 2, 'qty' => 1],
    ['name' => 'Red Case with Steel Rods', 'shelf' => 'F', 'row' => 2, 'qty' => 2],
    ['name' => 'Access Tool Bag', 'shelf' => 'F', 'row' => 2, 'qty' => 1],
    ['name' => 'Pick Headed Axe', 'shelf' => 'F', 'row' => 3, 'qty' => 6],
    ['name' => 'Flat Headed Axe', 'shelf' => 'F', 'row' => 3, 'qty' => 5],
    ['name' => 'Sledge Hammer', 'shelf' => 'F', 'row' => 3, 'qty' => 6],
    ['name' => 'Mini Sledge', 'shelf' => 'F', 'row' => 3, 'qty' => 1],
    ['name' => 'Rubber Mallet', 'shelf' => 'F', 'row' => 3, 'qty' => 1],
    ['name' => 'Crowbar (Shelf F)', 'shelf' => 'F', 'row' => 3, 'qty' => 1],
    ['name' => 'Style-50 Bar', 'shelf' => 'F', 'row' => 3, 'qty' => 11],
    ['name' => 'Mini Shovel', 'shelf' => 'F', 'row' => 3, 'qty' => 1],
    ['name' => 'Mini Halligan', 'shelf' => 'F', 'row' => 3, 'qty' => 1],
    ['name' => 'Storm Drain Tool', 'shelf' => 'F', 'row' => 3, 'qty' => 1],
    ['name' => 'Hacksaw', 'shelf' => 'F', 'row' => 3, 'qty' => 1],
    ['name' => 'Quick Strap Mounting System', 'shelf' => 'F', 'row' => 3, 'qty' => 1],
    ['name' => 'Box of Forcible Entry Tool Straps', 'shelf' => 'F', 'row' => 3, 'qty' => 1],
    ['name' => '36in Bolt Cutters', 'shelf' => 'F', 'row' => 3, 'qty' => 3],
    ['name' => '2 Sided Spannered Hydrant Wrench', 'shelf' => 'F', 'row' => 4, 'qty' => 4],
    ['name' => '1 Sided Spannered Hydrant Wrench', 'shelf' => 'F', 'row' => 4, 'qty' => 3],
    ['name' => 'Hydrant Wrench', 'shelf' => 'F', 'row' => 4, 'qty' => 1],
    ['name' => 'FLIR TIC Case', 'shelf' => 'F', 'row' => 4, 'qty' => 1],
    ['name' => 'Carpenter Square', 'shelf' => 'F', 'row' => 4, 'qty' => 3],
    ['name' => 'Keiser Deadblow 10lb', 'shelf' => 'F', 'row' => 4, 'qty' => 1],
    ['name' => 'Sprinkler Assortment in Ammo Can', 'shelf' => 'F', 'row' => 4, 'qty' => 1],
    ['name' => 'Water Can', 'shelf' => 'F', 'row' => 4, 'qty' => 5],
    ['name' => 'CO2 Can', 'shelf' => 'F', 'row' => 4, 'qty' => 1],
];

echo "Starting equipment quantity update...\n";
echo "=========================================\n\n";

$updated = 0;
$notFound = [];
$errors = [];

DB::beginTransaction();

try {
    foreach ($csvData as $item) {
        $name = $item['name'];
        $qty = $item['qty'];
        $shelf = $item['shelf'];
        $row = $item['row'];
        
        // Find equipment by name (case-insensitive match)
        $equipment = EquipmentItem::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
        
        if (!$equipment) {
            $notFound[] = $name;
            continue;
        }
        
        // Update reorder_max to the CSV quantity
        $oldQty = $equipment->reorder_max;
        $equipment->reorder_max = $qty;
        
        // Also update location row if needed
        if ($equipment->location_id) {
            $location = InventoryLocation::find($equipment->location_id);
            if ($location && $location->row != $row) {
                $location->row = $row;
                $location->save();
            }
        }
        
        $equipment->save();
        $updated++;
        
        echo "Updated: {$name} (qty: {$oldQty} -> {$qty})\n";
    }
    
    DB::commit();
    
    echo "\n=========================================\n";
    echo "Summary:\n";
    echo "- Updated: {$updated} items\n";
    
    if (count($notFound) > 0) {
        echo "- Not found: " . count($notFound) . " items\n";
        foreach ($notFound as $name) {
            echo "  - {$name}\n";
        }
    }
    
    echo "\nUpdate completed successfully!\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Transaction rolled back.\n";
    exit(1);
}
