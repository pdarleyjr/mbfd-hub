<?php

namespace App\Console\Commands;

use App\Models\Apparatus;
use Illuminate\Console\Command;

/**
 * Backfill apparatus vehicle details, mileage and engine hours from combined data sources.
 * 
 * Vehicle details from: MBFD Vehicle Master Status Sheet 01.20 (Master Inventory)
 * Mileage/Hours from: PM Cycles - Sheet1.csv (Latest PM data)
 * 
 * Usage: php artisan apparatus:backfill-pm-data
 */
class BackfillApparatusPmData extends Command
{
    protected $signature = 'apparatus:backfill-pm-data';
    protected $description = 'Backfill apparatus vehicle details, mileage, engine hours from Master Inventory and PM Cycles';

    /**
     * Combined data from Master Inventory (vehicle details) + PM Cycles (mileage/hours)
     * Format: [designation => [vehicle_number, year, make, model, miles, hours, service_type, unit_name]]
     */
    private array $combinedData = [
        'E1' => [
            'vehicle_number' => '20503',
            'year' => 2021,
            'make' => 'PIERCE',
            'model' => 'VELOCITY',
            'miles' => 27836,
            'hours' => 5228.0,
            'service_type' => 'PMC',
            'unit_name' => 'ENGINE 1',
        ],
        'E2' => [
            'vehicle_number' => '24509',
            'year' => null,
            'make' => null,
            'model' => null,
            'miles' => 5148,
            'hours' => 672.0,
            'service_type' => 'PMA',
            'unit_name' => 'ENGINE 2',
        ],
        'E21' => [
            'vehicle_number' => '00216',
            'master_vehicle_number' => '2-16',
            'year' => 2011,
            'make' => 'PIERCE',
            'model' => 'VELOCITY',
            'miles' => 62352,
            'hours' => 11662.0,
            'service_type' => 'PMA',
            'unit_name' => 'RESERVE ENGINE 21',
        ],
        'E3' => [
            'vehicle_number' => '00222',
            'master_vehicle_number' => '2-22',
            'year' => 2014,
            'make' => 'PIERCE',
            'model' => 'VELOCITY',
            'miles' => 76306,
            'hours' => 7299.0,
            'service_type' => 'PMA',
            'unit_name' => 'ENGINE 3',
        ],
        'E4' => [
            'vehicle_number' => '20504',
            'year' => 2021,
            'make' => 'PIERCE',
            'model' => 'VELOCITY',
            'miles' => 20940,
            'hours' => 3979.0,
            'service_type' => 'PMA',
            'unit_name' => 'ENGINE 4',
        ],
        'L1' => [
            'vehicle_number' => '00212',
            'master_vehicle_number' => '2-12',
            'year' => 2006,
            'make' => 'PIERCE',
            'model' => 'DASH-2000',
            'miles' => 79386,
            'hours' => 5367.0,
            'service_type' => 'PMC',
            'unit_name' => 'LADDER 1',
        ],
        'R1' => [
            'vehicle_number' => '16508',
            'year' => 2015,
            'make' => 'FREIGHTLINER',
            'model' => 'M2106',
            'miles' => 75218,
            'hours' => 15310.0,
            'service_type' => 'PMA',
            'unit_name' => 'RESCUE 1',
        ],
        'R11' => [
            'vehicle_number' => '19502',
            'year' => 2020,
            'make' => 'FREIGHTLINER',
            'model' => 'M2106',
            'miles' => 59881,
            'hours' => 9129.0,
            'service_type' => 'PMA',
            'unit_name' => 'RESCUE 11',
        ],
        'R2' => [
            'vehicle_number' => '16507',
            'year' => 2015,
            'make' => 'FREIGHTLINER',
            'model' => 'M2106',
            'miles' => 95206,
            'hours' => 15189.0,
            'service_type' => 'PMA',
            'unit_name' => 'RESCUE 2',
        ],
        'R22' => [
            'vehicle_number' => '19503',
            'year' => 2020,
            'make' => 'FREIGHTLINER',
            'model' => 'M2106',
            'miles' => 52788,
            'hours' => 8221.0,
            'service_type' => 'PMA',
            'unit_name' => 'RESCUE 22',
        ],
        'R3' => [
            'vehicle_number' => '17501',
            'year' => 2017,
            'make' => 'FREIGHTLINER',
            'model' => 'M2106',
            'miles' => 75563,
            'hours' => 574.0,
            'service_type' => 'PMC',
            'unit_name' => 'RESCUE 3',
        ],
        'R4' => [
            'vehicle_number' => '17502',
            'year' => 2017,
            'make' => 'FREIGHTLINER',
            'model' => 'M2106',
            'miles' => 77520,
            'hours' => 10872.0,
            'service_type' => 'PMA',
            'unit_name' => 'RESCUE 4',
        ],
        'R44' => [
            'vehicle_number' => '17503',
            'year' => 2017,
            'make' => 'FREIGHTLINER',
            'model' => 'M2106',
            'miles' => 73028,
            'hours' => 10045.0,
            'service_type' => 'PMA',
            'unit_name' => 'RESCUE 44',
        ],
    ];

