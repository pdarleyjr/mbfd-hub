<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Http\Controllers\Controller;
use App\Models\PersonnelRequest;
use App\Services\PersonnelRequests\PersonnelRequestWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PersonnelRequestResponseController extends Controller
{
    use ResolvesCanonicalEmployee;

    public function __invoke(Request $request, PersonnelRequest $personnelRequest, PersonnelRequestWorkflowService $workflow): RedirectResponse
    {
        $validated = $request->validate(['response' => ['required', 'string', 'max:4000']]);
        $employee = $this->authenticatedEmployee();
        $workflow->employeeRespond($personnelRequest, $employee, $validated['response']);

        return back()->with('status', 'Your response was sent to Support Services.');
    }
}
