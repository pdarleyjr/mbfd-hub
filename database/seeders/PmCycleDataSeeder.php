<?php

namespace Database\Seeders;

use App\Models\Apparatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Seeds PM (Preventative Maintenance) cycle data from the PM Cycles CSV.
 * 
 * For each vehicle, takes the LAST (most recent) service entry as the baseline.
 * Sets both last_pm_* and current_* values to the same starting point since
 * no field inspections have submitted meter readings yet.
 */
class PmCycleDataSeeder extends Seeder
{
    /**
     * CSV data parsed from "PM Cycles - Sheet1.csv"
     * Format: [vehicle_number/designation => [miles, hours, service_type]]
     * Only the LAST entry per vehicle is kept (CSV is ordered oldest→newest).
     */
    public function run(): void
    {
        $csvData = $this->parseCsvData();
        $updated = 0;
        $skipped = 0;

        foreach ($csvData as $key => $data) {
            $vehicleNumber = $data['vehicle_number'];
            $designation   = $data['designation'];
            $miles         = $data['miles'];
            $hours         = $data['hours'];
            $serviceType   = $data['service_type'];

            // Try to match by vehicle_number first, then by designation
            $apparatus = Apparatus::where('vehicle_number', $vehicleNumber)->first();
            
            if (!$apparatus && $designation) {
                // Try matching designation (e.g., "E1" to "E 1")
                $apparatus = Apparatus::where('designation', $designation)->first();
                
                if (!$apparatus) {
                    // Try fuzzy match: "E1" → "E 1", "R22" → "R 22"
                    $fuzzy = preg_replace('/([A-Za-z]+)(\d+)/', '$1 $2', $designation);
                    $apparatus = Apparatus::where('designation', $fuzzy)->first();
                }
            }

            if (!$apparatus) {
                $this->command?->warn("  ✗ No match for: {$vehicleNumber} / {$designation}");
                $skipped++;
                continue;
            }

            $apparatus->update([
                'current_engine_hours'  => $hours,
                'current_miles'         => $miles,
                'last_pm_engine_hours'  => $hours,
                'last_pm_mileage'       => $miles,
                'last_pm_date'          => now()->toDateString(),
                'last_service_type'     => $serviceType,
                'pm_interval_hours'     => 300, // Default 300-hour cycle
            ]);

            $this->command?->info("  ✓ {$apparatus->designation} ({$vehicleNumber}): {$hours}h / {$miles}mi");
            $updated++;

            Log::info("PM Seed: {$apparatus->designation} set to {$hours}h / {$miles}mi");
        }

        $this->command?->info("\n  PM Cycle Data Seeded: {$updated} updated, {$skipped} skipped");
    }

    /**
     * Parse the PM Cycles CSV data.
     * Returns the LAST (most recent) entry per vehicle.
     */
    private function parseCsvData(): array
    {
        // Raw CSV data — last entry per vehicle wins
        $rawEntries = [
            // Format: [unit_number, designation, miles, hours, service_type]
            ['14501', 'Spare', 87113, 13616, 'PMA'],
            ['20503', 'E1', 27836, 5228, 'PMC'],
            ['20504', 'E4', 20940, 3979, 'PMA'],
            ['17501', 'R3', 75563, 574, 'PMC'],
            ['14500', 'Spare', 89206, 14510, 'PMC'],
            ['1036', 'Spare', 99238, 13630, 'PMC'],
            ['1035', 'Spare', 112605, 16013, 'PMC'],
            ['16507', 'R2', 95206, 15189, 'PMA'],
            ['19503', 'R22', 52788, 8221, 'PMA'],
            ['002-16', 'E21', 62352, 11662, 'PMA'],
            ['17502', 'R4', 77520, 10872, 'PMA'],
            ['1033', 'Spare', 89186, 950, 'PMC'],
            ['002-12', 'L1', 79386, 5367, 'PMC'],
            ['002-18', 'Spare', 83672, 14276, 'PMC'],
            ['002-22', 'E3', 76306, 7299, 'PMA'],
            ['17503', 'R44', 73028, 10045, 'PMA'],
            ['19502', 'R11', 59881, 9129, 'PMA'],
            ['24509', 'E2', 5148, 672, 'PMA'],
            ['16508', 'R1', 75218, 15310, 'PMA'],
        ];

        $data = [];
        foreach ($rawEntries as [$vehicleNumber, $designation, $miles, $hours, $serviceType]) {
            // Last entry wins (array key overwrites)
            $data[$vehicleNumber] = [
                'vehicle_number' => $vehicleNumber,
                'designation'    => $designation,
                'miles'          => $miles,
                'hours'          => $hours,
                'service_type'   => $serviceType,
            ];
        }

        return $data;
    }
}
