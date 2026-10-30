<?php

declare(strict_types=1);

namespace App\Services\Roster;

use App\Models\Employee;

/**
 * Resolves a rank by exact employee ID for RosterSpecialEventExtractor.
 * Unknown IDs resolve to null; a roster position is never used as a rank.
 */
final class EmployeeRankResolver
{
    /** @var array<string, ?string> */
    private array $cache = [];

    public function __invoke(string $employeeId): ?string
    {
        if (! array_key_exists($employeeId, $this->cache)) {
            $rank = Employee::query()->where('employee_id', $employeeId)->value('rank');
            $this->cache[$employeeId] = is_string($rank) && trim($rank) !== '' ? trim($rank) : null;
        }

        return $this->cache[$employeeId];
    }
}
