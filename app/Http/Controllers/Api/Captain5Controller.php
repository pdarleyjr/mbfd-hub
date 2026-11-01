<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\Captain5\Captain5ActingRequest;
use App\Models\User;
use App\Services\Captain5\Captain5HandoffService;
use App\Services\Captain5\Captain5InspectionView;
use App\Services\Captain5\Captain5IssueActions;
use App\Services\Captain5\Captain5IssuePresenter;
use App\Services\Captain5\Captain5IssueScope;
use App\Services\Captain5\Captain5Workspace;
use App\Services\DailyRoster\OnDutyOfficerDisplayResolver;
use App\Services\Defects\StaleDefectStateException;
use App\Services\Fleet\VehicleIdentificationPlanner;
use App\Services\Inspections\IssueMessageService;
use App\Services\Security\Captain5Access;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Captain 5 EMS Operations Center API (docs/captain5-ems-operations-center.md). The route group
 * requires `captain5-view`; every mutation re-checks its own capability in the service layer.
 */
final class Captain5Controller extends Controller
{
    // Resolved per call, never held on the controller: the router caches controller instances,
    // and these services memoize per request (scoped bindings).
    private function access(): Captain5Access
    {
        return app(Captain5Access::class);
    }

    private function workspace(): Captain5Workspace
    {
        return app(Captain5Workspace::class);
    }

    private function presenter(): Captain5IssuePresenter
    {
        return app(Captain5IssuePresenter::class);
    }

    private function scope(): Captain5IssueScope
    {
        return app(Captain5IssueScope::class);
    }

