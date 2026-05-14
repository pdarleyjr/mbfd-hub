<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;

class ImportPersonnel extends Command
{
    protected $signature = 'mbfd:import-personnel {file : Path to CSV file with Name,Rank,EmployeeID columns}
                            {--dry-run : Preview without creating records}
                            {--password= : Default password (falls back to config employee.default_temp_password / env EMPLOYEE_DEFAULT_TEMP_PASSWORD)}';

    protected $description = 'Import fire department personnel into the employees table for the Employee Portal';

    public function handle(): int
    {
        $filePath = $this->argument('file');
        $isDryRun = $this->option('dry-run');
        $defaultPassword = $this->option('password')
            ?: config('employee.default_temp_password', env('EMPLOYEE_DEFAULT_TEMP_PASSWORD', ''));
        if ($defaultPassword === '') {
            $this->error('No default password specified. Pass --password=<value> or set config employee.default_temp_password / env EMPLOYEE_DEFAULT_TEMP_PASSWORD.');
            return Command::FAILURE;
        }

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

        $header  = array_map('strtolower', array_map('trim', $header));
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
            $name       = trim($row[$nameIdx] ?? '');
            $rank       = $rankIdx !== false ? trim($row[$rankIdx] ?? '') : '';
            $employeeId = trim($row[$empIdIdx] ?? '');

            if (empty($name) || empty($employeeId)) {
                $skipped++;
                continue;
            }
            $rows[] = compact('name', 'rank', 'employeeId');
        }
        fclose($handle);

        $this->info("Found " . count($rows) . " valid rows, {$skipped} skipped.");

        if ($isDryRun) {
            $this->table(['Name', 'Rank', 'Employee ID'], array_map(
                fn ($r) => [$r['name'], $r['rank'], $r['employeeId']],
                $rows
            ));
            $this->info('[DRY RUN] No records created.');
            return Command::SUCCESS;
        }

        foreach ($rows as $data) {
            $existing = Employee::where('employee_id', $data['employeeId'])->first();

            if ($existing) {
                $existing->update([
                    'rank'                 => $data['rank'] ?: $existing->rank,
                    'password'             => $defaultPassword,
                    'must_change_password' => true,
                ]);
                $updated++;
                $this->line("  Updated: {$data['name']} ({$data['employeeId']})");
                continue;
            }

            Employee::create([
                'name'                 => $data['name'],
                'employee_id'          => $data['employeeId'],
                'rank'                 => $data['rank'],
                'password'             => $defaultPassword,
                'must_change_password' => true,
            ]);
            $created++;
            $this->line("  Created: {$data['name']} ({$data['employeeId']})");
        }

        $this->newLine();
        $this->info("✅ Import complete — Created: {$created}, Updated: {$updated}, Skipped: {$skipped}");
        $this->info("Default password: {$defaultPassword}");
        $this->warn('All employees flag must_change_password=true — will be prompted on first login.');

        return Command::SUCCESS;
    }

    private function findColumn(array $header, array $candidates): int|false
    {
        foreach ($candidates as $candidate) {
            $idx = array_search($candidate, $header);
            if ($idx !== false) return $idx;
        }
        return false;
    }
}
