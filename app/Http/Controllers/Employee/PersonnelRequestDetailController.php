<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Http\Controllers\Controller;
use App\Models\PersonnelRequest;
use App\Support\PersonnelRequests\PersonnelRequestParticipantAccess;
use Illuminate\View\View;

class PersonnelRequestDetailController extends Controller
{
    use ResolvesCanonicalEmployee;

    public function __invoke(PersonnelRequest $personnelRequest): View
    {
        $employee = $this->authenticatedEmployee();
        // The member it is for, or the officer who submitted it for them (docs/member-replies.md section 3).
        abort_unless(PersonnelRequestParticipantAccess::isParticipant($employee, $personnelRequest), 403);

        return view('employee.personnel-request-detail', [
            'request' => $personnelRequest->load(['items', 'updates', 'originatingStation', 'attachments']),
            'isBeneficiary' => PersonnelRequestParticipantAccess::isBeneficiary($employee, $personnelRequest),
            'viewerEmployeeId' => (int) $employee->getKey(),
        ]);
    }
}
