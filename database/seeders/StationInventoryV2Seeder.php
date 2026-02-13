<?php

namespace Database\Seeders;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\Station;
use App\Models\StationInventoryItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StationInventoryV2Seeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Load the station supply list JSON
        $jsonPath = database_path('seeders/data/station_supply_list.json');
        
        if (!file_exists($jsonPath)) {
            $this->command->error("JSON file not found at: {$jsonPath}");
            return;
        }

        $data = json_decode(file_get_contents($jsonPath), true);
        
        if (!$data || !isset($data['categories'])) {
            $this->command->error("Invalid JSON structure in station_supply_list.json");
            return;
        }

        $this->command->info('Seeding inventory categories and items...');

        // Seed categories and items
        foreach ($data['categories'] as $categoryData) {
            $category = InventoryCategory::firstOrCreate(
                ['name' => $categoryData['name']],
                [
                    'sort_order' => $categoryData['sort_order'],
                    'active' => true,
                ]
            );

            $this->command->info("  Category: {$category->name}");

            // Seed items for this category
            foreach ($categoryData['items'] as $itemData) {
                $item = InventoryItem::firstOrCreate(
                    [
                        'category_id' => $category->id,
                        'sku' => $itemData['sku'],
                    ],
                    [
                        'name' => $itemData['name'],
                        'par_quantity' => $itemData['par_quantity'],
                        'active' => true,
                        'sort_order' => $itemData['sort_order'],
                    ]
                );

                $this->command->info("    Item: {$item->sku} - {$item->name} (PAR: {$item->par_quantity})");
            }
        }

        $this->command->info('Seeding station inventory items for all stations...');

        // Get all stations
        $stations = Station::all();
        $inventoryItems = InventoryItem::all();

        foreach ($stations as $station) {
            $this->command->info("  Station {$station->station_number}");

            foreach ($inventoryItems as $item) {
                StationInventoryItem::firstOrCreate(
                    [
                        'station_id' => $station->id,
                        'inventory_item_id' => $item->id,
                    ],
                    [
                        'on_hand' => $item->par_quantity,
                        'status' => 'ok',
                        'last_updated_at' => null,
                    ]
                );
            }
        }

        $this->command->info('Setting default PIN (1234) for all stations...');

        // Set default PIN for all stations that don't have one
        Station::whereNull('inventory_pin_hash')->update([
            'inventory_pin_hash' => Hash::make('1234'),
        ]);

        $this->command->info('Station Inventory v2 seeding complete!');
    }
}
