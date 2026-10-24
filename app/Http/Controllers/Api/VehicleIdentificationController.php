<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apparatus;
use App\Models\User;
use App\Services\Fleet\VehicleIdentificationPlanner;
use App\Services\Fleet\VehicleIdentificationService;
use App\Services\Fleet\VehicleIdentificationSubmission;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use App\Services\Security\Captain5Access;
use App\Support\Fleet\VehicleIdentificationFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The one-time vehicle questions outside a Daily Checkout: the Captain 5 page, and any other unit
 * page that wants to ask. Whether a question is open is always decided by the planner on the
 * server; this controller only decides who may see and answer a unit's questions.
 */
final class VehicleIdentificationController extends Controller
{
    public function __construct(
        private readonly VehicleIdentificationPlanner $planner,
        private readonly VehicleIdentificationService $service,
    ) {}

    /** The open questions for a unit the person may answer for. */
    public function show(Request $request, int $apparatus): JsonResponse
    {
        $unit = Apparatus::query()->findOrFail($apparatus);
        $user = $this->authorizeUnit($request, $unit);

        return response()->json(['vehicle_identification' => $this->planner->block($unit, $this->planner->canAnswer($user, $unit))]);
    }

    /**
     * Record the answers. Idempotent by client_submission_id: a retry returns the current questions
     * without recording anything twice. Items that cannot be used come back as field errors.
     */
    public function store(Request $request, int $apparatus, VehicleIdentificationSubmission $submission): JsonResponse
    {
        $unit = Apparatus::query()->findOrFail($apparatus);
        $user = $this->authorizeUnit($request, $unit);
        // Answering writes to vehicle records: seeing the questions is not enough.
        abort_unless($this->planner->canAnswer($user, $unit), 403);
        $validated = $request->validate([
            'client_submission_id' => ['required', 'uuid'],
            'questions_version' => ['required', 'string', 'max:64'],
            'answers' => ['required', 'array', 'min:1', 'max:'.VehicleIdentificationSubmission::MAX_ANSWERS],
        ]);
        $clientSubmissionId = strtolower((string) $validated['client_submission_id']);

        $recordedFor = $this->service->submissionApparatusId($clientSubmissionId);
        if ($recordedFor !== null && $recordedFor !== (int) $unit->getKey()) {
            return response()->json([
                'message' => 'This submission identifier was already used for a different apparatus.',
            ], 409);
        }

        $cleaned = $submission->clean(
            ['questions_version' => $validated['questions_version'], 'answers' => $validated['answers']],
            VehicleIdentificationFields::kindFor($unit),
            strict: true,
        );
        if ($cleaned['errors'] !== []) {
            throw ValidationException::withMessages($cleaned['errors']);
        }

        $section = $cleaned['section'];
        abort_if($section === null, 422, 'Send the answers as a list.');
        $replayed = $this->service->submitStandalone($unit, $section, $user, $clientSubmissionId);

        return response()->json([
            'replayed' => $replayed,
            'vehicle_identification' => $this->planner->block($unit->refresh(), true),
        ]);
    }

    /**
     * Who may see a unit's questions: Fleet managers for any unit; for the Captain 5 unit, anyone
     * the Captain 5 page is open to (view access); for a Daily Checkout unit, members who may
     * submit its checkout. Writing answers is narrower, see VehicleIdentificationPlanner::canAnswer
     * (the Captain 5 unit needs act access). A unit that is not asked questions does not exist as
     * far as this route goes.
     */
    private function authorizeUnit(Request $request, Apparatus $unit): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($this->planner->isEligible($unit), 404);

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
}
