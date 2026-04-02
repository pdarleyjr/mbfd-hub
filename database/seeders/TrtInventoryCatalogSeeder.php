<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TrtInventoryCatalogItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class TrtInventoryCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = database_path('seeders/data/trt_inventory_catalog.json');
        $data = json_decode(File::get($jsonPath), true);

        foreach ($data['categories'] as $category) {
            foreach ($category['items'] as $item) {
                TrtInventoryCatalogItem::firstOrCreate(
                    [
                        'name' => $item['name'],
                        'category' => $category['name'],
                    ],
                    [
                        'expected_quantity' => $item['expected_quantity'],
                        'sort_order' => $item['sort_order'],
                        'active' => true,
                    ]
                );
            }
        }

        $this->command->info('TRT Inventory Catalog seeded: ' . TrtInventoryCatalogItem::count() . ' items.');
    }
}
