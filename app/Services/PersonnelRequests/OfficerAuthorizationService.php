<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

use App\Models\Employee;

final class OfficerAuthorizationService
{
    public function isAuthorized(Employee $employee): bool
    {
        return in_array($employee->rank, config('personnel_requests.officer_ranks', []), true);
    }
}
