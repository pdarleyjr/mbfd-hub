<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Http\Controllers\Controller;
use App\Services\PersonnelRequests\OfficerAuthorizationService;
use App\Services\PersonnelRequests\PersonnelRosterSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonnelRosterSearchController extends Controller
{
    use ResolvesCanonicalEmployee;

    public function __invoke(
        Request $request,
        OfficerAuthorizationService $officers,
        PersonnelRosterSearch $roster,
    ): JsonResponse {
        $officer = $this->authenticatedEmployee();
        abort_unless($officers->isAuthorized($officer), 403);

        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']]);

        return response()->json([
            'data' => $roster->payload($validated['q']),
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
