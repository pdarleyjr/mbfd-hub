<?php

declare(strict_types=1);

namespace App\Data\StationActors;

use App\Models\Employee;

/**
 * An actor identity established by the server-side Employee session guard.
 *
 * Request, PIN, signed-URL, and launch-context values are intentionally not
 * represented here.
 */
final readonly class VerifiedHumanStationActor
{
    private function __construct(
        public int $employeeId,
    ) {}

    public static function fromAuthenticatedEmployee(Employee $employee): self
    {
        return new self((int) $employee->getKey());
    }
}
