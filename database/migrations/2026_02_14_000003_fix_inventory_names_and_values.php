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
        // Correction List. 
        // We use fuzzy search terms to find the items.
        // Values are: [Search Term, New Name (Optional), Par, Unit Label]
        // We force unit_multiplier = 1 for all to simplify.
        // On_Hand will be reset to Par for consistency with user request "It should reflect...".

        $corrections = [
            // Garbage & Paper Goods
            // term, name, par, label
            ['Small Garbage', 'Small Garbage bag rolls, 25 bags/roll', 20, 'rolls'],
            ['Large Garbage', 'Large Garbage bag rolls, 10 bags/roll', 20, 'rolls'],
            ['Terry Towel', 'Box White Terry Towel Rags', 1, 'box'],
            ['Multifold', 'White Multifold Paper Towels (250 sheets/pack)', 16, 'packs'], // User req: 16
            ['Brown Paper', 'Brown Paper Towel Rolls (800 ft/roll)', 6, 'rolls'], // User req: 6
            ['Toilet Paper', 'Toilet Paper (500 sheets/roll)', 192, 'rolls'], // 2 cases
            ['White Paper Towel', 'White Paper Towel Rolls', 30, 'rolls'],
            ['Aerosol', 'Aerosol Disinfectant Deodorant', 12, 'cans'],

            // Floors
            ['General Purpose', 'General Purpose Floor Cleaner 1 gal', 8, 'gallons'],
            ['Degreaser', 'Cleaner Degreaser 1 gal', 16, 'gallons'],
            ['Dual Surface', '10" Dual Surface Scrub Brush', 3, 'units'],
            ['Green Swivel', '8" Green Swivel Scrub Brush', 3, 'units'],
            ['Feather Tip', '10" Feather Tip Vehicle Brush', 3, 'units'],
            ['Foam Floor', '22" Foam Floor Squeegee', 4, 'units'],
            ['Fiberglass Handle', '15/16" x 54" Fiberglass Handle', 4, 'units'],
            ['Garage Sweep', 'Garage Sweep Broom', 2, 'units'],
            ['Lobby Dust', 'Lobby Dust Pan', 1, 'unit'],
            ['Corn Housekeeping', 'Corn Housekeeping Broom', 2, 'units'],
            ['Mop Handle', 'Mop Handle', 2, 'units'],
            ['String Mop', 'Replacement String Mop Head', 12, 'units'],
            ['WaveBrake', 'Rubbermaid WaveBrake Bucket', 1, 'unit'],

            // Laundry
            ['Bleach', 'Bleach 1 gal', 12, 'gallons'],
            ['Laundry Detergent', 'Laundry Detergent 1 gal', 12, 'gallons'],

            // Bathroom
            ['Spic', 'Spic and Span Cleaner', 8, 'units'],
            ['Simple Green', 'Simple Green Concentrate', 1, 'unit'],
            ['Toilet Bowl', 'Toilet Bowl Cleaner', 12, 'units'],
            ['Sanitizing', 'Sanitizing Bathroom Cleaner Spray', 8, 'units'],
            ['Urinal', 'Urinal Screen', 12, 'units'],
            ['Trash Can', 'Rectangular Trash Can', 2, 'units'],
            ['Plunger', 'Toilet Plunger', 1, 'unit'],
            ['Toilet Brush', 'Toilet Brush', 6, 'units'],
            ['Plastic Spray', 'Plastic Spray Bottle', 4, 'bottles'],
            ['Trigger', 'Trigger Spray', 4, 'units'],

            // Kitchen
            ['Scrub Sponge', 'Scrub Sponge', 40, 'units'],
            ['Steel Wool', 'S.O.S Steel Wool Soap Pads', 30, 'units'],
            ['Oven', 'Oven and Grill Cleaner', 12, 'units'],
            ['Stainless', 'Stainless Steel Polish', 4, 'units'],
            ['Antibacterial', 'Antibacterial Liquid Hand Soap', 24, 'units'],
            ['Dawn', 'Dawn Dish Soap', 8, 'units'],
        ];

        DB::transaction(function () use ($corrections) {
            foreach ($corrections as $row) {
                $term = $row[0];
                $name = $row[1];
                $par = $row[2];
                $label = $row[3];

                // Fuzzy Match with ILIKE
                $item = InventoryItem::where('name', 'ILIKE', '%' . $term . '%')->first();

                if ($item) {
                    $item->update([
                        'name' => $name,
                        'par_quantity' => $par,
                        'unit_multiplier' => 1,
                        'unit_label' => $label,
                    ]);

                    // Reset On Hand to Par for visibility
                    StationInventoryItem::where('inventory_item_id', $item->id)
                        ->update(['on_hand' => $par]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    //
    }
};
