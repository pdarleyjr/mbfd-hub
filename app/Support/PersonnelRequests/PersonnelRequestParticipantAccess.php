<?php

declare(strict_types=1);

namespace App\Support\PersonnelRequests;

use App\Models\Employee;
use App\Models\PersonnelRequest;

/**
 * Who is part of a uniform or personnel equipment request (docs/member-replies.md section 3). Deliberately not a
 * Gate ability, like InventoryCorrectionAccess: Gate::before / AdminCapabilityGate would map it onto admin.*
 * permissions and hide it from the members it is for.
 *
 *  - the member the request is for (beneficiary), and
 *  - the officer who submitted it on their behalf (requester), when that is someone else.
 *
 * Both read the request and its member-visible history and both may message Support Services. Uploading and
 * downloading supporting documents (police reports, damage photos) stays with the beneficiary only.
 */
final class PersonnelRequestParticipantAccess
{
    public static function isParticipant(?Employee $employee, PersonnelRequest $request): bool
    {
        return self::isBeneficiary($employee, $request) || self::isRequester($employee, $request);
    }

    public static function isBeneficiary(?Employee $employee, PersonnelRequest $request): bool
    {
        return $employee instanceof Employee
            && $request->beneficiary_employee_id !== null
            && (int) $request->beneficiary_employee_id === (int) $employee->getKey();
    }

    public static function isRequester(?Employee $employee, PersonnelRequest $request): bool
    {
        return $employee instanceof Employee
            && $request->getAttribute('requester_employee_id') !== null
            && (int) $request->getAttribute('requester_employee_id') === (int) $employee->getKey();
    }
}
