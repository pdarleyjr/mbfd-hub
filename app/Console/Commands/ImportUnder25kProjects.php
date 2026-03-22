<?php

namespace App\Console\Commands;

use App\Models\Under25kProject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ImportUnder25kProjects extends Command
{
    protected $signature = 'mbfd:import-under25k 
                            {--path= : Path to CSV file}
                            {--overwrite : Overwrite existing data}';

    protected $description = 'Import Under25k projects from CSV file';

    protected array $stats = [
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => [],
    ];

    // CSV column to database field mapping
    protected array $columnMapping = [
        'FACILITY / PROJECT NAME' => 'name',
        'DESCRIPTION / NOTES' => 'description',
        'STATUS / PROGRESS' => 'status',
        'START DATE' => 'start_date',
        'COMPLETION DATE' => 'actual_completion_date',
        'ASSIGNED TO' => 'project_manager',
        'MUNIS - REVISED BUDGET' => 'budget_amount',
        'ACTUAL EXPENSES' => 'spend_amount',
        'LATEST COMMENT' => 'notes',
    ];

    // Additional columns to store in internal_notes
    protected array $additionalColumns = [
        'ZONE',
        'MIAMI BEACH AREA',
        'MUNIS ADOPTED/AMENDED',
        'MUNIS TRANSF. IN / OUT',
        'INTERNAL TRANSF. IN / OUT',
        'INT. - REVISED BUDGET',
        'REQUISITIONS',
        'PROJECT BAL. / SAVINGS',
        'LAST COMMENT DATE',
        'VFA UPDATE',
        'VFA UPDATE DATE',
    ];

    public function handle(): int
    {
        // Step 1: Determine CSV path
        $path = $this->option('path');
        
        if (!$path) {
            $this->error('--path option is required');
            return Command::FAILURE;
        }

        if (!file_exists($path)) {
            $this->error("CSV file not found at: {$path}");
            return Command::FAILURE;
        }

        $this->info("Reading CSV from: {$path}");

        // Step 2: Read CSV
        $rows = $this->readCsv($path);
        
        if (empty($rows)) {
            $this->error('No data found in CSV file');
            return Command::FAILURE;
        }

        // Step 3: Display preview
        $this->displayPreview($rows);

        // Step 4: Confirm import
        if (!$this->confirm('Proceed with import?', true)) {
            $this->info('Import cancelled');
            return Command::SUCCESS;
        }

        // Step 5: Import data
        $this->info('Starting import...');
        
        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                $this->processRow($row, $index);
            }
            
            DB::commit();
            $this->info('Import completed successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Import failed: {$e->getMessage()}");
            $this->error("Stack trace: {$e->getTraceAsString()}");
            return Command::FAILURE;
        }

        // Step 6: Display statistics
        $this->displayStats();

        return Command::SUCCESS;
    }

    protected function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        
        if (!$handle) {
            throw new \Exception("Could not open CSV file: {$path}");
        }

        // Read headers
        $headers = fgetcsv($handle);
        
        if (!$headers) {
            fclose($handle);
            throw new \Exception('CSV file is empty');
        }

        // Normalize headers (trim whitespace)
        $headers = array_map('trim', $headers);

        // Process rows
        $lineNumber = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $lineNumber++;
            
            // Skip if not enough columns
            if (count($data) < count($headers)) {
                continue;
            }

            // Create associative array
            $row = array_combine($headers, $data);
            
            // Skip separator rows: facility_project_name equals "1" and all other fields are empty
            $facilityName = trim($row['FACILITY / PROJECT NAME'] ?? '');
            if ($facilityName === '1' && $this->isRowEmptyExcept($row, 'FACILITY / PROJECT NAME')) {
                $this->stats['skipped']++;
                continue;
            }

            // Skip if facility/project name is empty
            if (empty($facilityName)) {
                $this->stats['skipped']++;
                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);
        
        $this->info("Parsed {$lineNumber} lines, found " . count($rows) . " valid project rows");
        
        return $rows;
    }

    protected function isRowEmptyExcept(array $row, string $exceptKey): bool
    {
        foreach ($row as $key => $value) {
            if ($key === $exceptKey) {
                continue;
            }
            if (!empty(trim($value))) {
                return false;
            }
        }
        return true;
    }

    protected function displayPreview(array $rows): void
    {
        $this->info("Found " . count($rows) . " rows to import");
        
        // Show unique statuses
        $uniqueStatuses = array_unique(array_filter(array_map(fn($r) => trim($r['STATUS / PROGRESS'] ?? ''), $rows)));
        $this->info("Unique statuses: " . implode(', ', $uniqueStatuses));
        
        // Show unique zones
        $uniqueZones = array_unique(array_filter(array_map(fn($r) => trim($r['ZONE'] ?? ''), $rows)));
        $this->info("Unique zones: " . implode(', ', $uniqueZones));
        
        // Show first 5 rows in table format
        $this->newLine();
        $this->info("Sample rows (first 5):");
        $preview = array_slice($rows, 0, 5);
        
        $previewData = array_map(function($row) {
            return [
                'Name' => substr($row['FACILITY / PROJECT NAME'] ?? '', 0, 30),
                'Status' => $row['STATUS / PROGRESS'] ?? '',
                'Start Date' => $row['START DATE'] ?? '',
                'Budget' => $row['MUNIS - REVISED BUDGET'] ?? '',
                'Assigned To' => $row['ASSIGNED TO'] ?? '',
            ];
        }, $preview);
        
        $this->table(
            ['Name', 'Status', 'Start Date', 'Budget', 'Assigned To'],
            $previewData
        );
    }

    protected function processRow(array $row, int $index): void
    {
        $facilityName = trim($row['FACILITY / PROJECT NAME'] ?? '');

        // Find existing record by name
        $project = Under25kProject::where('name', $facilityName)->first();
        $isUpdate = $project !== null;
        $overwrite = $this->option('overwrite');

        // Prepare data
        $data = $this->prepareData($row);

        if ($isUpdate) {
            if ($overwrite) {
                // Update all fields
                $project->update($data);
                $this->stats['updated']++;
            } else {
                // Only update null/empty fields
                foreach ($data as $key => $value) {
                    if ($value !== null && $this->isFieldEmpty($project, $key)) {
                        $project->$key = $value;
                    }
                }
                $project->save();
                $this->stats['updated']++;
            }
        } else {
            // Create new record
            Under25kProject::create($data);
            $this->stats['created']++;
        }
    }

    protected function isFieldEmpty(Under25kProject $project, string $key): bool
    {
        $value = $project->$key;
        
        // Check for null
        if ($value === null) {
            return true;
        }
        
        // Check for empty string
        if (is_string($value) && trim($value) === '') {
            return true;
        }
        
        // Check for empty array
        if (is_array($value) && empty($value)) {
            return true;
        }
        
        return false;
    }

    protected function prepareData(array $row): array
    {
        $data = [];

        // Map main columns
        foreach ($this->columnMapping as $csvColumn => $dbField) {
            $value = $row[$csvColumn] ?? null;
            
            if ($value !== null) {
                $value = trim($value);
                
                // Skip empty values
                if ($value === '') {
                    continue;
                }

                // Parse based on field type
                if (in_array($dbField, ['budget_amount', 'spend_amount'])) {
                    $data[$dbField] = $this->parseCurrency($value);
                } elseif (in_array($dbField, ['start_date', 'actual_completion_date'])) {
                    $data[$dbField] = $this->parseDate($value);
                } else {
                    $data[$dbField] = $value;
                }
            }
        }

        // Build internal_notes from additional columns
        $internalNotes = [];
        foreach ($this->additionalColumns as $column) {
            $value = trim($row[$column] ?? '');
            if (!empty($value)) {
                $internalNotes[] = "{$column}: {$value}";
            }
        }
        
        if (!empty($internalNotes)) {
            $data['internal_notes'] = implode("\n", $internalNotes);
        }

        return $data;
    }

    protected function parseCurrency(string $value): ?float
    {
        // Strip $ and commas
        $cleaned = str_replace(['$', ','], '', $value);
        
        // Try to parse as float
        $parsed = floatval($cleaned);
        
        // Return null if parsing failed (result is 0 and original wasn't "0" or "0.00")
        if ($parsed == 0 && !in_array($cleaned, ['0', '0.00', '0.0'])) {
            return null;
        }
        
        return $parsed;
    }

    protected function parseDate(string $value): ?string
    {
        if (empty(trim($value))) {
            return null;
        }

        try {
            $date = Carbon::parse($value);
            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to parse date '{$value}': {$e->getMessage()}";
            return null;
        }
    }

    protected function displayStats(): void
    {
        $this->newLine();
        $this->info('=== Import Statistics ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Records Created', $this->stats['created']],
                ['Records Updated', $this->stats['updated']],
                ['Rows Skipped', $this->stats['skipped']],
            ]
        );

        if (!empty($this->stats['errors'])) {
            $this->newLine();
            $this->warn('=== Errors ===');
            foreach (array_slice($this->stats['errors'], 0, 20) as $error) {
                $this->warn("• {$error}");
            }
            if (count($this->stats['errors']) > 20) {
                $this->warn("... and " . (count($this->stats['errors']) - 20) . " more errors");
            }
        }
    }
}
