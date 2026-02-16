<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VendorProductMappingSeeder extends Seeder
{
    /**
     * Seed vendor product mappings from the CSV file
     * Maps station supply inventory items to their Grainger product equivalents
     */
    public function run(): void
    {
        // CSV mapping data: [sku_search_pattern => [vendor_name, vendor_sku, vendor_url]]
        $mappings = [
            'REN22505-CA' => ['Grainger', '5WG03', 'https://www.grainger.com/search?searchQuery=5WG03'],
            'REN66024-CA' => ['Grainger', '31DK79', 'https://www.grainger.com/search?searchQuery=31DK79'],
            'HOS537-10' => ['Grainger', '4HP38', 'https://www.grainger.com/search?searchQuery=4HP38'],
            'REN06002-WB' => ['Grainger', '38C404', 'https://www.grainger.com/search?searchQuery=38C404'],
            'REN06004-WB' => ['Grainger', '38X645', 'https://www.grainger.com/search?searchQuery=38X645'],
            '309116312' => ['Grainger', '32GV95', 'https://www.grainger.com/search?searchQuery=32GV95'],
            'GPT2717714' => ['Grainger', '39FK90', 'https://www.grainger.com/search?searchQuery=39FK90'],
            'SPA6075' => ['Grainger', '827PJ2', 'https://www.grainger.com/search?searchQuery=827PJ2'],
            '326445104' => ['Grainger', '449W25', 'https://www.grainger.com/search?searchQuery=449W25'],
            'REN051' => ['Grainger', '36P453', 'https://www.grainger.com/search?searchQuery=36P453'],
            'REN03953' => ['Grainger', '39AT06', 'https://www.grainger.com/search?searchQuery=39AT06'],
            'REN03964' => ['Grainger', '3YDT1', 'https://www.grainger.com/search?searchQuery=3YDT1'],
            '312441367' => ['Grainger', '43CK89', 'https://www.grainger.com/search?searchQuery=43CK89'],
            'CSM36622200' => ['Grainger', '6DTG1', 'https://www.grainger.com/search?searchQuery=6DTG1'],
            '322775340' => ['Grainger', '1VAC6', 'https://www.grainger.com/search?searchQuery=1VAC6'],
            'REN03984' => ['Grainger', '450Y49', 'https://www.grainger.com/search?searchQuery=450Y49'],
            'REN05125' => ['Grainger', '38TM03', 'https://www.grainger.com/search?searchQuery=38TM03'],
            'REN03997' => ['Grainger', '30E836', 'https://www.grainger.com/search?searchQuery=30E836'],
            '321381641' => ['Grainger', '14J833', 'https://www.grainger.com/search?searchQuery=14J833'],
            '318353671' => ['Grainger', '15F237', 'https://www.grainger.com/search?searchQuery=15F237'],
            'RCP758088YL' => ['Grainger', '5NY79', 'https://www.grainger.com/search?searchQuery=5NY79'],
            'KIK55GB' => ['Grainger', '43NR77', 'https://www.grainger.com/search?searchQuery=43NR77'],
            'SPA7003-04' => ['Grainger', '800WM3', 'https://www.grainger.com/search?searchQuery=800WM3'],
            'PGC58775' => ['Grainger', '4XKT3', 'https://www.grainger.com/search?searchQuery=4XKT3'],
            'SMP13005' => ['Grainger', '2GVN6', 'https://www.grainger.com/search?searchQuery=2GVN6'],
            'CLO00031' => ['Grainger', '1AU29', 'https://www.grainger.com/search?searchQuery=1AU29'],
            'PGC22569' => ['Grainger', '33NT92', 'https://www.grainger.com/search?searchQuery=33NT92'],
            'YYYSH-CLN' => ['Grainger', '5MLH0', 'https://www.grainger.com/search?searchQuery=5MLH0'],
            'RCP295700BG' => ['Grainger', '3U636', 'https://www.grainger.com/search?searchQuery=3U636'],
            'IMP9200-90' => ['Grainger', '1RLV8', 'https://www.grainger.com/search?searchQuery=1RLV8'],
            '311535529' => ['Grainger', '8ZG61', 'https://www.grainger.com/search?searchQuery=8ZG61'],
            'IMP5032HG-90' => ['Grainger', '3U593', 'https://www.grainger.com/search?searchQuery=3U593'],
            '313640723' => ['Grainger', '38C407', 'https://www.grainger.com/search?searchQuery=38C407'],
            'REN02118' => ['Grainger', '4HN92', 'https://www.grainger.com/search?searchQuery=4HN92'],
            'CLO88320' => ['Grainger', '3LFV7', 'https://www.grainger.com/search?searchQuery=3LFV7'],
            'REN05001-AM' => ['Grainger', '451D18', 'https://www.grainger.com/search?searchQuery=451D18'],
            '203759588' => ['Grainger', '53YZ87', 'https://www.grainger.com/search?searchQuery=53YZ87'],
            'DIA84014' => ['Grainger', '852HF5', 'https://www.grainger.com/search?searchQuery=852HF5'],
            'PGC45112' => ['Grainger', '802FY5', 'https://www.grainger.com/search?searchQuery=802FY5'],
        ];

        $updated = 0;
        $notFound = [];

        foreach ($mappings as $skuPattern => $vendorData) {
            // Try to find the inventory item by SKU
            $item = InventoryItem::where('sku', 'ILIKE', "%{$skuPattern}%")->first();

            if ($item) {
                $item->update([
                    'vendor_name' => $vendorData[0],
                    'vendor_sku' => $vendorData[1],
                    'vendor_url' => $vendorData[2],
                ]);
                $updated++;
                $this->command->info("✓ Updated: {$item->name} (SKU: {$item->sku}) → Grainger SKU: {$vendorData[1]}");
            } else {
                $notFound[] = $skuPattern;
                $this->command->warn("✗ Not found: {$skuPattern}");
            }
        }

        $this->command->newLine();
        $this->command->info("═══════════════════════════════════════════════════");
        $this->command->info("Vendor Mapping Summary:");
        $this->command->info("  Updated: {$updated} items");
        $this->command->info("  Not Found: " . count($notFound) . " items");
        
        if (count($notFound) > 0) {
            $this->command->newLine();
            $this->command->warn("Items not found in database:");
            foreach ($notFound as $sku) {
                $this->command->warn("  - {$sku}");
            }
            $this->command->newLine();
            $this->command->info("TIP: These items may need to be created in the database first,");
            $this->command->info("     or their SKU format may differ from the CSV.");
        }
        $this->command->info("═══════════════════════════════════════════════════");
    }
}