    /** Lightweight card data for the station list; outside the view gate on purpose (see routes/api.php). */
    public function summary(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $decision = $this->access()->decide($user);
        if (! $decision->canViewData) {
            return $this->json(['access' => ['view' => false]]);
        }

        return $this->json([
            'access' => $decision->toClientArray(),
            'on_duty' => $this->cardDuty(),
            'open_issues' => $this->workspace()->openIssues()->count(),
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return $this->json($this->workspace()->overview($user, $this->access()->decide($user)) + $this->vehicleIdentification($user));
    }

    /**
     * The Captain 5 unit's one-time vehicle questions, for the same viewers who may open this
     * workspace (the route is behind captain5-view). Answers post to
     * POST /api/public/apparatuses/{apparatus_id}/vehicle-identification.
     *
     * @return array<string, mixed>
     */
    private function vehicleIdentification(User $viewer): array
    {
        $planner = app(VehicleIdentificationPlanner::class);
        $unit = $planner->captain5Unit();

        // can_answer is false for a viewer who may see the page but not write: the card shows no submit path.
        return $unit === null ? [] : [
            'apparatus_id' => $unit->getKey(),
            'vehicle_identification' => $planner->block($unit, $planner->canAnswer($viewer, $unit)),
        ];
    }

    public function issues(Request $request): JsonResponse
    {
        $valid = $request->validate([
            'status' => ['nullable', 'in:open,closed'],
            'q' => ['nullable', 'string', 'max:60'],
        ]);
        $defects = ($valid['status'] ?? 'open') === 'closed'
            ? $this->workspace()->closedIssues($valid['q'] ?? null)
            : $this->workspace()->openIssues();

        return $this->json(['issues' => $this->presenter()->summaries($defects)]);
    }

    public function issue(Request $request, int $defect): JsonResponse
    {
        $user = $this->user($request);
        $model = $this->inScope($defect);

        return $this->json(['issue' => $this->presenter()->detail($model, $user, $this->access()->decide($user))]);
    }

    public function act(Request $request, Captain5IssueActions $actions, int $defect): JsonResponse
    {
        $user = $this->user($request);
        $decision = $this->access()->decide($user);
        try {
            $updated = $actions->perform($user, $decision, $defect, $request->all());
        } catch (StaleDefectStateException $stale) {
            return $this->json([
                'message' => $stale->getMessage(),
                'issue' => $this->presenter()->detail($stale->current->refresh(), $user, $decision),
            ], 409);
        }

        return $this->json(['issue' => $this->presenter()->detail($updated->refresh(), $user, $decision)]);
    }

    public function message(Request $request, IssueMessageService $messages, int $defect): JsonResponse
    {
        $user = $this->user($request);
        $model = $this->inScope($defect);
        $valid = $request->validate([
            'body' => ['required', 'string', 'max:'.IssueMessageService::MAX_BODY],
            'visibility' => ['nullable', 'in:member,internal'],
            'client_message_id' => ['required', 'uuid'],
        ]);
        $messages->post($user, [
            'defect_id' => (int) $model->getKey(),
            'body' => $valid['body'],
            'visibility' => $valid['visibility'] ?? 'member',
            'client_message_id' => $valid['client_message_id'],
            'as_captain5' => true,
        ]);

        return $this->json(['issue' => $this->presenter()->detail($model->refresh(), $user, $this->access()->decide($user))], 201);
    }

    public function inspection(Request $request, Captain5InspectionView $view, int $inspection): JsonResponse
    {
        $model = ApparatusInspection::query()->find($inspection);
        $payload = $model !== null ? $view->present($model) : null;
        if ($payload === null) {
            throw new AuthorizationException('Not available.');
        }

        return $this->json(['inspection' => $payload]);
    }

    public function clarification(Request $request, IssueMessageService $messages, Captain5InspectionView $view, int $inspection): JsonResponse
    {
        $user = $this->user($request);
        if (! $this->access()->decide($user)->canAct) {
            throw new AuthorizationException('You can view this workspace but not message crews.');
        }
        $model = ApparatusInspection::query()->find($inspection);
        $presented = $model !== null ? $view->present($model) : null;
        if ($presented === null) {
            throw new AuthorizationException('Not available.');
        }
        if ($presented['kind'] !== Captain5IssueScope::KIND_RESCUE) {
            throw ValidationException::withMessages(['body' => 'For an Engine or Ladder, message the crew on the medical item\'s issue instead.']);
        }
        $valid = $request->validate([
            'body' => ['required', 'string', 'max:'.IssueMessageService::MAX_BODY],
            'client_message_id' => ['required', 'uuid'],
        ]);
        $message = $messages->post($user, [
            'inspection_id' => (int) $model->getKey(),
            'body' => $valid['body'],
            'visibility' => 'member',
            'client_message_id' => $valid['client_message_id'],
            'kind' => 'clarification_requested',
            'as_captain5' => true,
        ]);

        return $this->json(['message_id' => (int) $message->getKey()], 201);
    }

    public function writeHandoff(Request $request, Captain5HandoffService $handoffs): JsonResponse
    {
        $user = $this->user($request);
        $handoffs->write($user, $request->only(['body', 'client_request_id']));

        return $this->json(['handoff' => $this->workspace()->handoff($this->access()->decide($user)->onDuty->dutyDate->format('Y-m-d'), $user)], 201);
    }

    public function acknowledgeHandoff(Request $request, Captain5HandoffService $handoffs, int $note): JsonResponse
    {
        $user = $this->user($request);
        $handoffs->acknowledge($user, $note);

        return $this->json(['handoff' => $this->workspace()->handoff($this->access()->decide($user)->onDuty->dutyDate->format('Y-m-d'), $user)]);
    }

    /**
     * Who the card shows. Display only: `access` stays on the seat decision.
     *
     * @return array<string, mixed>
     */
    private function cardDuty(): array
    {
        return app(OnDutyOfficerDisplayResolver::class)->resolve(Captain5ActingRequest::ROLE_CAPTAIN_5)->toArray();
    }

    private function inScope(int $defectId): ApparatusDefect
    {
        $defect = ApparatusDefect::query()->with('firstReporter')->find($defectId);
        if ($defect === null || ! $this->scope()->inScope($defect)) {
            throw new AuthorizationException('Not available.');
        }
        $this->scope()->attachApparatus(collect([$defect]));

        return $defect;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        return $user;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, ['Cache-Control' => 'private, no-store']);
    }
}
