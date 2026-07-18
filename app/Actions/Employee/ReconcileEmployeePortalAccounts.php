<?php

declare(strict_types=1);

namespace App\Actions\Employee;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class ReconcileEmployeePortalAccounts
{
    /**
     * @return array{created: int, updated: int, total: int}
     */
    public function handle(string $sourcePath, string $password): array
    {
        if ($password === '') {
            throw new InvalidArgumentException('The employee portal password cannot be empty.');
        }

        $roster = $this->readRoster($sourcePath);

        return DB::transaction(function () use ($roster, $password): array {
            $created = 0;
            $updated = 0;

            foreach ($roster as $employeeId => $row) {
                $employee = Employee::query()->where('employee_id', $employeeId)->first();

                if ($employee === null) {
                    Employee::query()->create([
                        'employee_id' => $employeeId,
                        'name' => $row['name'],
                        'rank' => $row['rank'],
                        'password' => $password,
                        'must_change_password' => false,
                    ]);
                    $created++;

                    continue;
                }

                $employee->update([
                    'name' => $row['name'],
                    'rank' => $row['rank'] !== '' ? $row['rank'] : $employee->rank,
                    'password' => $password,
                    'must_change_password' => false,
                ]);
                $updated++;
            }

            return [
                'created' => $created,
                'updated' => $updated,
                'total' => count($roster),
            ];
        });
    }

    /**
     * @return array<string, array{name: string, rank: string}>
     */
    public function readRoster(string $sourcePath): array
    {
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new InvalidArgumentException("Employee roster is not readable: {$sourcePath}");
        }

        $handle = fopen($sourcePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open employee roster: {$sourcePath}");
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                throw new InvalidArgumentException('Employee roster is empty.');
            }

            $normalizedHeader = array_map(
                fn (mixed $column): string => strtolower(trim((string) $column, " \t\n\r\0\x0B\xEF\xBB\xBF")),
                $header,
            );
            $nameIndex = $this->columnIndex($normalizedHeader, ['name', 'full name', 'fullname', 'employee name']);
            $rankIndex = $this->columnIndex($normalizedHeader, ['rank', 'position', 'title']);
            $employeeIdIndex = $this->columnIndex($normalizedHeader, ['employee id', 'employee_id', 'employeeid', 'emp id', 'empid']);

            if ($nameIndex === null || $employeeIdIndex === null) {
                throw new InvalidArgumentException('Employee roster must contain Name and Employee ID columns.');
            }

            $roster = [];
            $line = 1;

            while (($columns = fgetcsv($handle)) !== false) {
                $line++;
                if ($columns === [null] || $columns === []) {
                    continue;
                }

                $employeeId = trim((string) ($columns[$employeeIdIndex] ?? ''));
                $name = trim((string) ($columns[$nameIndex] ?? ''));
                $rank = $rankIndex === null ? '' : trim((string) ($columns[$rankIndex] ?? ''));

                if ($employeeId === '' && $name === '') {
                    continue;
                }
                if ($employeeId === '' || $name === '') {
                    throw new InvalidArgumentException("Roster line {$line} must contain both Name and Employee ID.");
                }
                if (isset($roster[$employeeId])) {
                    throw new InvalidArgumentException("Duplicate Employee ID {$employeeId} in roster.");
                }

                $roster[$employeeId] = ['name' => $name, 'rank' => $rank];
            }
        } finally {
            fclose($handle);
        }

        if ($roster === []) {
            throw new InvalidArgumentException('Employee roster contains no valid accounts.');
        }

        return $roster;
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $candidates
     */
    private function columnIndex(array $header, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $index = array_search($candidate, $header, true);
            if ($index !== false) {
                return $index;
            }
        }

        return null;
    }
}
