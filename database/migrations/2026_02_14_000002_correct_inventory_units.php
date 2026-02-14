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
        // Define corrections based on User's explicit request.
        // We set unit_multiplier = 1 for all to simplify "Expected" = "Par"
        // We update on_hand to the user's "Reset" value.
        $corrections = [
            // Garbage & Paper Goods
            ['Small Garbage bag rolls, 25 bags/roll', 20, 20, 'rolls'],
            ['Large Garbage bag rolls, 10 bags/roll', 20, 20, 'rolls'],
            ['Box White Terry Towel Rags', 1, 1, 'box'],
            ['White Multifold Paper Towels (250 sheets/pack)', 64, 64, 'packs'],
            ['Brown Paper Towel Rolls (800 ft/roll)', 24, 24, 'rolls'],
            ['Toilet Paper (500 sheets/roll)', 192, 192, 'rolls'],
            ['White Paper Towel Rolls', 30, 30, 'rolls'], // "rolls" inferred from previous context, user said "30 rolls"
            ['Aerosol Disinfectant Deodorant', 12, 12, 'cans'],

            // Floors
            ['General Purpose Floor Cleaner 1 gal', 8, 8, 'gallons'],
            ['Cleaner Degreaser 1 gal', 16, 16, 'gallons'],
            ['10" Dual Surface Scrub Brush', 3, 3, 'units'],
            ['8" Green Swivel Scrub Brush', 3, 3, 'units'],
            ['10" Feather Tip Vehicle Brush', 3, 3, 'units'],
            ['22" Foam Floor Squeegee', 4, 4, 'units'],
            ['15/16" x 54" Fiberglass Handle', 4, 4, 'units'],
            ['Garage Sweep Broom', 2, 2, 'units'],
            ['Lobby Dust Pan', 1, 1, 'unit'],
            ['Corn Housekeeping Broom', 2, 2, 'units'],
            ['Mop Handle', 2, 2, 'units'],
            ['Replacement String Mop Head', 12, 12, 'units'],
            ['Rubbermaid WaveBrake Bucket', 1, 1, 'unit'],

            // Laundry
            ['Bleach 1 gal', 12, 12, 'gallons'],
            ['Laundry Detergent 1 gal', 12, 12, 'gallons'],

            // Bathroom & Cleaners
            ['Spic and Span Cleaner', 8, 8, 'units'],
            ['Simple Green Concentrate', 1, 1, 'unit'],
            ['Toilet Bowl Cleaner', 12, 12, 'units'],
            ['Sanitizing Bathroom Cleaner Spray', 8, 8, 'units'],
            ['Urinal Screen', 12, 12, 'units'],
            ['Rectangular Trash Can', 2, 2, 'units'],
            ['Toilet Plunger', 1, 1, 'unit'],
            ['Toilet Brush', 6, 6, 'units'],
            ['Plastic Spray Bottle', 4, 4, 'bottles'],
            ['Trigger Spray', 4, 4, 'units'],

            // Kitchen
            ['Scrub Sponge', 40, 40, 'units'],
            ['S.O.S Steel Wool Soap Pads', 30, 30, 'units'],
            ['Oven and Grill Cleaner', 12, 12, 'units'],
            ['Stainless Steel Polish', 4, 4, 'units'],
            ['Antibacterial Liquid Hand Soap', 24, 24, 'units'],
            ['Dawn Dish Soap', 8, 8, 'units'],
        ];

        DB::transaction(function () use ($corrections) {
            foreach ($corrections as $row) {
                $name = $row[0];
                $expected = $row[1];
                $onHand = $row[2];
                $label = $row[3];

                // Find item
                $item = InventoryItem::where('name', $name)->first();

                // Fuzzy match fallback if needed
                if (!$item) {
                    $partialName = explode(',', $name)[0];
                    $item = InventoryItem::where('name', 'LIKE', '%' . $partialName . '%')->first();
                }

                if ($item) {
                    // Update Definition
                    $item->update([
                        'par_quantity' => $expected,
                        'unit_multiplier' => 1,
                        'unit_label' => $label,
                    ]);

                    // Reset On Hand for ALL stations to the "Ideal" on hand value
                    // This ensures the user sees the numbers they requested.
                    StationInventoryItem::where('inventory_item_id', $item->id)
                        ->update(['on_hand' => $onHand]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    // No reverse for data corrections
    }
};
