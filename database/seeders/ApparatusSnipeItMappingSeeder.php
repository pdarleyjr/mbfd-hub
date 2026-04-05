<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Apparatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Maps Hub apparatus records to their corresponding Snipe-IT asset IDs.
 * Run: php artisan db:seed --class=ApparatusSnipeItMappingSeeder
 */
class ApparatusSnipeItMappingSeeder extends Seeder
{
    public function run(): void
    {
        // Hub apparatus designation => [snipeit_asset_id, snipeit_asset_tag]
        $mappings = [
            // Front-line apparatus (these get daily inspections)
            'E 1'  => [29, 'APP-E1'],
            'E 2'  => [37, 'APP-E2'],
            'E 3'  => [49, 'APP-E3'],
            'E 4'  => [52, 'APP-E4'],
            'L 1'  => [30, 'APP-L1'],
            'L 3'  => [50, 'APP-L3'],
            'R 1'  => [32, 'APP-R1'],
            'R 11' => [33, 'APP-R11'],
            'R 2'  => [41, 'APP-R2'],
            'R 22' => [42, 'APP-R22'],
            'R 3'  => [51, 'APP-R3'],
            'R 4'  => [53, 'APP-R4'],
            'R 44' => [54, 'APP-R44'],
            'A 1'  => [35, 'APP-A1'],
            'A 2'  => [36, 'APP-A2'],

            // Reserve apparatus
            'E 11' => [38, 'APP-E11'],
            'E 21' => [39, 'APP-E21'],
            'E 31' => [40, 'APP-E31'],
            'L 11' => [31, 'APP-L11'],
        ];

        $updated = 0;

        foreach ($mappings as $designation => [$snipeitId, $snipeitTag]) {
            $apparatus = Apparatus::where('designation', $designation)->first();

            if ($apparatus) {
                $apparatus->update([
                    'snipeit_asset_id' => $snipeitId,
                    'snipeit_asset_tag' => $snipeitTag,
                ]);
                $updated++;
                $this->command?->info("Mapped {$designation} → Snipe-IT ID {$snipeitId} ({$snipeitTag})");
            } else {
                $this->command?->warn("Apparatus '{$designation}' not found in Hub database");
                Log::warning("[ApparatusMapping] Apparatus not found", ['designation' => $designation]);
            }
        }

        $this->command?->info("Mapped {$updated} apparatus to Snipe-IT assets.");
    }
}
