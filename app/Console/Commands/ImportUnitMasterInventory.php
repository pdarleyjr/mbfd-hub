<?php

namespace App\Console\Commands;

use App\Models\UnitMasterVehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportUnitMasterInventory extends Command
{
    protected $signature = 'mbfd:import-unit-master-inventory 
                            {--path= : Path to the CSV file}
                            {--overwrite : Overwrite existing records including user-edited fields}';

    protected $description = 'Import vehicles from the Unit Master Inventory CSV file';

    protected array $headerColumns = ['Veh #', 'Make', 'Model', 'Year', 'Tag #', 'Dept.', 'Employee / Vehicle Name', 'Sunpass #', 'ALS License', 'Serial Number'];

    public function handle(): int
    {
        $path = $this->option('path') ?? storage_path('app/imports/unit_master_inventory.csv');
        $overwrite = $this->option('overwrite');

        if (!file_exists($path)) {
            $this->error("CSV file not found at: {$path}");
            return 1;
        }

        $this->info("Importing from: {$path}");
        $this->info("Overwrite mode: " . ($overwrite ? 'YES' : 'NO'));

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error("Could not open file");
            return 1;
        }

        $currentSection = null;
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $lineNumber = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;
            
            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            $firstCell = trim($row[0] ?? '');
            
            // Check if this is a section header (single non-empty cell, rest empty)
            if ($this->isSectionHeader($row)) {
                $currentSection = $firstCell;
                $this->info("Section: {$currentSection}");
                continue;
            }

            // Skip header rows
            if ($firstCell === 'Veh #' || $firstCell === 'Veh#') {
                continue;
            }

            // Skip non-data rows (no vehicle number)
            if (empty($firstCell) || !$this->looksLikeVehicleNumber($firstCell)) {
                continue;
            }

            // Parse data row
            $data = $this->parseDataRow($row, $currentSection);
            
            if (empty($data['veh_number'])) {
                $skipped++;
                continue;
            }

            // Import logic
            $record = UnitMasterVehicle::where('veh_number', $data['veh_number'])->first();

            if (!$record) {
                $record = new UnitMasterVehicle();
                $record->fill($data);
                $record->save();
                $imported++;
            } elseif ($overwrite) {
                $record->fill($data);
                $record->save();
                $updated++;
            } else {
                // Only fill empty fields (don't overwrite user edits)
                foreach ($data as $key => $value) {
                    if (empty($record->$key) && !empty($value)) {
                        $record->$key = $value;
                    }
                }
                $record->save();
                $updated++;
            }
        }

        fclose($handle);

        $this->info("Import complete!");
        $this->info("New records: {$imported}");
        $this->info("Updated records: {$updated}");
        $this->info("Skipped rows: {$skipped}");

        Log::info("Unit Master Inventory import completed", [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);

        return 0;
    }

    protected function isSectionHeader(array $row): bool
    {
        $nonEmpty = array_filter($row, fn($cell) => !empty(trim($cell)));
        if (count($nonEmpty) !== 1) {
            return false;
        }
        
        $first = trim($row[0] ?? '');
        // Section headers are typically text like "Fire Administration", "Fire Prevention", etc.
        return !empty($first) && 
               !is_numeric($first) && 
               !in_array($first, ['Veh #', 'Veh#', 'Make', 'Model']);
    }

    protected function looksLikeVehicleNumber(string $value): bool
    {
        // Vehicle numbers are typically numeric or alphanumeric
        return preg_match('/^[A-Za-z0-9\-]+$/', $value);
    }

    protected function parseDataRow(array $row, ?string $section): array
    {
        return [
            'veh_number' => trim($row[0] ?? ''),
            'make' => trim($row[1] ?? ''),
            'model' => trim($row[2] ?? ''),
            'year' => trim($row[3] ?? ''),
            'tag_number' => trim($row[4] ?? ''),
            'dept_code' => trim($row[5] ?? ''),
            'employee_or_vehicle_name' => trim($row[6] ?? ''),
            'sunpass_number' => trim($row[7] ?? ''),
            'als_license' => trim($row[8] ?? ''),
            'serial_number' => trim($row[9] ?? ''),
            'section' => $section,
        ];
    }
}
