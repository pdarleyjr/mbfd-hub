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
        $validated = $request->validate([
            'response' => ['required', 'string', 'max:4000'],
            'item_id' => ['nullable', 'integer'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);
        $employee = $this->authenticatedEmployee();
        abort_unless($personnelRequest->beneficiary_employee_id === $employee->id, 403);
        $item = filled($validated['item_id'] ?? null)
            ? $personnelRequest->items()->findOrFail($validated['item_id'])
            : null;
        $workflow->employeeRespond($personnelRequest, $employee, $validated['response'], $item, $validated['idempotency_key'] ?? null);

        return back()->with('status', 'Your response was sent to Support Services.');
    }
}
