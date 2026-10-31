<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\User;
use App\Services\Fleet\ApparatusChangeoutPlanner;
use App\Services\Fleet\ApparatusChangeoutService;
use App\Services\Fleet\ChangeoutConflictException;
use App\Services\Fleet\VehicleIdentificationPlanner;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use App\Services\Security\Captain5Access;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The member side of a vehicle changeout (docs/apparatus-changeouts.md section 6). Who may do what is checked here and
 * again in the service; all rules about trucks and equipment live in the service and the planner.
 */
final class ApparatusChangeoutController extends Controller
{
    public function __construct(
        private readonly ApparatusChangeoutPlanner $planner,
        private readonly ApparatusChangeoutService $service,
    ) {}

    public function context(Request $request, int $apparatus): JsonResponse
    {
        $unit = Apparatus::query()->findOrFail($apparatus);
        $user = $this->authorizeMember($request, $unit);
        $validated = $request->validate(['alert_id' => ['nullable', 'integer']]);

        return response()->json($this->planner->context($unit, $user, isset($validated['alert_id']) ? (int) $validated['alert_id'] : null));
    }

    /** 201 for a new record, 200 with replayed true for a repeat of the same submission. */
    public function store(Request $request, int $apparatus): JsonResponse
    {
        $unit = Apparatus::query()->findOrFail($apparatus);
        $user = $this->authorizeMember($request, $unit);
        // A truck keeps its number after a move, so the unit is what proves nothing moved under this screen.
        $validated = $request->validate([...ApparatusChangeoutService::rules(), 'expected_unit_slug' => ['required', 'string', 'max:255']]);

        try {
            $result = $this->service->submit($unit, $user, $validated);
        } catch (ChangeoutConflictException $conflict) {
            return $this->conflict($conflict);
        }

        return response()->json([
            'changeout' => $this->service->summary($result['changeout'], $user),
            'replayed' => $result['replayed'],
        ], $result['created'] ? 201 : 200);
    }

    /** The submitter, Fleet, and active members with a profile. Everyone else gets a plain not found. */
    public function show(Request $request, int $changeout): JsonResponse
    {
        $record = ApparatusChangeout::query()->findOrFail($changeout);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($this->canView($request, $user, $record), 404);

        return response()->json(['changeout' => $this->service->summary($record, $user, true)]);
    }

    public function verify(Request $request, int $changeout, int $line): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500']]);

        try {
            $record = $this->service->verifyLine($changeout, $line, $user, $validated['note'] ?? null);
        } catch (ChangeoutConflictException $conflict) {
            return $this->conflict($conflict);
        }

        return response()->json(['changeout' => $this->service->summary($record, $user, true)]);
    }

    /**
     * Fleet managers for any unit; the Captain 5 unit for anyone its page is open to; any other unit for active members
     * with an employee profile. The rule VehicleIdentificationController::authorizeUnit uses, without its question gates.
     */
    private function authorizeMember(Request $request, Apparatus $unit): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        if ($user->canManageFleetSwaps()) {
            return $user;
        }
        if (VehicleIdentificationPlanner::isCaptain5($unit)) {
            abort_unless(app(Captain5Access::class)->canView($user), 403);

            return $user;
        }
        app(AuthenticatedMemberContextResolver::class)->resolve($request)->actor()->requireEmployee();

        return $user;
    }

    private function canView(Request $request, User $user, ApparatusChangeout $record): bool
    {
        if ((int) $record->submitted_by_user_id === (int) $user->getKey() || $this->service->canVerify($user, $record)) {
            return true;
        }
        try {
            app(AuthenticatedMemberContextResolver::class)->resolve($request)->actor()->requireEmployee();
        } catch (AuthorizationException) {
            return false;
        }

        return true;
    }

    private function conflict(ChangeoutConflictException $conflict): JsonResponse
    {
        return response()->json($conflict->toResponseArray(), 409);
    }
}