    public function handle(): int
    {
        $this->info('Backfilling apparatus vehicle details and PM data...');
        $this->newLine();

        $updated = 0;
        $skipped = 0;

        foreach ($this->combinedData as $designation => $data) {
            // Try to find by designation first (E1, R1, L1, etc.)
            $apparatus = Apparatus::where('designation', $designation)
                ->orWhere('designation', str_replace('E', 'E ', $designation))
                ->orWhere('designation', str_replace('R', 'R ', $designation))
                ->orWhere('designation', str_replace('L', 'L ', $designation))
                ->first();

            // If not found, try by vehicle number
            if (!$apparatus) {
                $vehNum = $data['vehicle_number'];
                $masterVehNum = $data['master_vehicle_number'] ?? null;
                
                $apparatus = Apparatus::where('vehicle_number', $vehNum)
                    ->orWhere('vehicle_number', $masterVehNum)
                    ->first();
            }

            if (!$apparatus) {
                $this->warn("  Skipping {$designation}: No matching apparatus found in database");
                $skipped++;
                continue;
            }

            $oldValues = [
                'vehicle_number' => $apparatus->vehicle_number,
                'year' => $apparatus->year,
                'make' => $apparatus->make,
                'model' => $apparatus->model,
                'current_miles' => $apparatus->current_miles,
                'current_engine_hours' => $apparatus->current_engine_hours,
                'last_service_type' => $apparatus->last_service_type,
            ];

            // Update with combined data
            $apparatus->vehicle_number = $data['vehicle_number'];
            $apparatus->current_miles = $data['miles'];
            $apparatus->current_engine_hours = $data['hours'];
            $apparatus->last_service_type = $data['service_type'];
            
            // Update vehicle details from Master Inventory
            if ($data['year']) {
                $apparatus->year = $data['year'];
            }
            if ($data['make']) {
                $apparatus->make = $data['make'];
            }
            if ($data['model']) {
                $apparatus->model = $data['model'];
            }
            
            // Set PM baseline if not already set
            if (!$apparatus->last_pm_engine_hours) {
                $apparatus->last_pm_engine_hours = max(0, $data['hours'] - 300);
            }
            if (!$apparatus->last_pm_mileage) {
                $apparatus->last_pm_mileage = max(0, $data['miles'] - 5000);
            }

            $apparatus->save();

            $this->info("  ✓ {$designation} (ID: {$apparatus->id}) - {$data['unit_name']}");
            $this->line("    Vehicle #: {$oldValues['vehicle_number']} → {$data['vehicle_number']}");
            if ($data['year']) {
                $this->line("    Year: " . ($oldValues['year'] ?? 'NULL') . " → {$data['year']}");
            }
            if ($data['make']) {
                $this->line("    Make: " . ($oldValues['make'] ?? 'NULL') . " → {$data['make']}");
            }
            if ($data['model']) {
                $this->line("    Model: " . ($oldValues['model'] ?? 'NULL') . " → {$data['model']}");
            }
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