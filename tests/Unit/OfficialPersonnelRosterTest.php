<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class OfficialPersonnelRosterTest extends TestCase
{
    public function test_approved_command_staff_are_in_the_official_employee_roster(): void
    {
        $expected = [
            '16847' => ['name' => 'Jorge Linares', 'rank' => 'Deputy Fire Chief'],
            '18156' => ['name' => 'Digna Abello', 'rank' => 'Fire Chief'],
            '19545' => ['name' => 'Victor White', 'rank' => 'Division Chief'],
            '19952' => ['name' => 'David Sola', 'rank' => 'Division Chief'],
            '20487' => ['name' => 'Juan Mestas', 'rank' => 'Deputy Fire Chief'],
            '21989' => ['name' => 'Miguel Anchia', 'rank' => 'Division Chief'],
        ];

        $roster = $this->readRoster();

        foreach ($expected as $employeeId => $profile) {
            $this->assertArrayHasKey($employeeId, $roster, "Employee {$employeeId} is missing from the official roster.");
            $this->assertSame($profile, $roster[$employeeId]);
        }
    }

    public function test_official_employee_roster_has_unique_employee_ids(): void
    {
        $path = __DIR__.'/../../scripts/mbfd-personnel.csv';
        $handle = fopen($path, 'rb');

        $this->assertNotFalse($handle);
        $this->assertSame(['Name', 'Rank', 'Employee ID'], fgetcsv($handle));

        $employeeIds = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 3 || trim($row[2]) === '') {
                continue;
            }

            $employeeIds[] = trim($row[2]);
        }
        fclose($handle);

        $this->assertSame($employeeIds, array_values(array_unique($employeeIds)));
    }

    /**
     * @return array<string, array{name: string, rank: string}>
     */
    private function readRoster(): array
    {
        $handle = fopen(__DIR__.'/../../scripts/mbfd-personnel.csv', 'rb');
        $this->assertNotFalse($handle);
        $this->assertSame(['Name', 'Rank', 'Employee ID'], fgetcsv($handle));

        $roster = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 3 || trim($row[2]) === '') {
                continue;
            }

            $roster[trim($row[2])] = [
                'name' => trim($row[0]),
                'rank' => trim($row[1]),
            ];
        }
        fclose($handle);

        return $roster;
    }
}
