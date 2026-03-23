<?php

namespace App\Console\Commands;

use App\Models\Apparatus;
use Illuminate\Console\Command;

/**
 * Backfill apparatus mileage and engine hours from PM Cycles data.
 * 
 * Usage: php artisan apparatus:backfill-pm-data
 */
class BackfillApparatusPmData extends Command
{
    protected $signature = 'apparatus:backfill-pm-data';
    protected $description = 'Backfill apparatus mileage, engine hours, and service type from PM Cycles CSV data';

    /**
     * PM Cycle data extracted from docs/PM_Cycles.csv
     * Format: [designation => [vehicle_number, miles, hours, service_type]]
     */
    private array $pmData = [
        'E1' => ['vehicle_number' => '20503', 'miles' => 27836, 'hours' => 5228.0, 'service_type' => 'PMC'],
        'E2' => ['vehicle_number' => '24509', 'miles' => 5148, 'hours' => 672.0, 'service_type' => 'PMA'],
        'E21' => ['vehicle_number' => '00216', 'miles' => 62352, 'hours' => 11662.0, 'service_type' => 'PMA'],
        'E3' => ['vehicle_number' => '00222', 'miles' => 76306, 'hours' => 7299.0, 'service_type' => 'PMA'],
        'E4' => ['vehicle_number' => '20504', 'miles' => 20940, 'hours' => 3979.0, 'service_type' => 'PMA'],
        'L1' => ['vehicle_number' => '00212', 'miles' => 79386, 'hours' => 5367.0, 'service_type' => 'PMC'],
        'R1' => ['vehicle_number' => '16508', 'miles' => 75218, 'hours' => 15310.0, 'service_type' => 'PMA'],
        'R11' => ['vehicle_number' => '19502', 'miles' => 59881, 'hours' => 9129.0, 'service_type' => 'PMA'],
        'R2' => ['vehicle_number' => '16507', 'miles' => 95206, 'hours' => 15189.0, 'service_type' => 'PMA'],
        'R22' => ['vehicle_number' => '19503', 'miles' => 52788, 'hours' => 8221.0, 'service_type' => 'PMA'],
        'R3' => ['vehicle_number' => '17501', 'miles' => 75563, 'hours' => 574.0, 'service_type' => 'PMC'],
        'R4' => ['vehicle_number' => '17502', 'miles' => 77520, 'hours' => 10872.0, 'service_type' => 'PMA'],
        'R44' => ['vehicle_number' => '17503', 'miles' => 73028, 'hours' => 10045.0, 'service_type' => 'PMA'],
    ];

    public function handle(): int
    {
        $this->info('Backfilling apparatus PM data...');
        $this->newLine();

        $updated = 0;
        $created = 0;
        $skipped = 0;

        foreach ($this->pmData as $designation => $data) {
            // Try to find by designation first, then by vehicle_number
            $apparatus = Apparatus::where('designation', $designation)
                ->orWhere('vehicle_number', $designation)
                ->first();

            if (!$apparatus) {
                // Try normalized designation (e.g., "E 1" vs "E1")
                $normalizedDesignation = str_replace(' ', '', $designation);
                $apparatus = Apparatus::whereRaw("REPLACE(designation, ' ', '') = ?", [$normalizedDesignation])
                    ->first();
            }

            if (!$apparatus) {
                $this->warn("  Skipping {$designation}: No matching apparatus found in database");
                $skipped++;
                continue;
            }

            $oldValues = [
                'vehicle_number' => $apparatus->vehicle_number,
                'current_miles' => $apparatus->current_miles,
                'current_engine_hours' => $apparatus->current_engine_hours,
                'last_service_type' => $apparatus->last_service_type,
            ];

            // Update with PM data
            $apparatus->vehicle_number = $data['vehicle_number'];
            $apparatus->current_miles = $data['miles'];
            $apparatus->current_engine_hours = $data['hours'];
            $apparatus->last_service_type = $data['service_type'];
            
            // Set PM baseline if not already set
            if (!$apparatus->last_pm_engine_hours) {
                $apparatus->last_pm_engine_hours = max(0, $data['hours'] - 300);
            }
            if (!$apparatus->last_pm_mileage) {
                $apparatus->last_pm_mileage = max(0, $data['miles'] - 5000);
            }

            $apparatus->save();

            $this->info("  ✓ {$designation} (ID: {$apparatus->id})");
            $this->line("    Vehicle #: {$oldValues['vehicle_number']} → {$data['vehicle_number']}");
            $this->line("    Miles: " . ($oldValues['current_miles'] ?? 'NULL') . " → " . number_format($data['miles']));
            $this->line("    Hours: " . ($oldValues['current_engine_hours'] ?? 'NULL') . " → " . number_format($data['hours'], 1));
            $this->line("    Service: " . ($oldValues['last_service_type'] ?? 'NULL') . " → {$data['service_type']}");
            $this->newLine();

            $updated++;
        }

        $this->info("Summary:");
        $this->info("  Updated: {$updated}");
        $this->info("  Skipped: {$skipped}");

        return Command::SUCCESS;
    }
}