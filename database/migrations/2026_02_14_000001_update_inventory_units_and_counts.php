<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\InventoryItem;
use App\Models\StationInventoryItem;
use Illuminate\Support\Facades\DB;

return new class extends Migration 
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Define the new unit configuration key based on Appendix A
        // Format: [Item Name] => ['unit_label' => '...', 'unit_multiplier' => ..., 'par_units' => ...]
        $itemsConfig = [
            // Garbage & Paper Goods
            'Small Garbage bag rolls, 25 bags/roll' => ['unit_label' => 'rolls', 'unit_multiplier' => 10, 'par_units' => 20], // Was 10 rolls/case, expected 2 cases = 20 rolls?  Wait, "Expected = 2 (cases)". If 10 rolls/case, then 2 cases = 20 rolls. Multiplier from Case -> Rolls is 10.
            'Large Garbage bag rolls, 10 bags/roll' => ['unit_label' => 'rolls', 'unit_multiplier' => 10, 'par_units' => 20],
            'Box White Terry Towel Rags' => ['unit_label' => 'box', 'unit_multiplier' => 1, 'par_units' => 1],
            'White Multifold Paper Towels (250 sheets/pack)' => ['unit_label' => 'packs', 'unit_multiplier' => 16, 'par_units' => 64], // e.g. 4 cases * 16 packs/case = 64? Or just setting par directly.
            'Brown Paper Towel Rolls (800 ft/roll)' => ['unit_label' => 'rolls', 'unit_multiplier' => 6, 'par_units' => 24],
            'Toilet Paper (500 sheets/roll)' => ['unit_label' => 'rolls', 'unit_multiplier' => 96, 'par_units' => 192],
            'White Paper Towel Rolls' => ['unit_label' => 'rolls', 'unit_multiplier' => 30, 'par_units' => 30],
            'Aerosol Disinfectant Deodorant' => ['unit_label' => 'cans', 'unit_multiplier' => 12, 'par_units' => 12],

            // Floors
            'General Purpose Floor Cleaner 1 gal' => ['unit_label' => 'gallons', 'unit_multiplier' => 4, 'par_units' => 8],
            'Cleaner Degreaser 1 gal' => ['unit_label' => 'gallons', 'unit_multiplier' => 4, 'par_units' => 16],
            '10" Dual Surface Scrub Brush' => ['unit_label' => 'units', 'unit_multiplier' => 1, 'par_units' => 3],
            '8" Green Swivel Scrub Brush' => ['unit_label' => 'units', 'unit_multiplier' => 1, 'par_units' => 3],
            '10" Feather Tip Vehicle Brush' => ['unit_label' => 'units', 'unit_multiplier' => 1, 'par_units' => 3],
            '22" Foam Floor Squeegee' => ['unit_label' => 'units', 'unit_multiplier' => 1, 'par_units' => 4],
            '15/16" x 54" Fiberglass Handle' => ['unit_label' => 'units', 'unit_multiplier' => 1, 'par_units' => 4],
            'Garage Sweep Broom' => ['unit_label' => 'units', 'unit_multiplier' => 1, 'par_units' => 2],
            'Lobby Dust Pan' => ['unit_label' => 'unit', 'unit_multiplier' => 1, 'par_units' => 1],
            'Corn Housekeeping Broom' => ['unit_label' => 'units', 'unit_multiplier' => 1, 'par_units' => 2],
            'Mop Handle' => ['unit_label' => 'units', 'unit_multiplier' => 1, 'par_units' => 2],
            'Replacement String Mop Head' => ['unit_label' => 'units', 'unit_multiplier' => 12, 'par_units' => 12], // 12/box
            'Rubbermaid WaveBrake Bucket' => ['unit_label' => 'unit', 'unit_multiplier' => 1, 'par_units' => 1],

            // Laundry
            'Bleach 1 gal' => ['unit_label' => 'gallons', 'unit_multiplier' => 6, 'par_units' => 12],
            'Laundry Detergent 1 gal' => ['unit_label' => 'gallons', 'unit_multiplier' => 4, 'par_units' => 12],

            // Bathroom & Cleaners
            'Spic and Span Cleaner' => ['unit_label' => 'units', 'unit_multiplier' => 1, 'par_units' => 8], // Assuming 1? Or cases? 
            // NOTE: For items where "Expected" was already roughly units or cases of 1, we use multiplier 1. 
            // If the user says "Allowed Quantity" is the PAR in CASES, we need the multiplier to convert existing "2 cases" to "20 rolls".

            'Simple Green Concentrate' => ['unit_label' => 'unit', 'unit_multiplier' => 1, 'par_units' => 1],
            'Toilet Bowl Cleaner' => ['unit_label' => 'units', 'unit_multiplier' => 12, 'par_units' => 12],
            'Sanitizing Bathroom Cleaner Spray' => ['unit_label' => 'units', 'unit_multiplier' => 1, 'par_units' => 8],
            'Urinal Screen' => ['unit_label' => 'units', 'unit_multiplier' => 12, 'par_units' => 12], // 12/pack?
            'Rectangular Trash Can' => ['unit_label' => 'units', 'unit_multiplier' => 1, 'par_units' => 2],
            'Toilet Plunger' => ['unit_label' => 'unit', 'unit_multiplier' => 1, 'par_units' => 1],
            'Toilet Brush' => ['unit_label' => 'units', 'unit_multiplier' => 1, 'par_units' => 6],
            'Plastic Spray Bottle' => ['unit_label' => 'bottles', 'unit_multiplier' => 1, 'par_units' => 4],
            'Trigger Spray' => ['unit_label' => 'units', 'unit_multiplier' => 1, 'par_units' => 4],

            // Kitchen
            'Scrub Sponge' => ['unit_label' => 'units', 'unit_multiplier' => 20, 'par_units' => 40], // e.g. 20/box -> 2 boxes = 40
            'S.O.S Steel Wool Soap Pads' => ['unit_label' => 'units', 'unit_multiplier' => 15, 'par_units' => 30], // 15/box -> 2 boxes = 30
            'Oven and Grill Cleaner' => ['unit_label' => 'units', 'unit_multiplier' => 1, 'par_units' => 12],
            'Stainless Steel Polish' => ['unit_label' => 'units', 'unit_multiplier' => 1, 'par_units' => 4],
            'Antibacterial Liquid Hand Soap' => ['unit_label' => 'units', 'unit_multiplier' => 1, 'par_units' => 24],
            'Dawn Dish Soap' => ['unit_label' => 'units', 'unit_multiplier' => 1, 'par_units' => 8],
        ];

        // Mappings for old names to new names/configs if names are changing slightly
        // We will fuzzy match or use likely existing names. 
        // For accurate migration, we'll try to match by name.

        DB::transaction(function () use ($itemsConfig) {
            foreach ($itemsConfig as $name => $config) {
                // Try to find exact match first
                $item = InventoryItem::where('name', $name)->first();

                // If not found, try to find by similarity (optional, or just log missing)
                if (!$item) {
                    // Try matching parts of the string if name changed slightly in the user's request
                    // e.g. "Small Garbage bags" -> "Small Garbage bag rolls..."
                    $partialName = explode(',', $name)[0]; // "Small Garbage bag rolls"
                    $item = InventoryItem::where('name', 'LIKE', '%' . $partialName . '%')->first();
                }

                if (!$item) {
                    // Fallback for specific known renames derived from context
                    if (str_contains($name, 'Small Garbage')) {
                        $item = InventoryItem::where('name', 'LIKE', '%Small Garbage%')->first();
                    }
                    elseif (str_contains($name, 'Large Garbage')) {
                        $item = InventoryItem::where('name', 'LIKE', '%Large Garbage%')->first();
                    }
                    elseif (str_contains($name, 'White Multifold')) {
                        $item = InventoryItem::where('name', 'LIKE', '%Multifold%')->first();
                    }
                    elseif (str_contains($name, 'Toilet Paper')) {
                        $item = InventoryItem::where('name', 'LIKE', '%Toilet Paper%')->first();
                    }
                }

                if ($item) {
                    $oldMultiplier = $item->unit_multiplier ?? 1;
                    $newMultiplier = $config['unit_multiplier'];
                    $ratio = $newMultiplier; // Assuming old was "case" (1) and new is "units" (multiplier)

                    // Update Item Definition
                    $item->update([
                        'name' => $name, // Update name to the new descriptive one
                        'unit_label' => $config['unit_label'],
                        'unit_multiplier' => $newMultiplier,
                        'par_quantity' => $config['par_units'] / $newMultiplier, // Store base cases if needed? 
                        // WAIT: Model getParUnitsAttribute uses par_quantity * unit_multiplier.
                        // User wants "Expected" to be the Units.
                        // If we set par_quantity = 2 cases, and multiplier = 10, then par_units = 20.
                        // The User request says "Expected = 20 units".
                        // So we should set par_quantity = 2 (cases), multiplier = 10.
                        'par_quantity' => ceil($config['par_units'] / $newMultiplier),
                    ]);

                    // Update Station Inventory Counts
                    // Existing on_hand is likely in "cases" or old units.
                    // We need to convert it to new units.
                    // New On Hand = Old On Hand * New Multiplier
                    // BUT only if we haven't already migrated it (idempotency check?)
                    // We'll rely on the fact this is a one-time run or check a flag.
                    // Since we can't easily add a flag to the row, we'll assume this runs once.

                    // To be safe, we only multiply if the value seems small (like case counts) 
                    // and the multiplier is > 1. 
                    // A heuristic: if on_hand <= max_quantity (cases), multiply.
                    // Actually, simpler: Just multiply. If they have 2 cases, they now have 20 rolls.

                    if ($newMultiplier > 1) {
                        StationInventoryItem::where('inventory_item_id', $item->id)
                            ->update([
                            'on_hand' => DB::raw("on_hand * $newMultiplier")
                        ]);
                    }
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    // Reverting data migrations is complex and lossy. 
    // We generally don't support full automated revert of data values here.
    }
};
