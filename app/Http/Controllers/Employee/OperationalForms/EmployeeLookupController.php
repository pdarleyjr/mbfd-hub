<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee\OperationalForms;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmployeeLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:50']]);
        $query = trim($validated['q']);
        $needle = mb_strtolower($query);

        $employees = Employee::query()
            ->select(['employee_id', 'name', 'rank'])
            ->where(function ($builder) use ($needle): void {
                $builder->whereRaw('LOWER(employee_id) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%'.$needle.'%']);
            })
            ->orderByRaw('CASE WHEN LOWER(employee_id) = ? THEN 0 WHEN LOWER(employee_id) LIKE ? THEN 1 WHEN LOWER(name) LIKE ? THEN 2 ELSE 3 END', [$needle, $needle.'%', $needle.'%'])
            ->orderBy('name')
            ->limit(10)
            ->get();

        return response()->json(['employees' => $employees]);
    }
}
