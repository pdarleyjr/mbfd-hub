<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use Illuminate\View\View;

class PersonnelRequestDetailController extends Controller
{
    public function __invoke(PersonnelRequest $personnelRequest): View
    {
        /** @var Employee $employee */
        $employee = auth('employee')->user();
        abort_unless($personnelRequest->beneficiary_employee_id === $employee->id, 403);

        return view('employee.personnel-request-detail', [
            'request' => $personnelRequest->load(['items', 'updates', 'originatingStation', 'attachments']),
        ]);
    }
}
