<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Under25kProject;
use Carbon\Carbon;

class Under25kProjectsFromCSVSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Starting Under 25k Projects CSV import...');

        // Path to the correct CSV file
        $csvPath = 'C:\Users\Peter Darley\Downloads\under_25k_fire_dept - Sheet1.csv';

        if (!file_exists($csvPath)) {
            $this->command->error("CSV file not found at: {$csvPath}");
            return;
        }

        $this->command->info("Reading CSV file from: {$csvPath}");

        // Read the CSV file
        $csvData = $this->readCSV($csvPath);

        if (empty($csvData)) {
            $this->command->error('CSV file is empty or could not be read.');
            return;
        }

        $this->command->info("Found " . count($csvData) . " rows in CSV (including header)");

        // Remove header row
        $header = array_shift($csvData);

        $this->command->info('CSV Headers: ' . implode(', ', $header));

        // Import data
        $imported = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($csvData as $rowIndex => $row) {
            try {
                // Map CSV columns to database fields
                $projectData = $this->mapCSVRowToProject($row);

                // Check if project already exists by name
                $existingProject = Under25kProject::where('name', $projectData['name'])->first();

                if ($existingProject) {
                    // Update existing project
                    $existingProject->update($projectData);
                    $updated++;
                    $this->command->info("Updated project: {$projectData['name']}");
                } else {
                    // Create new project
                    Under25kProject::create($projectData);
                    $imported++;
                    $this->command->info("Imported project: {$projectData['name']}");
                }
            } catch (\Exception $e) {
                $this->command->error("Error processing row " . ($rowIndex + 2) . ": " . $e->getMessage());
                $skipped++;
            }
        }

        $this->command->info('Import completed!');
        $this->command->info("Imported: {$imported} new projects");
        $this->command->info("Updated: {$updated} existing projects");
        $this->command->info("Skipped: {$skipped} rows due to errors");
    }

    /**
     * Read CSV file and return array of rows
     */
    private function readCSV($filePath): array
    {
        $rows = [];
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            return $rows;
        }

        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            $rows[] = $data;
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Map CSV row to Under25kProject model fields
     */
    private function mapCSVRowToProject(array $row): array
    {
        // CSV column mapping (0-indexed):
        // 0: FACILITY / PROJECT NAME
        // 1: DESCRIPTION / NOTES
        // 2: STATUS / PROGRESS
        // 3: START DATE
        // 4: COMPLETION DATE
        // 5: ZONE
        // 6: MIAMI BEACH AREA
        // 7: ASSIGNED TO
        // 8: MUNIS ADOPTED/AMENDED
        // 9: MUNIS TRANSF. IN / OUT
        // 10: MUNIS - REVISED BUDGET
        // 11: INTERNAL TRANSF. IN / OUT
        // 12: INT. - REVISED BUDGET
        // 13: REQUISITIONS
        // 14: ACTUAL EXPENSES
        // 15: PROJECT BAL. / SAVINGS
        // 16: LAST COMMENT DATE
        // 17: LATEST COMMENT
        // 18: VFA UPDATE
        // 19: VFA UPDATE DATE

        return [
            'name' => $this->cleanString($row[0] ?? ''),
            'description' => $this->cleanString($row[1] ?? ''),
            'status' => $this->cleanString($row[2] ?? 'Not Started'),
            'start_date' => $this->parseDate($row[3] ?? null),
            'target_completion_date' => $this->parseDate($row[4] ?? null),
            'zone' => $this->cleanString($row[5] ?? ''),
            'miami_beach_area' => $this->cleanString($row[6] ?? ''),
            'project_manager' => $this->cleanString($row[7] ?? ''),
            'munis_adopted_amended' => $this->parseCurrency($row[8] ?? 0),
            'munis_transfers_in_out' => $this->parseCurrency($row[9] ?? 0),
            'munis_revised_budget' => $this->parseCurrency($row[10] ?? 0),
            'internal_transfers_in_out' => $this->parseCurrency($row[11] ?? 0),
            'internal_revised_budget' => $this->parseCurrency($row[12] ?? 0),
            'requisitions' => $this->parseCurrency($row[13] ?? 0),
            'actual_expenses' => $this->parseCurrency($row[14] ?? 0),
            'project_balance_savings' => $this->parseCurrency($row[15] ?? 0),
            'last_comment_date' => $this->parseDate($row[16] ?? null),
            'latest_comment' => $this->cleanString($row[17] ?? ''),
            'vfa_update' => $this->cleanString($row[18] ?? ''),
            'vfa_update_date' => $this->parseDate($row[19] ?? null),
            // Set default values for required fields
            'project_number' => $this->generateProjectNumber($row[0] ?? ''),
            'budget_amount' => $this->parseCurrency($row[10] ?? 0), // Use MUNIS Revised Budget as budget
            'spend_amount' => $this->parseCurrency($row[14] ?? 0), // Use Actual Expenses as spend
            'priority' => 'Medium',
            'percent_complete' => $this->calculatePercentComplete($row[2] ?? 'Not Started'),
        ];
    }

    /**
     * Clean string by trimming and removing extra whitespace
     */
    private function cleanString(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Parse currency value (e.g., "$24,900.00" -> 24900.00)
     */
    private function parseCurrency($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        // Remove currency symbols, commas, and whitespace
        $cleaned = preg_replace('/[^0-9.\-]/', '', (string)$value);
        
        $parsed = floatval($cleaned);
        
        return $parsed;
    }

    /**
     * Parse date value (e.g., "12/11/25" -> "2025-12-11")
     */
    private function parseDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            // Try parsing as MM/DD/YY or MM/DD/YYYY
            $date = Carbon::parse($value);
            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Generate project number from project name
     */
    private function generateProjectNumber(string $name): string
    {
        // Extract first few words and create a project number
        $words = explode(' ', $name);
        $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $words[0] ?? 'PRJ'), 0, 4));
        $suffix = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        return $prefix . '-' . $suffix;
    }

    /**
     * Calculate percent complete from status
     */
    private function calculatePercentComplete(string $status): int
    {
        $status = strtolower($status);
        
        if (strpos($status, 'complete') !== false || strpos($status, '100%') !== false) {
            return 100;
        } elseif (strpos($status, '75%') !== false) {
            return 75;
        } elseif (strpos($status, '50%') !== false) {
            return 50;
        } elseif (strpos($status, '25%') !== false) {
            return 25;
        } elseif (strpos($status, 'in progress') !== false || strpos($status, 'started') !== false) {
            return 50;
        } elseif (strpos($status, 'not started') !== false) {
            return 0;
        }
        
        return 0;
    }
}
