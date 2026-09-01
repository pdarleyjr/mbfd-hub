<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Http\Controllers\Controller;
use App\Models\PersonnelRequest;
use Illuminate\View\View;

class PersonnelRequestDetailController extends Controller
{
    use ResolvesCanonicalEmployee;

    public function __invoke(PersonnelRequest $personnelRequest): View
    {
        $employee = $this->authenticatedEmployee();
        abort_unless($personnelRequest->beneficiary_employee_id === $employee->id, 403);

        return view('employee.personnel-request-detail', [
            'request' => $personnelRequest->load(['items', 'updates', 'originatingStation', 'attachments']),
        ]);
    }
}
