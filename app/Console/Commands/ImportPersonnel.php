<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Identity\EmployeeBootstrapCredentialProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ImportPersonnel extends Command
{
    protected $signature = 'mbfd:import-personnel {file : Path to CSV file with Name,Rank,EmployeeID columns}
                            {--dry-run : Preview without creating records}';

    protected $description = 'Import fire department personnel into the operational employee profile table';

    public function handle(EmployeeBootstrapCredentialProvisioner $bootstrap): int
    {
        $filePath = $this->argument('file');
        $isDryRun = $this->option('dry-run');
        if (! file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return Command::FAILURE;
        }

        $handle = fopen($filePath, 'r');
        if (! $handle) {
            $this->error("Cannot open file: {$filePath}");

            return Command::FAILURE;
        }

        $header = fgetcsv($handle);
        if (! $header) {
            $this->error('CSV file is empty or unreadable.');
            fclose($handle);

            return Command::FAILURE;
        }

        $header = array_map('strtolower', array_map('trim', $header));
        $nameIdx = $this->findColumn($header, ['name', 'full name', 'fullname', 'employee name']);
        $rankIdx = $this->findColumn($header, ['rank', 'position', 'title']);
        $empIdIdx = $this->findColumn($header, ['employee id', 'employee_id', 'employeeid', 'id', 'emp id', 'empid']);

        if ($nameIdx === false || $empIdIdx === false) {
            $this->error('CSV must contain at minimum "Name" and "Employee ID" columns.');
            fclose($handle);

            return Command::FAILURE;
        }

        $created = $skipped = $updated = 0;
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $name = trim($row[$nameIdx] ?? '');
            $rank = $rankIdx !== false ? trim($row[$rankIdx] ?? '') : '';
            $employeeId = trim($row[$empIdIdx] ?? '');

            if (empty($name) || empty($employeeId)) {
                $skipped++;

                continue;
            }
            $rows[] = compact('name', 'rank', 'employeeId');
        }
        fclose($handle);

        $this->info('Found '.count($rows)." valid rows, {$skipped} skipped.");

        if ($isDryRun) {
            $this->info('[DRY RUN] No records created.');

            return Command::SUCCESS;
        }

        DB::beginTransaction();
        try {
            foreach ($rows as $data) {
                $existing = Employee::where('employee_id', $data['employeeId'])->first();

                if ($existing) {
                    $existing->update([
                        'name' => $data['name'],
                        'rank' => $data['rank'] ?: $existing->rank,
                    ]);
                    $updated++;
                    $this->line("Updated Employee DB ID: {$existing->id}; compatibility hash unchanged");

                    continue;
                }

                $employee = Employee::create([
                    'name' => $data['name'],
                    'employee_id' => $data['employeeId'],
                    'rank' => $data['rank'],
                    ...$bootstrap->attributesForNewEmployee(),
                ]);
                $created++;
                $this->line("Created first-login-ready Employee DB ID: {$employee->id}");
            }

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->error('Import failed; no employee changes were committed: '.$exception->getMessage());

            return Command::FAILURE;
        }

        $this->newLine();
        $this->info("Import complete — Created: {$created}, Updated profiles: {$updated}, Skipped: {$skipped}");

        return Command::SUCCESS;
    }

    private function findColumn(array $header, array $candidates): int|false
    {
        foreach ($candidates as $candidate) {
            $idx = array_search($candidate, $header);
            if ($idx !== false) {
                return $idx;
            }
        }

        return false;
    }
}
