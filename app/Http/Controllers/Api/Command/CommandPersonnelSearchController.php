<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Command;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\PersonnelRequests\PersonnelRosterSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only personnel lookup for the Command Board crew editor. Returns only
 * the fields a crew picker needs; nothing about contact details, stations,
 * accounts or schedules ever leaves the Hub through this route.
 */
final class CommandPersonnelSearchController extends Controller
{
    public const LIMIT = 20;

    private const MAX_QUERY_LENGTH = 100;

    public function __invoke(Request $request, PersonnelRosterSearch $search): JsonResponse
    {
        $term = $request->query('q');
        $term = is_string($term) ? mb_substr(trim($term), 0, self::MAX_QUERY_LENGTH) : '';

        $employees = $search->employees($term)->take(self::LIMIT)->values();
        $statuses = Employee::query()
            ->whereIn('id', $employees->pluck('id'))
            ->pluck('roster_status', 'id');

        return response()->json([
            'results' => $employees->map(static fn (Employee $employee): array => [
                'employeeId' => (string) $employee->employee_id,
                'name' => (string) $employee->name,
                'rank' => (string) $employee->rank,
                'active' => ($statuses[$employee->id] ?? null) === 'active',
            ])->all(),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }
}
