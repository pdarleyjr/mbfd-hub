<?php
/**
 * Set stock quantities from CSV using laravel-stock package
 * This script uses the HasStock trait's setStock() method
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\EquipmentItem;
use Illuminate\Support\Facades\DB;

echo "=== Setting Stock Quantities from CSV ===\n\n";

// CSV data with quantities
$csvData = [
    'Mounts' => 2,
    'Aerial Master Stream Tips' => 4,
    'Stream Straightener' => 1,
    'Nozzle Teeth Packs' => 17,
    'Stortz Caps' => 6,
    '4" Cap' => 1,
    '5" Caps' => 4,
    '6" Caps' => 8,
    '6" Gaskets' => 4,
    '5" Gaskets' => 16,
    '5" Suction Gaskets' => 10,
    '2 1/2" Gaskets' => 18,
    '1 1/2" Gaskets' => 9,
    'Misc. Gaskets' => 4,
    '6" to 4" Reducers' => 4,
    '6" to 2" Reducer' => 1,
    '4" to 2 1/2" Reducers' => 2,
    'Stortz Connection with 4" Male' => 6,
    'Stortz Connection with 5" Male' => 4,
    'Stortz Connection with 6" Male' => 1,
    'Stortz Connection with 6" Female' => 2,
    '5" to 4" Reducers' => 3,
    '4 1/2" Adapter' => 1,
    'Stortz Connection with 4" Female' => 1,
    'Hydrant Assist Valve' => 1,
    'Intake' => 1,
    'Stortz Elbow to 4" Female' => 5,
    'Misc. Adapters' => 2,
    'Foam Boot' => 8,
    '75psi 175gpm Fog Tips' => 10,
    '100psi 325gpm Fog Tips' => 1,
    '75psi 200gpm Fog Tips' => 1,
    'Selectomatic Nozzle Tip' => 1,
    'Other Fog Tips' => 5,
    'Glow in the Dark Stream Adjusters' => 2,
    'Bag of Brass Set Screws' => 1,
    'Red Box Misc.' => 1,
    'Appliance Mounts' => 9,
    'Handle Playpipes' => 3,
    'Incline Gates' => 3,
    '1" Breakaways Bails' => 6,
    '1 1/2" Breakaway Bails' => 6,
    'Water Thiefs' => 6,
    'Ground Y Supply' => 3,
    'Ground Supply' => 3,
    'Blitzfire' => 1,
    'Strainers' => 3,
    'Hose Edge Protectors' => 3,
    '1 1/4" Nozzle Tips' => 3,
    '1" Nozzle Tip' => 3,
    '1 3/8" Nozzle Tips' => 2,
    '1 1/2" Nozzle Tip' => 1,
    '1 3/4" Nozzle Tip' => 1,
    '2" Nozzle Tip' => 1,
    '1 1/8" Nozzle Tip' => 1,
    '2 1/2" Elbows' => 7,
    '1 1/2" Double Males' => 9,
    '1 1/2" Couplings' => 7,
    '2 1/2 Cap Pressure Gauge' => 3,
    'Inline Pressure Gauge' => 1,
    '2 1/2" to 1/2" Reducer' => 1,
    '2 1/2" Female Caps' => 5,
    '2 1/2" Male Caps' => 3,
    '1 1/2" Female Caps' => 3,
    '2 1/2 to 1" Reducers' => 12,
    'Gated Wye' => 4,
    'Gate Valves' => 2,
    'Double Male 2 1/2"' => 21,
    'Double Female 2 1/2"' => 15,
    '2 1/2" Couplings' => 2,
    'Siamese 2.5" with clapper valves' => 3,
    'Siamese with 5" storz connection' => 2,
    'Trimese 2.5"' => 1,
    'Wye 2.5"' => 2,
    'Hose Jacket' => 1,
    'Foam Pick up tubes' => 2,
    'Turbo draft (small)' => 1,
    'Drafting appliances' => 2,
    'Training Foam' => 5,
    'Auto Wash (1)' => 1,
    'Fog Fluid (x2)' => 2,
    'TK Charger (x5)' => 5,
    'Vector Fog Machine (x3)' => 3,
    'Marq Fog Machine (x2)' => 2,
    '4" PVC Pipe (x4)' => 4,
    '8" PVC Pipe (x1)' => 1,
    'Sprinkler Wedge (x7)' => 7,
    'Pipe Clamp (x4)' => 4,
    'Male/Female Threaded PVC Caps 1" - 3/4" (x 1 bag)' => 1,
    'Glue on PVC Caps (x 5)' => 5,
    'Cone Pipe Plug (x1)' => 1,
    'Well Test (x1)' => 1,
    'Crowbar (x5)' => 5,
    'Ball-peen Hammer (x1)' => 1,
    'Hammer (x1)' => 1,
    '511 Tool (x1)' => 1,
    'Spanner Wrench (x1)' => 1,
    'Assortment of Allen Wrenches (x1)' => 1,
    'Decon System (x25)' => 25,
    'Blankets (x4)' => 4,
    'Duffle Bag (x5)' => 5,
    'Struts with Attachments (x8)' => 8,
    'AAA Batteries' => 24,
    'AA Batteries' => 28,
    'D Battery' => 1,
    'Allen Wrench Set Metric' => 1,
    'Allen Wrench Set SAE' => 1,
    'Allen Wrench Set Metric/SAE' => 1,
    'Box Cutter' => 1,
    'Frangible Bulb Sprinkler Head' => 1,
    'Flat-headed Screwdriver' => 2,
    'Philips Screwdriver' => 1,
    'Mini Philips Screwdriver' => 1,
    'Torx Screwdriver' => 1,
    'Lockout' => 8,
    'Open-ended Wrench' => 1,
    'Slip-Joint Pliers' => 1,
    'Dyke Cutters' => 2,
    'Mini Hacksaw' => 1,
    'Vice Grips' => 3,
    'Adjustable Wrench' => 1,
    'Wire Cutter' => 1,
    'Crowbar' => 1,
    'Hammer' => 1,
    'Pipe-Wrench' => 1,
    'Air Cutting Chisel' => 1,
    '20in Chainsaw Blade' => 4,
    'Chainsaw Chain' => 1,
    'Dremel' => 1,
    '12in Hacksaw Blade' => 15,
    'Carbide Sawzaw Blade' => 12,
    'Air Lube' => 2,
    '4-6in Spanner Wrench' => 8,
    '5in Spanner Wrench' => 13,
    'Come-along' => 2,
    'Come-along Bar' => 6,
    'Rope Edge Protection' => 2,
    'Dewalt Carrying Bag' => 1,
    'Hydraram' => 1,
    'Rescue Tech Bag' => 1,
    'Yates' => 1,
    'Pop-up Traffic Cone with Carrying Bag' => 4,
    'Universal Lockout Tool Set' => 4,
    'Air Wedge' => 6,
    'Glassmaster' => 1,
    'K-Tool' => 3,
    'Search and Rescue Gloves' => 1,
    'Flag Pole Mount' => 1,
    'Conversion Kit' => 2,
    'Spring Rope Hook' => 1,
    'Wedge Pack' => 1,
    'Cooler Cable' => 5,
    'Access Tool' => 1,
    'Access Tool Kit' => 1,
    'Cutters Edge Tool Sling' => 5,
    'Hot Stick' => 1,
    'AC Voltage Detector' => 1,
    'Red Case with Steel Rods' => 2,
    'Access Tool Bag' => 1,
    'Pick Headed Axe' => 6,
    'Flat Headed Axe' => 5,
    'Sledge Hammer' => 6,
    'Mini Sledge' => 1,
    'Rubber Mallet' => 1,
    'Style-50 Bar' => 11,
    'Mini Shovel' => 1,
    'Mini Halligan' => 1,
    'Storm Drain Tool' => 1,
    'Hacksaw' => 1,
    'Quick Strap Mounting System' => 1,
    'Box of Forcible Entry Tool Straps' => 1,
    '36in Bolt Cutters' => 3,
    '2 Sided Spannered Hydrant Wrench' => 4,
    '1 Sided Spannered Hydrant Wrench' => 3,
    'Hydrant Wrench' => 1,
    'FLIR TIC Case' => 1,
    'Carpenter Square' => 3,
    'Keiser Deadblow 10lb' => 1,
    'Sprinkler Assortment in Ammo Can' => 1,
    'Water Can' => 5,
    'CO2 Can' => 1,
];

$updated = 0;
$notFound = [];

DB::beginTransaction();
try {
    foreach ($csvData as $itemName => $quantity) {
        // Try exact match first
        $item = EquipmentItem::where('name', $itemName)->first();
        
        // Try normalized match
        if (!$item) {
            $normalizedName = EquipmentItem::normalizeName($itemName);
            $item = EquipmentItem::where('normalized_name', $normalizedName)->first();
        }
        
        // Try LIKE match
        if (!$item) {
            $item = EquipmentItem::where('name', 'ILIKE', '%' . $itemName . '%')->first();
        }
        
        if ($item) {
            // Clear existing stock mutations and set new stock
            DB::table('stock_mutations')->where('stockable_id', $item->id)
                ->where('stockable_type', EquipmentItem::class)
                ->delete();
            
            // Add new stock
            $item->increaseStock($quantity);
            $updated++;
            echo "✓ Set {$item->name}: stock = {$quantity}\n";
        } else {
            $notFound[] = $itemName;
        }
    }
    
    DB::commit();
    echo "\n=== Results ===\n";
    echo "Updated: {$updated} items\n";
    echo "Not found: " . count($notFound) . " items\n";
    
    if (count($notFound) > 0) {
        echo "\nNot found items:\n";
        foreach ($notFound as $name) {
            echo "  - {$name}\n";
        }
    }
    
    // Show low stock count after update
    $lowStockCount = EquipmentItem::where('is_active', true)
        ->get()
        ->filter(fn ($item) => $item->stock <= $item->reorder_min)
        ->count();
    echo "\nLow stock items now: {$lowStockCount}\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone!\n";
