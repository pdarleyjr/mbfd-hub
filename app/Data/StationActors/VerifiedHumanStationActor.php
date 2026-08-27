<?php

declare(strict_types=1);

namespace App\Data\StationActors;

use App\Models\Employee;

/**
 * An actor identity established by the server-side Employee session guard.
 *
 * Request, PIN, signed-URL, and launch-context values are intentionally not
 * represented here. employeeRecordId is the local Employee primary key, not
 * the MBFD employee-number credential stored in employee_id.
 */
final readonly class VerifiedHumanStationActor
{
    private function __construct(
        public int $employeeRecordId,
    ) {}

    public static function fromAuthenticatedEmployee(Employee $employee): self
    {
        return new self((int) $employee->getKey());
    }
}
