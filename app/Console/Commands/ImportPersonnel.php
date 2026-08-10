<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ImportPersonnel extends Command
{
    protected $signature = 'mbfd:import-personnel {file : Path to CSV file with Name,Rank,EmployeeID columns}
                            {--dry-run : Preview without creating records}
                            {--credentials-output= : New file for unique onboarding credentials; required when creating employees}';

    protected $description = 'Import fire department personnel into the employees table for the Employee Portal';

    public function handle(): int
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
            $this->table(['Name', 'Rank', 'Employee ID'], array_map(
                fn ($r) => [$r['name'], $r['rank'], $r['employeeId']],
                $rows
            ));
            $this->info('[DRY RUN] No records created.');

            return Command::SUCCESS;
        }

        $employeeIds = array_column($rows, 'employeeId');
        $existingIds = Employee::query()
            ->whereIn('employee_id', $employeeIds)
            ->pluck('employee_id')
            ->all();
        $newEmployeeIds = array_values(array_diff($employeeIds, $existingIds));
        $credentialsOutput = $this->option('credentials-output');

        if ($newEmployeeIds !== [] && blank($credentialsOutput)) {
            $this->error('New employees require --credentials-output=<new-file.csv> so each unique temporary password can be delivered securely.');

            return Command::FAILURE;
        }

        $credentialsHandle = null;
        if ($newEmployeeIds !== []) {
            $credentialsHandle = @fopen((string) $credentialsOutput, 'x');
            if ($credentialsHandle === false) {
                $this->error('Credentials output must be a writable path that does not already exist.');

                return Command::FAILURE;
            }
            fputcsv($credentialsHandle, ['employee_id', 'temporary_password']);
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
                    $this->line("  Updated profile: {$data['name']} ({$data['employeeId']}); password unchanged");

                    continue;
                }

                $temporaryPassword = Str::password(24);
                Employee::create([
                    'name' => $data['name'],
                    'employee_id' => $data['employeeId'],
                    'rank' => $data['rank'],
                    'password' => $temporaryPassword,
                    'must_change_password' => true,
                ]);
                fputcsv($credentialsHandle, [$data['employeeId'], $temporaryPassword]);
                $created++;
                $this->line("  Created: {$data['name']} ({$data['employeeId']})");
            }

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();
            if (is_resource($credentialsHandle)) {
                fclose($credentialsHandle);
            }
            if (is_string($credentialsOutput) && is_file($credentialsOutput)) {
                @unlink($credentialsOutput);
            }
            $this->error('Import failed; no employee changes were committed: '.$exception->getMessage());

            return Command::FAILURE;
        }

        if (is_resource($credentialsHandle)) {
            fclose($credentialsHandle);
            @chmod((string) $credentialsOutput, 0600);
        }

        $this->newLine();
        $this->info("Import complete — Created: {$created}, Updated profiles: {$updated}, Skipped: {$skipped}");
        if ($created > 0) {
            $this->warn("Unique temporary credentials were written to {$credentialsOutput}. Protect, distribute, and delete that file after onboarding.");
        }

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
