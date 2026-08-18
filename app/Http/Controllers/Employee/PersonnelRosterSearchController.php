<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\PersonnelRequests\OfficerAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonnelRosterSearchController extends Controller
{
    public function __invoke(Request $request, OfficerAuthorizationService $officers): JsonResponse
    {
        /** @var Employee $officer */
        $officer = auth('employee')->user();
        abort_unless($officers->isAuthorized($officer), 403);

        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']]);
        $term = $validated['q'];
        $employees = Employee::query()
            ->where(fn ($query) => $query->where('name', 'like', "%{$term}%")
                ->orWhere('employee_id', 'like', "%{$term}%")
                ->orWhere('rank', 'like', "%{$term}%"))
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'employee_id', 'name', 'rank']);

        return response()->json([
            'data' => $employees->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'label' => "{$employee->rank} — {$employee->name} — {$employee->employee_id}",
            ])->values(),
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
