<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DailyCheckoutRequirement;
use App\Exceptions\DailyCheckoutInspectionSessionException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\PublicApparatusResource;
use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusInspection;
use App\Models\DailyCheckoutInspectionSession;
use App\Models\Employee;
use App\Models\User;
use App\Services\ApparatusInspectionApprovalService;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\DailyCheckoutInspectionSessionService;
use App\Services\Display\DisplaySnapshotService;
use App\Services\Fleet\ApparatusChangeoutPlanner;
use App\Services\Fleet\CheckoutVehicleReport;
use App\Services\Fleet\VehicleIdentificationPlanner;
use App\Services\Fleet\VehicleIdentificationService;
use App\Services\Fleet\VehicleIdentificationSubmission;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use App\Services\Inspections\InspectionSubmissionSignals;
use App\Services\Inventory\ChecklistRevisionStore;
use App\Services\Inventory\InventoryCorrectionProjection;
use App\Services\Inventory\InventoryCorrectionService;
use App\Support\Database\TransientDatabaseFailure;
use App\Support\Fleet\FleetVehicleCatalog;
use App\Support\Fleet\VehicleIdentificationFields;
use App\Support\Fleet\VehicleNumber;
use App\Support\Security\Base64Image;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApparatusController extends Controller
{
    private const INSPECTION_BROWSER_COOKIE = 'daily_checkout_inspection_browser';

    public function index(): JsonResponse
    {
        $apparatuses = Apparatus::query()
            ->where('daily_checkout_requirement', DailyCheckoutRequirement::Required->value)
            ->get();

        return response()->json(PublicApparatusResource::collection($apparatuses)->resolve(request()));
    }

    /**
     * Every physical vehicle ID, frontline and reserve, for the checkout's
     * "confirm the physical vehicle" dropdown. ID, unit and role only.
     */
    public function fleetVehicles(FleetVehicleCatalog $catalog): JsonResponse
    {
        return response()->json(['vehicles' => $catalog->all()]);
    }

    public function checklist(Request $request, int $id, DailyCheckoutChecklistResolver $checklistResolver): JsonResponse
    {
        $inspectionDateInput = $request->validate([
            'inspection_date' => ['nullable', 'date_format:Y-m-d'],
        ])['inspection_date'] ?? null;
        $inspectionDate = CarbonImmutable::parse(
            $inspectionDateInput ?? CarbonImmutable::now((string) config('hub.timezone'))->toDateString(),
            (string) config('hub.timezone'),
        )->startOfDay();
        $apparatus = Apparatus::findOrFail($id);

        if (! $apparatus->isDailyCheckoutRequired()) {
            return response()->json([
                'message' => 'This apparatus is not configured for Daily Checkout.',
            ], 409);
        }

        $resolution = $checklistResolver->resolve($apparatus);
        if (! $resolution['usable']) {
            Log::warning('Daily Checkout checklist resolution failed.', [
                'apparatus_id' => $apparatus->id,
                'checklist_type' => $resolution['checklist_type'],
                'reason' => $resolution['error'],
            ]);

            return response()->json([
                'message' => 'The Daily Checkout checklist is unavailable. Contact an officer before continuing.',
                'code' => 'DAILY_CHECKOUT_CHECKLIST_UNAVAILABLE',
            ], 503);
        }

        $checklist = $resolution['checklist'];

        return response()->json([
            'apparatus' => (new PublicApparatusResource($apparatus))->resolve(request()),
            'checklist' => $checklist,
            'checklist_version' => $resolution['checklist_version'],
            // The template hash without approved inventory changes: lets an open draft tell
            // an inventory update (answers carry over by item id) from a template change.
            'checklist_base_version' => $resolution['checklist_base_version'],
            'checklist_type' => $resolution['checklist_type'],
            'checklist_item_count' => $resolution['item_count'],
            'inspection_date' => $inspectionDate->toDateString(),
            'due_tasks' => $checklistResolver->dueTasksFor($checklist, $inspectionDate),
            // This browser route only needs a warning count. Never
            // serialize defect notes, photos, resolution history, or paths.
            'open_defects_count' => $apparatus->openDefects()->count(),
            'open_findings' => $this->knownFindings($apparatus, $checklist),
            'pending_corrections' => $this->pendingCorrections($apparatus, $request),
            // Outside the hashed checklist on purpose: answering a question never changes checklist_version.
            'vehicle_identification' => app(VehicleIdentificationPlanner::class)->block($apparatus),
            'vehicle_id_required' => VehicleIdentificationFields::usesFleetNumber($apparatus),
            // Outside the hashed checklist too: what stayed on the truck this crew just left.
            'changeout_handoff' => app(ApparatusChangeoutPlanner::class)->handoffNotice($apparatus),
        ]);
    }

    /**
     * A checkout queued on a phone still posts to the truck it was performed on. When the unit moved off this truck
     * after that, the row is no longer a Daily Checkout unit, but the findings are real and belong to this truck, so
     * a stored submission must not be stranded. Same grace window as a retired checklist.
     */
    private function justChangedOutFrom(Apparatus $apparatus): ?ApparatusChangeout
    {
        return app(ApparatusChangeoutPlanner::class)->changedOutFrom($apparatus);
    }

    /** Open inventory corrections on this apparatus (member-safe markers, no reporter identity). */
    private function pendingCorrections(Apparatus $apparatus, Request $request): array
    {
        $viewer = $request->user();

        return app(InventoryCorrectionProjection::class)->pendingMarkers($apparatus, $viewer instanceof User ? $viewer : null);
    }

    /**
     * The checklist an inspection was performed on when an approved inventory change has
     * replaced it since: work begun on the old list is validated against exactly that list.
     *
     * @return array<string, mixed>|null
     */
    private function recentlyReplacedChecklist(Apparatus $apparatus, string $submittedVersion): ?array
    {
        $checklist = app(ChecklistRevisionStore::class)->recentlyRetired((int) $apparatus->getKey(), $submittedVersion);

        return is_array($checklist) && ! $this->isV2Checklist($checklist) ? $checklist : null;
    }

    /**
     * Begin an immutable Fire Boat inspection contract. The public checklist
     * GET remains a read-only preview; this state-changing endpoint is the
     * only source of an authoritative duty date and recurring-duty set.
     */
    public function startInspectionSession(
        Request $request,
        int $id,
        DailyCheckoutChecklistResolver $checklistResolver,
        DailyCheckoutInspectionSessionService $inspectionSessionService,
        AuthenticatedMemberContextResolver $memberContextResolver,
    ): JsonResponse {
        $actor = $memberContextResolver->resolve($request)->actor();
        $actor->requireEmployee();
        $issuanceKey = $request->validate([
            'inspection_session_start_key' => ['nullable', 'uuid'],
        ])['inspection_session_start_key'] ?? null;
        $apparatus = Apparatus::findOrFail($id);
        if (! $apparatus->isDailyCheckoutRequired()) {
            return response()->json([
                'message' => 'This apparatus is not configured for Daily Checkout.',
            ], 409);
        }

        $resolution = $checklistResolver->resolve($apparatus);
        if (! $resolution['usable']) {
            Log::warning('Daily Checkout checklist resolution failed while starting an inspection session.', [
                'apparatus_id' => $apparatus->id,
                'checklist_type' => $resolution['checklist_type'],
                'reason' => $resolution['error'],
            ]);

            return response()->json([
                'message' => 'The Daily Checkout checklist is unavailable. Contact an officer before continuing.',
                'code' => 'DAILY_CHECKOUT_CHECKLIST_UNAVAILABLE',
            ], 503);
        }

        $checklist = $resolution['checklist'];
        if (! $this->isV2Checklist($checklist)) {
            return response()->json([
                'message' => 'This checklist does not require an inspection duty-date session.',
                'code' => 'DAILY_CHECKOUT_INSPECTION_SESSION_NOT_REQUIRED',
            ], 409);
        }

        $issuedAt = CarbonImmutable::now(DailyCheckoutInspectionSessionService::TIMEZONE);
        $browserBinding = $this->browserBindingForIssue($request, $issuanceKey, $inspectionSessionService);
        try {
            $issued = $inspectionSessionService->issue(
                apparatus: $apparatus,
                checklist: $checklist,
                checklistHash: (string) $resolution['checklist_version'],
                dueTasks: $checklistResolver->dueTasksFor($checklist, $issuedAt->startOfDay()),
                actorUserId: $actor->userId(),
                actorSessionHash: $browserBinding['hash'],
                issuanceKey: $issuanceKey,
                issuedAt: $issuedAt,
            );
        } catch (DailyCheckoutInspectionSessionException $exception) {
            return $this->inspectionSessionExceptionResponse($exception);
        }
        $session = $issued['session'];
        $sessionChecklist = $session->checklist_snapshot;
        if (! is_array($sessionChecklist) || ! $inspectionSessionService->checklistHasIntegrity($session)) {
            return response()->json([
                'message' => 'The Fire Boat inspection session contract could not be verified. Reconnect and start a new inspection session.',
                'code' => 'DAILY_CHECKOUT_INSPECTION_SESSION_INVALID',
            ], 409);
        }

        $response = response()->json([
            'apparatus' => (new PublicApparatusResource($apparatus))->resolve(request()),
            'checklist' => $sessionChecklist,
            'checklist_version' => $session->checklist_hash,
            'checklist_type' => $resolution['checklist_type'],
            'checklist_item_count' => $resolution['item_count'],
            'inspection_date' => $session->duty_date?->toDateString(),
            'due_tasks' => $session->due_tasks,
            'open_defects_count' => $apparatus->openDefects()->count(),
            'open_findings' => $this->knownFindings($apparatus, $resolution['checklist']),
            'inspection_session' => $inspectionSessionService->publicContract($session, $issued['token']),
            // Not part of the frozen session snapshot or its hash.
            'vehicle_identification' => app(VehicleIdentificationPlanner::class)->block($apparatus),
            'vehicle_id_required' => VehicleIdentificationFields::usesFleetNumber($apparatus),
        ], $issued['created'] ? 201 : 200);

        if ($browserBinding['new']) {
            $response->withCookie($this->browserBindingCookie($browserBinding['token'], $request));
        }

        return $response;
    }

    /**
     * Explicitly abandons one still-valid prior-day Fire Boat contract before
     * issuing today's contract. This route never accepts browser-state loss as
     * authority: it requires the original server-issued token, replay key, and
     * browser binding.
     */
    public function abandonInspectionSession(
        Request $request,
        int $id,
        string $sessionId,
        DailyCheckoutChecklistResolver $checklistResolver,
        DailyCheckoutInspectionSessionService $inspectionSessionService,
        AuthenticatedMemberContextResolver $memberContextResolver,
    ): JsonResponse {
        $actor = $memberContextResolver->resolve($request)->actor();
        $actor->requireEmployee();
        $validated = $request->validate([
            'inspection_session_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/i'],
            'inspection_session_replay_key' => ['required', 'uuid'],
            'inspection_session_transition_key' => ['required', 'uuid'],
        ]);
        $apparatus = Apparatus::findOrFail($id);
        if (! $apparatus->isDailyCheckoutRequired()) {
            return response()->json([
                'message' => 'This apparatus is not configured for Daily Checkout.',
            ], 409);
        }

        $resolution = $checklistResolver->resolve($apparatus);
        if (! $resolution['usable']) {
            Log::warning('Daily Checkout checklist resolution failed while abandoning an inspection session.', [
                'apparatus_id' => $apparatus->id,
                'checklist_type' => $resolution['checklist_type'],
                'reason' => $resolution['error'],
            ]);

            return response()->json([
                'message' => 'The Daily Checkout checklist is unavailable. Contact an officer before continuing.',
                'code' => 'DAILY_CHECKOUT_CHECKLIST_UNAVAILABLE',
            ], 503);
        }

        $checklist = $resolution['checklist'];
        if (! $this->isV2Checklist($checklist)) {
            return response()->json([
                'message' => 'This checklist does not require an inspection duty-date session.',
                'code' => 'DAILY_CHECKOUT_INSPECTION_SESSION_NOT_REQUIRED',
            ], 409);
        }

        $issuedAt = CarbonImmutable::now(DailyCheckoutInspectionSessionService::TIMEZONE);
        try {
            $issued = $inspectionSessionService->abandonAndIssue(
                apparatus: $apparatus,
                priorPublicId: $sessionId,
                priorToken: (string) $validated['inspection_session_token'],
                priorReplayKey: (string) $validated['inspection_session_replay_key'],
                transitionKey: (string) $validated['inspection_session_transition_key'],
                checklist: $checklist,
                checklistHash: (string) $resolution['checklist_version'],
                dueTasks: $checklistResolver->dueTasksFor($checklist, $issuedAt->startOfDay()),
                actorUserId: $actor->userId(),
                actorSessionHash: $this->browserBindingHash($request),
                issuedAt: $issuedAt,
            );
        } catch (DailyCheckoutInspectionSessionException $exception) {
            return $this->inspectionSessionExceptionResponse($exception);
        }

        $session = $issued['session'];
        $sessionChecklist = $session->getAttribute('checklist_snapshot');
        if (! is_array($sessionChecklist) || ! $inspectionSessionService->checklistHasIntegrity($session)) {
            return response()->json([
                'message' => 'The Fire Boat inspection session contract could not be verified. Reconnect and start a new inspection session.',
                'code' => 'DAILY_CHECKOUT_INSPECTION_SESSION_INVALID',
            ], 409);
        }

        $sessionDutyDate = $session->getAttribute('duty_date');
        if (! ($sessionDutyDate instanceof CarbonImmutable)) {
            return response()->json([
                'message' => 'The Fire Boat inspection session contract could not be verified. Reconnect and start a new inspection session.',
                'code' => 'DAILY_CHECKOUT_INSPECTION_SESSION_INVALID',
            ], 409);
        }

        return response()->json([
            'apparatus' => (new PublicApparatusResource($apparatus))->resolve(request()),
            'checklist' => $sessionChecklist,
            'checklist_version' => $session->checklist_hash,
            'checklist_type' => $resolution['checklist_type'],
            'checklist_item_count' => $resolution['item_count'],
            'inspection_date' => $sessionDutyDate->toDateString(),
            'due_tasks' => $session->due_tasks,
            'open_defects_count' => $apparatus->openDefects()->count(),
            'open_findings' => $this->knownFindings($apparatus, $resolution['checklist']),
            'inspection_session' => $inspectionSessionService->publicContract($session, $issued['token']),
            'vehicle_identification' => app(VehicleIdentificationPlanner::class)->block($apparatus),
            'vehicle_id_required' => VehicleIdentificationFields::usesFleetNumber($apparatus),
        ], $issued['created'] ? 201 : 200);
    }

    public function storeInspection(
        Request $request,
        int $id,
        DailyCheckoutChecklistResolver $checklistResolver,
        DailyCheckoutInspectionSessionService $inspectionSessionService,
        AuthenticatedMemberContextResolver $memberContextResolver,
    ): JsonResponse {
        $actor = $memberContextResolver->resolve($request)->actor();
        $employee = $actor->requireEmployee();
        $validated = $request->validate([
            'client_submission_id' => ['required', 'uuid'],
            'checklist_version' => ['required', 'string', 'regex:/\\A[a-f0-9]{64}\\z/i'],
            'processing_version' => ['nullable', 'integer', 'in:1'],
            'meter_baseline_token' => ['nullable', 'string', 'max:2048'],
            'operator_name' => ['required', 'string', 'max:255'],
            'rank' => ['required', 'string', 'max:100'],
            'shift' => ['required_if:processing_version,1', 'nullable', 'string', 'in:A,B,C'],
            'unit_number' => ['nullable', 'string', 'max:100'],
            'engine_hours' => ['nullable', 'numeric', 'min:0', 'max:9999999.9', 'decimal:0,1'],
            'miles' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'compartments' => ['required', 'array', 'min:1'],
            'compartments.*' => ['required', 'array'],
            'compartments.*.id' => ['required', 'string', 'max:255'],
            'compartments.*.name' => ['required', 'string', 'max:255'],
            'compartments.*.items' => ['required', 'array', 'min:1'],
            'compartments.*.items.*' => ['required', 'array'],
            'compartments.*.items.*.id' => ['nullable', 'string', 'max:255'],
            'compartments.*.items.*.name' => ['required', 'string', 'max:255'],
            'compartments.*.items.*.status' => ['required', 'string', 'in:Present,Missing,Damaged'],
            'compartments.*.items.*.observed' => ['sometimes', 'boolean'],
            'compartments.*.items.*.value' => ['sometimes', 'nullable'],
            'compartments.*.items.*.notes' => ['nullable', 'string', 'max:2000'],
            'compartments.*.items.*.significant' => ['sometimes', 'boolean'],
            'compartments.*.items.*.photo' => ['nullable', 'string', 'max:7000000'],
            'defects' => ['nullable', 'array', 'max:100'],
            'defects.*.compartment' => ['required', 'string', 'max:255'],
            'defects.*.item' => ['required', 'string', 'max:255'],
            'defects.*.status' => ['required', 'string', 'in:Missing,Damaged'],
            'defects.*.notes' => ['nullable', 'string', 'max:2000'],
            'defects.*.photo' => ['nullable', 'string', 'max:7000000'],
            'defects.*.compartment_id' => ['nullable', 'string', 'max:255'],
            'defects.*.item_id' => ['nullable', 'string', 'max:255'],
            'defects.*.significant' => ['sometimes', 'boolean'],
            'field_values' => ['nullable', 'array', 'max:100'],
            'field_values.*' => ['required', 'array'],
            'field_values.*.id' => ['required', 'string', 'max:255'],
            'field_values.*.value' => ['present'],
            'scheduled_tasks' => ['nullable', 'array', 'max:100'],
            'scheduled_tasks.*' => ['required', 'array'],
            'scheduled_tasks.*.id' => ['required', 'string', 'max:255'],
            'scheduled_tasks.*.status' => ['required', 'string', 'in:Present,Missing,Damaged'],
            'scheduled_tasks.*.observed' => ['sometimes', 'boolean'],
            'scheduled_tasks.*.notes' => ['nullable', 'string', 'max:2000'],
            'inspection_session_id' => ['nullable', 'uuid'],
            'inspection_session_token' => ['nullable', 'string', 'regex:/\A[a-f0-9]{64}\z/i'],
            'inspection_session_replay_key' => ['nullable', 'uuid'],
            'officer_signature' => ['required_if:processing_version,1', 'nullable', 'string', 'max:7000000'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            // The member's confirmation of the physical vehicle. Whether it differs from the unit's assigned
            // vehicle is decided only against the locked apparatus below. swap_answer and note come only from
            // phones still on the old app, and a badly formed number is flagged rather than refused there.
            'physical_vehicle' => ['nullable', 'array:vehicle_number,swap_answer,note', 'prohibited_unless:processing_version,1'],
            'physical_vehicle.vehicle_number' => ['nullable', 'string', 'max:255'],
            'physical_vehicle.swap_answer' => ['nullable', 'string'],
            'physical_vehicle.note' => ['nullable', 'string', 'max:1000'],
            // The member's request for further review. Items are validated against the resolved checklist.
            'review_flag' => ['nullable', 'array:explanation,items', 'prohibited_unless:processing_version,1'],
            'review_flag.explanation' => ['required_with:review_flag', 'string', 'max:1000', 'regex:/\S/'],
            'review_flag.items' => ['nullable', 'array', 'max:50'],
            'review_flag.items.*' => ['required', 'array:compartment_id,item_id'],
            'review_flag.items.*.compartment_id' => ['required', 'string', 'max:255'],
            'review_flag.items.*.item_id' => ['required', 'string', 'max:255'],
            // Correlates inventory corrections reported while this checkout was in progress.
            'inventory_draft_id' => ['nullable', 'uuid'],
            // One-time vehicle questions. Deliberately no type rule: a malformed section is cleaned item by
            // item below and can never reject the checkout.
            'vehicle_identification' => ['nullable'],
        ]);
        $validated = app(InspectionSubmissionSignals::class)->normalize($validated);
        $identification = app(VehicleIdentificationSubmission::class)
            ->clean($validated['vehicle_identification'] ?? null, $this->identificationKind($id, (string) $validated['client_submission_id']))['section'];
        if ($identification === null) {
            unset($validated['vehicle_identification']);
        } else {
            $validated['vehicle_identification'] = $identification;
        }

        if (is_array($validated['physical_vehicle'] ?? null)) {
            $note = is_string($validated['physical_vehicle']['note'] ?? null) ? trim($validated['physical_vehicle']['note']) : '';
            $validated['physical_vehicle'] = [
                'vehicle_number' => VehicleNumber::normalize($validated['physical_vehicle']['vehicle_number'] ?? null),
                'swap_answer' => $validated['physical_vehicle']['swap_answer'] ?? null,
                'note' => $note === '' ? null : $note,
            ];
        }

        $clientSubmissionId = (string) $validated['client_submission_id'];
        $validated['checklist_version'] = strtolower((string) $validated['checklist_version']);
        $validated['operator_name'] = (string) $employee->name;
        $validated['rank'] = (string) $employee->rank;
        $validated['employee_id'] = (int) $employee->getKey();
        $submissionPayloadHash = $this->submissionPayloadHash($validated);
        $existing = $this->findInspectionByClientSubmissionId($clientSubmissionId);
        if ($existing !== null) {
            return $this->idempotentInspectionResponse($existing, $id, $submissionPayloadHash, $actor->userId());
        }

        $apparatus = Apparatus::findOrFail($id);
        if (! $apparatus->isDailyCheckoutRequired() && $this->justChangedOutFrom($apparatus) === null) {
            return response()->json([
                'message' => 'This apparatus is not configured for Daily Checkout.',
            ], 409);
        }

        $hasInspectionSessionCredentials = $this->hasInspectionSessionCredentials($validated);
        /** @var DailyCheckoutInspectionSession|null $inspectionSession */
        $inspectionSession = null;
        $checklist = null;
        $expectedChecklistVersion = null;
        if ($hasInspectionSessionCredentials) {
            try {
                $inspectionSession = $this->resolveInspectionSessionContract(
                    $validated,
                    $id,
                    $request,
                    $inspectionSessionService,
                );
            } catch (HttpResponseException $exception) {
                // The original of this replay may have committed (and marked
                // the session submitted) since the idempotency check above.
                $committedMeanwhile = $this->findInspectionByClientSubmissionId($clientSubmissionId);
                if ($committedMeanwhile !== null) {
                    return $this->idempotentInspectionResponse($committedMeanwhile, $id, $submissionPayloadHash, $actor->userId());
                }

                throw $exception;
            }
            $checklist = $inspectionSession->checklist_snapshot;
            $expectedChecklistVersion = $inspectionSession->checklist_hash;
        } else {
            $resolution = $checklistResolver->resolve($apparatus);
            $replaced = ! $resolution['usable'] || ! hash_equals((string) $resolution['checklist_version'], $validated['checklist_version'])
                ? $this->recentlyReplacedChecklist($apparatus, $validated['checklist_version'])
                : null;
            if (! $resolution['usable'] && $replaced === null) {
                Log::warning('Daily Checkout checklist resolution failed during submission.', [
                    'apparatus_id' => $apparatus->id,
                    'checklist_type' => $resolution['checklist_type'],
                    'reason' => $resolution['error'],
                ]);

                return response()->json([
                    'message' => 'The Daily Checkout checklist is unavailable. Contact an officer before continuing.',
                    'code' => 'DAILY_CHECKOUT_CHECKLIST_UNAVAILABLE',
                ], 503);
            }

            $checklist = $resolution['checklist'];
            $expectedChecklistVersion = (string) $resolution['checklist_version'];
            if ($resolution['usable'] && $this->isV2Checklist($checklist)) {
                return $this->inspectionSessionRequiredResponse();
            }
            if ($replaced !== null) {
                $checklist = $replaced;
                $expectedChecklistVersion = $validated['checklist_version'];
            }
        }

        if (! is_array($checklist)
            || ! hash_equals($expectedChecklistVersion, $validated['checklist_version'])) {
            return $this->checklistVersionMismatchResponse((string) $expectedChecklistVersion);
        }

        $this->validateCompleteChecklist($validated, $checklist, $checklistResolver, $inspectionSession);

        $storedPaths = [];
        try {
            $prepared = $this->prepareImages($validated, $storedPaths);
            $result = DB::transaction(function () use ($id, $clientSubmissionId, $prepared, $checklistResolver, $inspectionSessionService, $request, $submissionPayloadHash, $hasInspectionSessionCredentials, $actor): array {
                $existing = $this->findInspectionByClientSubmissionId($clientSubmissionId, true);
                if ($existing !== null) {
                    return ['inspection' => $existing, 'created' => false];
                }

                $lockedApparatus = $this->lockApparatusForInspection($id);
                // A replay of the same submission (timeout, Retry) may have
                // waited on this lock while the original committed. Return that
                // receipt instead of treating its now-submitted Fire Boat
                // session as a conflict.
                $committedWhileWaiting = $this->findInspectionByClientSubmissionId($clientSubmissionId, true);
                if ($committedWhileWaiting !== null) {
                    return ['inspection' => $committedWhileWaiting, 'created' => false];
                }
                $changedOut = $lockedApparatus->isDailyCheckoutRequired() ? null : $this->justChangedOutFrom($lockedApparatus);
                if (! $lockedApparatus->isDailyCheckoutRequired() && $changedOut === null) {
                    abort(409, 'This apparatus is not configured for Daily Checkout.');
                }

                /** @var DailyCheckoutInspectionSession|null $lockedInspectionSession */
                $lockedInspectionSession = null;
                if ($hasInspectionSessionCredentials) {
                    $lockedInspectionSession = $this->resolveInspectionSessionContract(
                        $prepared,
                        $id,
                        $request,
                        $inspectionSessionService,
                        true,
                    );
                }
                if ($lockedInspectionSession !== null) {
                    $lockedChecklist = $lockedInspectionSession->checklist_snapshot;
                    $lockedChecklistVersion = $lockedInspectionSession->checklist_hash;
                } else {
                    $lockedResolution = $checklistResolver->resolve($lockedApparatus);
                    $lockedReplacement = ! $lockedResolution['usable'] || ! hash_equals((string) $lockedResolution['checklist_version'], (string) $prepared['checklist_version'])
                        ? $this->recentlyReplacedChecklist($lockedApparatus, (string) $prepared['checklist_version'])
                        : null;
                    if (! $lockedResolution['usable'] && $lockedReplacement === null) {
                        Log::warning('Daily Checkout checklist resolution failed after apparatus lock.', [
                            'apparatus_id' => $lockedApparatus->id,
                            'checklist_type' => $lockedResolution['checklist_type'],
                            'reason' => $lockedResolution['error'],
                        ]);

                        abort(503, 'The Daily Checkout checklist is unavailable. Contact an officer before continuing.');
                    }

                    $lockedChecklist = $lockedResolution['checklist'];
                    $lockedChecklistVersion = $lockedResolution['checklist_version'];
                    if ($lockedReplacement !== null) {
                        $lockedChecklist = $lockedReplacement;
                        $lockedChecklistVersion = (string) $prepared['checklist_version'];
                    }
                }

                if (! is_array($lockedChecklist) || ! is_string($lockedChecklistVersion)
                    || ! hash_equals($lockedChecklistVersion, (string) $prepared['checklist_version'])) {
                    return [
                        'inspection' => null,
                        'created' => false,
                        'checklist_version_mismatch' => true,
                        'checklist_version' => $lockedChecklistVersion,
                    ];
                }
                $this->validateCompleteChecklist(
                    $prepared,
                    $lockedChecklist,
                    $checklistResolver,
                    $lockedInspectionSession,
                );
                $vehicleReports = app(CheckoutVehicleReport::class);
                $vehicleReport = $vehicleReports->assess($lockedApparatus, $prepared['physical_vehicle'] ?? null);
                if (($prepared['processing_version'] ?? null) === 1) {
                    foreach ($prepared['field_values'] ?? [] as $field) {
                        if (($field['id'] === 'vehicle_num' && ! $vehicleReports->paperFieldMatches($vehicleReport, $field['value'], $lockedApparatus))
                            || ($field['id'] === 'mileage' && $field['value'] !== ($prepared['miles'] ?? null))) {
                            throw ValidationException::withMessages(['field_values' => 'The paper mileage and physical vehicle must match this inspection.']);
                        }
                    }
                }

                // The client may display a unit number, but the persisted identity must
                // always come from the apparatus selected by its unique route ID.
                $today = now((string) config('hub.timezone'))->format('Y-m-d');
                $designation = $changedOut->unit_designation ?? $lockedApparatus->designation ?? $lockedApparatus->name ?? 'UNK';
                $designationTag = preg_replace('/[^A-Z0-9]/i', '', $designation) ?: 'UNK';
                $hasCriticalDefects = false;
                foreach ($prepared['defects'] ?? [] as $defectData) {
                    if (in_array($defectData['status'], ['Missing', 'Damaged'], true)) {
                        $hasCriticalDefects = true;
                    }
                }

                $pendingEffects = [
                    'defects' => $prepared['defects'] ?? [],
                    'has_critical_defects' => $hasCriticalDefects,
                ];
                if ($this->isV2Checklist($lockedChecklist)) {
                    $pendingEffects['checklist_v2'] = $this->snapshotV2ChecklistEvidence(
                        $prepared,
                        $lockedChecklist,
                    );
                }
                if ($lockedInspectionSession !== null) {
                    $pendingEffects['inspection_session'] = $this->inspectionSessionEvidence($lockedInspectionSession);
                }

                $inspection = $this->createInspection([
                    'client_submission_id' => $clientSubmissionId,
                    'submission_payload_hash' => $submissionPayloadHash,
                    'checklist_version' => $lockedChecklistVersion,
                    'apparatus_id' => $lockedApparatus->id,
                    'actor_user_id' => $actor->userId(),
                    'operator_name' => $prepared['operator_name'],
                    'rank' => $prepared['rank'],
                    'shift' => $prepared['shift'] ?? null,
                    'unit_number' => $lockedApparatus->vehicle_number,
                    'engine_hours' => $prepared['engine_hours'] ?? null,
                    'miles' => $prepared['miles'] ?? null,
                    'vehicle_number' => $lockedApparatus->vehicle_number,
                    // The checkout was done as the unit that was on this truck, not the identity the truck took after.
                    'designation_at_time' => $changedOut->unit_designation ?? $lockedApparatus->designation,
                    'processing_status' => null,
                    'results' => $prepared['compartments'] ?? null,
                    'checklist_evidence' => ($prepared['processing_version'] ?? null) === 1
                        ? app(\App\Services\InspectionPaperFields::class)->snapshot($prepared, $lockedChecklist)
                        : null,
                    'officer_signature' => $prepared['officer_signature_path'] ?? null,
                    'employee_id' => $prepared['employee_id'] ?? null,
                    'inventory_draft_id' => isset($prepared['inventory_draft_id']) ? strtolower((string) $prepared['inventory_draft_id']) : null,
                    // Kept with the checkout so answers lost to a failed savepoint can be replayed.
                    'vehicle_identification' => is_array($prepared['vehicle_identification'] ?? null) ? $prepared['vehicle_identification'] : null,
                    ...app(InspectionSubmissionSignals::class)->persistedFlag($prepared, $lockedChecklist),
                    // The authenticated actor is immutable provenance; signatures
                    // remain evidence. Operational effects still require review.
                    'pending_effects' => $pendingEffects,
                    'review_status' => 'pending_review',
                    'completed_at' => now(),
                ]);

                $inspectionRef = "INS-{$designationTag}-{$today}-".str_pad((string) $inspection->id, 6, '0', STR_PAD_LEFT);
                $inspection->update(['inspection_reference' => $inspectionRef]);
                if ($lockedInspectionSession !== null) {
                    $lockedInspectionSession->update(['submitted_inspection_id' => $inspection->id]);
                }

                if (is_array($prepared['vehicle_identification'] ?? null)) {
                    // A different physical vehicle than the unit's own was reported: the answers describe that
                    // vehicle, so they are kept but never applied to this unit. A unit with no vehicle number
                    // of its own (Fire Boat 6) has nothing to differ from: its crew is naming the vehicle,
                    // which is exactly what the questions are for.
                    $this->recordVehicleIdentification($lockedApparatus, $prepared['vehicle_identification'], $request, $clientSubmissionId, $inspection, $vehicleReports->describesAnotherTruck($vehicleReport, $lockedApparatus));
                }

                if (($prepared['processing_version'] ?? null) === 1) {
                    $vehicleReports->process($inspection, $lockedApparatus, $vehicleReport, $actor->user(), $prepared['meter_baseline_token'] ?? null, $lockedChecklist);
                }

                DB::afterCommit(function () use ($inspection): void {
                    $this->forgetDisplayReadModels();
                    // Best effort, outside the submission's locks: a failed link never affects the inspection.
                    try {
                        app(InventoryCorrectionService::class)->linkInspection($inspection);
                    } catch (Throwable $exception) {
                        Log::warning('Inventory corrections could not be linked to an inspection.', [
                            'inspection_id' => $inspection->getKey(),
                            'exception' => $exception->getMessage(),
                        ]);
                    }
                });

                return ['inspection' => $inspection, 'created' => true];
            }, 3);

            if (($result['checklist_version_mismatch'] ?? false) === true) {
                $this->deleteStoredPaths($storedPaths);

                return $this->checklistVersionMismatchResponse((string) $result['checklist_version']);
            }

            /** @var ApparatusInspection $inspection */
            $inspection = $result['inspection'];
            if (! $result['created']) {
                $this->deleteStoredPaths($storedPaths);

                return $this->idempotentInspectionResponse($inspection, $id, $submissionPayloadHash, $actor->userId());
            }

            return response()->json($this->inspectionReceipt($inspection), 201);
        } catch (QueryException $exception) {
            $this->deleteStoredPaths($storedPaths);
            if (! $this->isClientSubmissionIdUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = $this->findInspectionByClientSubmissionId($clientSubmissionId);
            if ($existing !== null) {
                return $this->idempotentInspectionResponse($existing, $id, $submissionPayloadHash, $actor->userId());
            }

            throw $exception;
        } catch (Throwable $exception) {
            $this->deleteStoredPaths($storedPaths);

            throw $exception;
        }
    }

    /**
     * Identification answers ride along with the checkout but never decide its outcome: they are
     * recorded inside a savepoint, and an ordinary failure is logged while the checkout is still
     * saved (the savepoint was rolled back by Laravel, and the section stays on the inspection so
     * `fleet:replay-vehicle-identification` can record it later).
     *
     * A deadlock, serialization failure, lock timeout or lost connection is different: inside a
     * nested transaction Laravel does not roll back to the savepoint, the outer transaction is left
     * aborted, and swallowing it would only make the checkout fail later. Those are rethrown so the
     * outer transaction's own retry handles them.
     *
     * @param  array{kind: string, questions_version: string, answers: list<array<string, mixed>>}  $section
     */
    private function recordVehicleIdentification(
        Apparatus $apparatus,
        array $section,
        Request $request,
        string $clientSubmissionId,
        ApparatusInspection $inspection,
        bool $differentVehicleReported,
    ): void {
        $user = $request->user();
        try {
            DB::transaction(fn () => app(VehicleIdentificationService::class)->record(
                $apparatus,
                $section,
                $user instanceof User ? $user : null,
                $clientSubmissionId,
                (int) $inspection->getKey(),
                $differentVehicleReported,
            ));
        } catch (Throwable $exception) {
            if (TransientDatabaseFailure::isTransient($exception)) {
                throw $exception;
            }
            Log::error('Vehicle identification answers could not be recorded; the checkout was saved without them.', [
                'apparatus_id' => $apparatus->getKey(),
                'inspection_id' => $inspection->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The kind (vehicle or boat) the unit's answers are validated against. A replay of a stored
     * submission uses the kind that submission was cleaned with, so editing the unit's type in
     * between can never change its replay hash.
     */
    private function identificationKind(int $apparatusId, string $clientSubmissionId): string
    {
        $stored = ApparatusInspection::query()
            ->where('client_submission_id', $clientSubmissionId)
            ->where('apparatus_id', $apparatusId)
            ->value('vehicle_identification');
        $stored = is_string($stored) ? json_decode($stored, true) : $stored;
        if (is_array($stored) && in_array($stored['kind'] ?? null, [VehicleIdentificationFields::KIND_VEHICLE, VehicleIdentificationFields::KIND_BOAT], true)) {
            return (string) $stored['kind'];
        }

        $apparatus = Apparatus::query()->find($apparatusId);

        return $apparatus instanceof Apparatus ? VehicleIdentificationFields::kindFor($apparatus) : VehicleIdentificationFields::KIND_VEHICLE;
    }

    /** Equipment context for authenticated members; no personnel, internal notes or private storage paths. */
    private function knownFindings(Apparatus $apparatus, array $checklist): array
    {
        return app(\App\Services\Defects\DefectContinuityService::class)->openFindings($apparatus, $checklist);
    }

    /**
     * Approve a pending-review inspection (authenticated/authorized only).
     *
     * SECURITY (H-01): the public submission endpoint records the inspection
     * as 'pending_review'; only an authorized reviewer can apply it here. The
     * apparatus goes "Out of Service" only when the reviewer explicitly sends
     * mark_out_of_service=true; approving never does it implicitly.
     */
    public function approveInspection(
        Request $request,
        ApparatusInspection $inspection,
        ApparatusInspectionApprovalService $approvalService,
    ): JsonResponse {
        $reviewer = $request->user();
        abort_unless($reviewer instanceof User && $reviewer->can('approve', $inspection), 403);

        // Approval never takes a unit Out of Service on its own; the reviewer
        // must ask for it explicitly. No bundled client calls this endpoint
        // without the flag, so the safe default is false.
        $validated = $request->validate([
            'mark_out_of_service' => ['sometimes', 'boolean'],
        ]);

        $inspection = $approvalService->approve(
            (int) $inspection->getKey(),
            $reviewer,
            (bool) ($validated['mark_out_of_service'] ?? false),
        );

        return response()->json($inspection->fresh()->load('apparatus'));
    }

    /**
     * Reject a pending-review inspection without applying any reported
     * apparatus effects. A reviewer note is retained in the append-only
     * decision history with the authenticated actor.
     */
    public function rejectInspection(
        Request $request,
        ApparatusInspection $inspection,
        ApparatusInspectionApprovalService $approvalService,
    ): JsonResponse {
        $reviewer = $request->user();
        abort_unless($reviewer instanceof User && $reviewer->can('reject', $inspection), 403);

        $validated = $request->validate([
            'review_notes' => ['required', 'string', 'max:2000'],
        ]);

        $inspection = $approvalService->reject(
            (int) $inspection->getKey(),
            $reviewer,
            trim($validated['review_notes']),
        );

        return response()->json($inspection->fresh()->load('apparatus'));
    }

    /**
     * Return employee list for the operator name dropdown.
     */
    public function employees()
    {
        // employee_id is also the portal login identifier. The public kiosk
        // directory only needs an opaque database key to link the inspection.
        $employees = Employee::select('id', 'name', 'rank')
            ->orderBy('name')
            ->get();

        return response()->json($employees);
    }

    private function findInspectionByClientSubmissionId(string $clientSubmissionId, bool $lock = false): ?ApparatusInspection
    {
        $query = ApparatusInspection::query()->where('client_submission_id', $clientSubmissionId);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** @param array<string, mixed> $attributes */
    protected function createInspection(array $attributes): ApparatusInspection
    {
        return ApparatusInspection::query()->create($attributes);
    }

    protected function lockApparatusForInspection(int $id): Apparatus
    {
        return Apparatus::query()->lockForUpdate()->findOrFail($id);
    }

    /** @param array<string, mixed> $submission */
    private function hasInspectionSessionCredentials(array $submission): bool
    {
        return isset($submission['inspection_session_id'])
            || isset($submission['inspection_session_token'])
            || isset($submission['inspection_session_replay_key']);
    }

    /** @param array<string, mixed> $submission */
    private function resolveInspectionSessionContract(
        array $submission,
        int $apparatusId,
        Request $request,
        DailyCheckoutInspectionSessionService $inspectionSessionService,
        bool $lock = false,
    ): DailyCheckoutInspectionSession {
        $publicId = $submission['inspection_session_id'] ?? null;
        $token = $submission['inspection_session_token'] ?? null;
        $replayKey = $submission['inspection_session_replay_key'] ?? null;
        if (! is_string($publicId) || ! is_string($token) || ! is_string($replayKey)) {
            $this->throwInspectionSessionError(
                'A server-issued Fire Boat inspection session is required before this inspection can be submitted.',
                'DAILY_CHECKOUT_INSPECTION_SESSION_REQUIRED',
            );
        }

        $query = DailyCheckoutInspectionSession::query()->where('public_id', $publicId);
        $session = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($session === null || ! $inspectionSessionService->tokenIsValid($session, $token)) {
            $this->throwInspectionSessionError(
                'The Fire Boat inspection session is unavailable. Reconnect and start a new inspection session.',
                'DAILY_CHECKOUT_INSPECTION_SESSION_INVALID',
            );
        }

        if (! hash_equals($session->replay_key, $replayKey)) {
            $this->throwInspectionSessionError(
                'This Fire Boat inspection replay key does not match the server-issued session.',
                'DAILY_CHECKOUT_INSPECTION_SESSION_REPLAY_MISMATCH',
            );
        }

        if ((int) $session->apparatus_id !== $apparatusId) {
            $this->throwInspectionSessionError(
                'This Fire Boat inspection session belongs to a different apparatus.',
                'DAILY_CHECKOUT_INSPECTION_SESSION_APPARATUS_MISMATCH',
            );
        }

        $actor = $request->user();
        $actorUserId = $actor instanceof User ? (int) $actor->getKey() : null;
        if ($session->actor_user_id !== null && $actorUserId !== (int) $session->actor_user_id) {
            $this->throwInspectionSessionError(
                'This Fire Boat inspection session was issued to a different authenticated user.',
                'DAILY_CHECKOUT_INSPECTION_SESSION_ACTOR_MISMATCH',
                403,
            );
        }

        $actorSessionHash = $this->browserBindingHash($request);
        if ($session->actor_session_hash !== null && (
            $actorSessionHash === null || ! hash_equals($session->actor_session_hash, $actorSessionHash)
        )) {
            $this->throwInspectionSessionError(
                'This Fire Boat inspection session was issued to a different browser session.',
                'DAILY_CHECKOUT_INSPECTION_SESSION_ACTOR_MISMATCH',
                403,
            );
        }

        if ($session->abandoned_at !== null) {
            $this->throwInspectionSessionError(
                'This Fire Boat inspection session was abandoned before it was submitted.',
                'DAILY_CHECKOUT_INSPECTION_SESSION_ABANDONED',
            );
        }

        if ($session->submitted_inspection_id !== null) {
            $this->throwInspectionSessionError(
                'This Fire Boat inspection session has already been submitted.',
                'DAILY_CHECKOUT_INSPECTION_SESSION_ALREADY_SUBMITTED',
            );
        }

        if ($session->expires_at === null
            || $session->expires_at->lessThanOrEqualTo(CarbonImmutable::now(DailyCheckoutInspectionSessionService::TIMEZONE))) {
            $this->throwInspectionSessionError(
                'This Fire Boat inspection session has expired. Reconnect and start a new inspection session.',
                'DAILY_CHECKOUT_INSPECTION_SESSION_EXPIRED',
            );
        }

        if (! $inspectionSessionService->checklistHasIntegrity($session)
            || ! $inspectionSessionService->dueTasksHaveIntegrity($session)) {
            $this->throwInspectionSessionError(
                'The Fire Boat inspection session contract could not be verified. Reconnect and start a new inspection session.',
                'DAILY_CHECKOUT_INSPECTION_SESSION_INVALID',
            );
        }

        return $session;
    }

    private function inspectionSessionRequiredResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'A server-issued Fire Boat inspection session is required before this inspection can be submitted.',
            'code' => 'DAILY_CHECKOUT_INSPECTION_SESSION_REQUIRED',
        ], 409);
    }

    /** @return array{token: string, hash: string, new: bool} */
    private function browserBindingForIssue(
        Request $request,
        ?string $issuanceKey,
        DailyCheckoutInspectionSessionService $inspectionSessionService,
    ): array {
        $token = $request->cookie(self::INSPECTION_BROWSER_COOKIE);
        if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/i', $token) === 1) {
            return [
                'token' => strtolower($token),
                'hash' => hash('sha256', strtolower($token)),
                'new' => false,
            ];
        }

        $token = $issuanceKey === null
            ? bin2hex(random_bytes(32))
            : $inspectionSessionService->browserBindingTokenForIssuanceKey($issuanceKey);

        return [
            'token' => $token,
            'hash' => hash('sha256', $token),
            'new' => true,
        ];
    }

    private function browserBindingHash(Request $request): ?string
    {
        $token = $request->cookie(self::INSPECTION_BROWSER_COOKIE);

        return is_string($token) && preg_match('/\A[a-f0-9]{64}\z/i', $token) === 1
            ? hash('sha256', strtolower($token))
            : null;
    }

    private function browserBindingCookie(string $token, Request $request): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie(
            name: self::INSPECTION_BROWSER_COOKIE,
            value: $token,
            minutes: 24 * 60,
            path: '/api/public',
            domain: null,
            secure: $request->isSecure() || app()->isProduction(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    /** @return array<string, string|null> */
    private function inspectionSessionEvidence(DailyCheckoutInspectionSession $session): array
    {
        return [
            'id' => $session->public_id,
            'issued_at' => $session->issued_at?->toIso8601String(),
            'duty_date' => $session->duty_date?->toDateString(),
            'checklist_template_id' => $session->checklist_template_id,
            'checklist_template_version' => $session->checklist_template_version,
            'checklist_hash' => $session->checklist_hash,
            'due_tasks_hash' => $session->due_tasks_hash,
            'replay_key' => $session->replay_key,
            'expires_at' => $session->expires_at?->toIso8601String(),
        ];
    }

    private function throwInspectionSessionError(string $message, string $code, int $status = 409): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }

    private function inspectionSessionExceptionResponse(DailyCheckoutInspectionSessionException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->errorCode,
        ], $exception->status);
    }

    private function checklistVersionMismatchResponse(string $currentChecklistVersion): JsonResponse
    {
        return response()->json([
            'message' => 'The Daily Checkout checklist changed after this inspection was saved. Officer review is required before a new submission.',
            'code' => 'DAILY_CHECKOUT_CHECKLIST_VERSION_REVIEW_REQUIRED',
            'current_checklist_version' => $currentChecklistVersion,
        ], 409);
    }

    private function isClientSubmissionIdUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        if (! in_array($sqlState, ['23000', '23505'], true)) {
            return false;
        }

        return str_contains(strtolower($exception->getMessage()), 'client_submission_id');
    }

    private function idempotentInspectionResponse(
        ApparatusInspection $inspection,
        int $apparatusId,
        string $submissionPayloadHash,
        int $actorUserId,
    ): JsonResponse {
        if ($inspection->actor_user_id !== null && (int) $inspection->actor_user_id !== $actorUserId) {
            return response()->json([
                'message' => 'This queued submission belongs to a different authenticated account.',
                'code' => 'OFFLINE_QUEUE_OWNER_MISMATCH',
            ], 409);
        }

        if ((int) $inspection->apparatus_id !== $apparatusId) {
            return response()->json([
                'message' => 'This submission identifier was already used for a different apparatus.',
            ], 409);
        }

        if (! is_string($inspection->submission_payload_hash)
            || ! hash_equals($inspection->submission_payload_hash, $submissionPayloadHash)) {
            return response()->json([
                'message' => 'This submission identifier was already used with different inspection data.',
                'code' => 'DAILY_CHECKOUT_SUBMISSION_REPLAY_CONFLICT',
            ], 409);
        }

        return response()->json($this->inspectionReceipt($inspection));
    }

    /** @param array<string, mixed> $payload */
    private function submissionPayloadHash(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonicalizeSubmissionPayload($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalizeSubmissionPayload(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalizeSubmissionPayload(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeSubmissionPayload($item);
        }

        return $value;
    }

    private function inspectionReceipt(ApparatusInspection $inspection): array
    {
        $inspection = $inspection->fresh() ?? $inspection;

        return [
            'id' => $inspection->id,
            'apparatus_id' => $inspection->apparatus_id,
            'inspection_reference' => $inspection->inspection_reference,
            'review_status' => $inspection->review_status,
            'processing_status' => $inspection->processing_status,
            'completed_at' => $inspection->completed_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $storedPaths
     * @return array<string, mixed>
     */
    private function prepareImages(array $data, array &$storedPaths): array
    {
        if (filled($data['officer_signature'] ?? null)) {
            $data['officer_signature_path'] = $this->storeImageOrFail(
                (string) $data['officer_signature'],
                'signatures',
                'signature',
                'officer_signature',
            );
            $storedPaths[] = $data['officer_signature_path'];
        }
        unset($data['officer_signature']);

        $data['defects'] ??= [];
        foreach ($data['defects'] as $index => &$defect) {
            if (filled($defect['photo'] ?? null)) {
                $defect['photo_path'] = $this->storeImageOrFail(
                    (string) $defect['photo'],
                    'defects',
                    'defect',
                    "defects.{$index}.photo",
                );
                $storedPaths[] = $defect['photo_path'];
            }
            unset($defect['photo']);
        }
        unset($defect);

        foreach ($data['compartments'] as $compartmentIndex => &$compartment) {
            foreach ($compartment['items'] as $itemIndex => &$item) {
                if (filled($item['photo'] ?? null)) {
                    $matching = collect($data['defects'])->first(fn (array $finding): bool => $finding['compartment'] === $compartment['name'] && $finding['item'] === $item['name']);
                    $existingPath = $matching['photo_path'] ?? null;
                    $photoPath = is_string($existingPath) ? $existingPath : $this->storeImageOrFail(
                        (string) $item['photo'], 'defects', 'observation', "compartments.{$compartmentIndex}.items.{$itemIndex}.photo",
                    );
                    $item['photo_path'] = $photoPath;
                    $storedPaths[] = $photoPath;
                }
                unset($item['photo']);
            }
            unset($item);
        }
        unset($compartment);

        return $data;
    }

    private function storeImageOrFail(string $payload, string $directory, string $prefix, string $field): string
    {
        $path = Base64Image::store($payload, $directory, $prefix);

        if ($path === null) {
            throw ValidationException::withMessages([
                $field => 'The uploaded image must be a valid JPEG, PNG, WebP, or GIF image.',
            ]);
        }

        return $path;
    }

    /** @param list<string> $paths */
    private function deleteStoredPaths(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function forgetDisplayReadModels(): void
    {
        Cache::forget(DisplaySnapshotService::SNAPSHOT_CACHE_KEY);
        Cache::forget(DisplaySnapshotService::STATIONS_CACHE_KEY);
    }

    /**
     * A browser may never decide which rows count as a completed Daily Checkout.
     * The submitted matrix must be an exact representation of the current,
     * server-resolved checklist, and every non-present row must have a matching
     * defect record for the review workflow.
     *
     * @param  array<string, mixed>  $submission
     * @param  array<string, mixed>|null  $checklist
     */
    private function validateCompleteChecklist(
        array $submission,
        ?array $checklist,
        DailyCheckoutChecklistResolver $checklistResolver,
        ?DailyCheckoutInspectionSession $inspectionSession = null,
    ): void {
        if (($submission['processing_version'] ?? null) === 1 && $checklist !== null) {
            app(\App\Services\InspectionPaperFields::class)->validate($submission, $checklist);
        }
        app(InspectionSubmissionSignals::class)->validate($submission, $checklist);
        if ($this->isV2Checklist($checklist)) {
            $this->validateCompleteV2Checklist($submission, $checklist, $checklistResolver, $inspectionSession);

            return;
        }

        if ($checklist === null || ! is_array($checklist['compartments'] ?? null)) {
            throw ValidationException::withMessages([
                'compartments' => 'The Daily Checkout checklist could not be validated.',
            ]);
        }

        $expected = [];
        foreach ($checklist['compartments'] as $compartment) {
            if (! is_array($compartment)) {
                throw ValidationException::withMessages([
                    'compartments' => 'The Daily Checkout checklist could not be validated.',
                ]);
            }

            $compartmentId = $this->canonicalChecklistString($compartment['id'] ?? null);
            $compartmentName = $this->canonicalChecklistString($compartment['name'] ?? $compartment['title'] ?? null);
            if ($compartmentId === null || $compartmentName === null || ! is_array($compartment['items'] ?? null)) {
                throw ValidationException::withMessages([
                    'compartments' => 'The Daily Checkout checklist could not be validated.',
                ]);
            }

            $itemNames = [];
            foreach ($compartment['items'] as $item) {
                $itemName = is_array($item) ? $this->canonicalChecklistString($item['name'] ?? null) : null;
                if ($itemName === null) {
                    throw ValidationException::withMessages([
                        'compartments' => 'The Daily Checkout checklist could not be validated.',
                    ]);
                }
                $itemNames[] = $itemName;
            }

            $expected[$compartmentId] = [
                'name' => $compartmentName,
                'item_names' => $itemNames,
            ];
        }

        $submittedById = [];
        foreach ($submission['compartments'] as $compartment) {
            $compartmentId = $this->canonicalChecklistString($compartment['id'] ?? null);
            if ($compartmentId === null || isset($submittedById[$compartmentId])) {
                throw ValidationException::withMessages([
                    'compartments' => 'Each current Daily Checkout compartment must be submitted exactly once.',
                ]);
            }
            $submittedById[$compartmentId] = $compartment;
        }

        if (array_diff_key($expected, $submittedById) !== [] || array_diff_key($submittedById, $expected) !== []) {
            throw ValidationException::withMessages([
                'compartments' => 'The submitted inspection must include every current Daily Checkout compartment exactly once.',
            ]);
        }

        $requiredDefects = [];
        foreach ($expected as $compartmentId => $expectedCompartment) {
            /** @var array<string, mixed> $submittedCompartment */
            $submittedCompartment = $submittedById[$compartmentId];
            $submittedName = $this->canonicalChecklistString($submittedCompartment['name'] ?? null);
            if ($submittedName !== $expectedCompartment['name']) {
                throw ValidationException::withMessages([
                    'compartments' => 'The submitted inspection does not match the current Daily Checkout checklist.',
                ]);
            }

            $submittedItems = $submittedCompartment['items'] ?? null;
            if (! is_array($submittedItems)) {
                throw ValidationException::withMessages([
                    'compartments' => 'The submitted inspection does not match the current Daily Checkout checklist.',
                ]);
            }

            $expectedItemNames = $expectedCompartment['item_names'];
            $submittedItemNames = [];
            foreach ($submittedItems as $item) {
                $itemName = is_array($item) ? $this->canonicalChecklistString($item['name'] ?? null) : null;
                if ($itemName === null) {
                    throw ValidationException::withMessages([
                        'compartments' => 'The submitted inspection must contain every current Daily Checkout item exactly once.',
                    ]);
                }

                $submittedItemNames[] = $itemName;
            }
            sort($expectedItemNames);
            sort($submittedItemNames);
            if ($submittedItemNames !== $expectedItemNames) {
                throw ValidationException::withMessages([
                    'compartments' => 'The submitted inspection must contain every current Daily Checkout item exactly once.',
                ]);
            }

            foreach ($submittedItems as $item) {
                if (! is_array($item)) {
                    throw ValidationException::withMessages([
                        'compartments' => 'The submitted inspection does not match the current Daily Checkout checklist.',
                    ]);
                }

                $status = (string) ($item['status'] ?? '');
                if ($status === 'Present') {
                    continue;
                }

                $key = $this->defectKey(
                    $expectedCompartment['name'],
                    $this->canonicalChecklistString($item['name'] ?? null) ?? '',
                    $status,
                );
                $requiredDefects[$key] = ($requiredDefects[$key] ?? 0) + 1;
            }
        }

        $submittedDefects = [];
        foreach ($submission['defects'] ?? [] as $defect) {
            $compartment = $this->canonicalChecklistString($defect['compartment'] ?? null);
            $item = $this->canonicalChecklistString($defect['item'] ?? null);
            if ($compartment === null || $item === null) {
                throw ValidationException::withMessages([
                    'defects' => 'Every Missing or Damaged checklist item must have one matching defect record.',
                ]);
            }

            $key = $this->defectKey(
                $compartment,
                $item,
                (string) ($defect['status'] ?? ''),
            );
            $submittedDefects[$key] = ($submittedDefects[$key] ?? 0) + 1;
        }

        ksort($requiredDefects);
        ksort($submittedDefects);
        if ($submittedDefects !== $requiredDefects) {
            throw ValidationException::withMessages([
                'defects' => 'Every Missing or Damaged checklist item must have one matching defect record.',
            ]);
        }
    }

    /** @param array<string, mixed>|null $checklist */
    private function isV2Checklist(?array $checklist): bool
    {
        return $checklist !== null && ($checklist['schema_version'] ?? 1) === 2;
    }

    /**
     * V2 identifies every submitted field, task, compartment, and item by an
     * immutable machine ID. V1 stays on its historical display-name contract.
     *
     * @param  array<string, mixed>  $submission
     * @param  array<string, mixed>  $checklist
     */
    private function validateCompleteV2Checklist(
        array $submission,
        array $checklist,
        DailyCheckoutChecklistResolver $checklistResolver,
        ?DailyCheckoutInspectionSession $inspectionSession,
    ): void {
        $fields = $checklist['fields'] ?? null;
        $inspectionDateFieldId = $this->canonicalChecklistString($checklist['inspectionDateFieldId'] ?? null);
        if (! is_array($fields) || $inspectionDateFieldId === null) {
            throw ValidationException::withMessages([
                'field_values' => 'The Daily Checkout field contract could not be validated.',
            ]);
        }

        $expectedFields = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                throw ValidationException::withMessages([
                    'field_values' => 'The Daily Checkout field contract could not be validated.',
                ]);
            }

            $fieldId = $this->canonicalChecklistString($field['id'] ?? null);
            $inputType = $this->canonicalChecklistString($field['inputType'] ?? null);
            if ($fieldId === null || $inputType === null || isset($expectedFields[$fieldId])) {
                throw ValidationException::withMessages([
                    'field_values' => 'The Daily Checkout field contract could not be validated.',
                ]);
            }

            $expectedFields[$fieldId] = [
                'input_type' => $inputType,
                'required' => ($field['required'] ?? false) === true,
            ];
        }

        $submittedFieldValues = [];
        foreach ($submission['field_values'] ?? [] as $fieldValue) {
            $fieldId = is_array($fieldValue) ? $this->canonicalChecklistString($fieldValue['id'] ?? null) : null;
            if ($fieldId === null || isset($submittedFieldValues[$fieldId]) || ! isset($expectedFields[$fieldId])
                || ! is_array($fieldValue) || ! array_key_exists('value', $fieldValue)) {
                throw ValidationException::withMessages([
                    'field_values' => 'Each current Daily Checkout field must be submitted exactly once.',
                ]);
            }

            if (! $this->isValidV2FieldValue(
                $expectedFields[$fieldId]['input_type'],
                $fieldValue['value'],
                $expectedFields[$fieldId]['required'],
            )) {
                throw ValidationException::withMessages([
                    'field_values' => 'A Daily Checkout field value does not match its configured type.',
                ]);
            }

            $submittedFieldValues[$fieldId] = $fieldValue;
        }

        if (array_diff_key($expectedFields, $submittedFieldValues) !== []
            || array_diff_key($submittedFieldValues, $expectedFields) !== []) {
            throw ValidationException::withMessages([
                'field_values' => 'The submitted inspection must include every current Daily Checkout field exactly once.',
            ]);
        }

        $inspectionDateValue = $submittedFieldValues[$inspectionDateFieldId]['value'] ?? null;
        $inspectionDate = $this->v2InspectionDate($inspectionDateValue);
        if ($inspectionDate === null) {
            throw ValidationException::withMessages([
                'field_values' => 'The Daily Checkout inspection date is invalid.',
            ]);
        }

        if ($inspectionSession !== null) {
            if ($inspectionSession->duty_date === null
                || ! hash_equals($inspectionSession->duty_date->toDateString(), $inspectionDate->toDateString())) {
                throw ValidationException::withMessages([
                    'field_values' => 'The Daily Checkout inspection date must match the server-issued Fire Boat inspection session.',
                ]);
            }

            $this->validateV2ScheduledTasks($submission, $inspectionSession->due_tasks ?? []);
        } else {
            $this->validateV2ScheduledTasks(
                $submission,
                $checklistResolver->dueTasksFor($checklist, $inspectionDate),
            );
        }
        $this->validateV2CompartmentMatrix($submission, $checklist);
    }

    /**
     * Persist the server-resolved labels and task instructions that authorized
     * reviewers need to evaluate a V2 submission after a template changes.
     *
     * @param  array<string, mixed>  $submission
     * @param  array<string, mixed>  $checklist
     * @return array<string, mixed>
     */
    private function snapshotV2ChecklistEvidence(array $submission, array $checklist): array
    {
        $templateId = $this->canonicalChecklistString($checklist['template_id'] ?? null);
        $templateVersion = $this->canonicalChecklistString($checklist['template_version'] ?? null);
        if ($templateId === null || $templateVersion === null) {
            throw new \LogicException('The validated Daily Checkout template metadata is unavailable.');
        }

        $fieldDefinitions = [];
        foreach ($checklist['fields'] ?? [] as $field) {
            if (! is_array($field)) {
                throw new \LogicException('The validated Daily Checkout field definition is unavailable.');
            }

            $fieldId = $this->canonicalChecklistString($field['id'] ?? null);
            $name = $this->canonicalChecklistString($field['name'] ?? null);
            $inputType = $this->canonicalChecklistString($field['inputType'] ?? null);
            if ($fieldId === null || $name === null || $inputType === null) {
                throw new \LogicException('The validated Daily Checkout field definition is unavailable.');
            }

            $fieldDefinitions[$fieldId] = [
                'name' => $name,
                'input_type' => $inputType,
                'required' => ($field['required'] ?? false) === true,
            ];
        }

        $fieldValues = [];
        foreach ($submission['field_values'] ?? [] as $fieldValue) {
            if (! is_array($fieldValue)) {
                throw new \LogicException('The validated Daily Checkout field value is unavailable.');
            }

            $fieldId = $this->canonicalChecklistString($fieldValue['id'] ?? null);
            $definition = $fieldId === null ? null : ($fieldDefinitions[$fieldId] ?? null);
            if ($fieldId === null || $definition === null || ! array_key_exists('value', $fieldValue)) {
                throw new \LogicException('The validated Daily Checkout field value is unavailable.');
            }

            $fieldValues[] = [
                'id' => $fieldId,
                'name' => $definition['name'],
                'input_type' => $definition['input_type'],
                'required' => $definition['required'],
                'value' => $fieldValue['value'],
            ];
        }

        $taskDefinitions = [];
        foreach ($checklist['recurringTasks'] ?? [] as $task) {
            if (! is_array($task) || ! is_array($task['recurrence'] ?? null)) {
                throw new \LogicException('The validated Daily Checkout scheduled-duty definition is unavailable.');
            }

            $taskId = $this->canonicalChecklistString($task['id'] ?? null);
            $name = $this->canonicalChecklistString($task['name'] ?? null);
            if ($taskId === null || $name === null) {
                throw new \LogicException('The validated Daily Checkout scheduled-duty definition is unavailable.');
            }

            $taskDefinitions[$taskId] = [
                'name' => $name,
                'instructions' => $this->canonicalChecklistString($task['instructions'] ?? null),
                'recurrence' => $task['recurrence'],
                'recurrence_label' => $this->v2RecurrenceLabel($task['recurrence']),
            ];
        }

        $scheduledTasks = [];
        foreach ($submission['scheduled_tasks'] ?? [] as $task) {
            if (! is_array($task)) {
                throw new \LogicException('The validated Daily Checkout scheduled-duty result is unavailable.');
            }

            $taskId = $this->canonicalChecklistString($task['id'] ?? null);
            $definition = $taskId === null ? null : ($taskDefinitions[$taskId] ?? null);
            if ($taskId === null || $definition === null) {
                throw new \LogicException('The validated Daily Checkout scheduled-duty result is unavailable.');
            }

            $scheduledTasks[] = [
                'id' => $taskId,
                'name' => $definition['name'],
                'instructions' => $definition['instructions'],
                'recurrence' => $definition['recurrence'],
                'recurrence_label' => $definition['recurrence_label'],
                'status' => $task['status'],
                'notes' => $task['notes'] ?? null,
            ];
        }

        return [
            'template_id' => $templateId,
            'template_version' => $templateVersion,
            'field_values' => $fieldValues,
            'scheduled_tasks' => $scheduledTasks,
        ];
    }

    /** @param array<string, mixed> $recurrence */
    private function v2RecurrenceLabel(array $recurrence): string
    {
        return match ($recurrence['type'] ?? null) {
            'weekday' => 'Every '.ucfirst((string) $recurrence['weekday']),
            'monthly_day' => 'Day '.(string) $recurrence['day'].' of each month',
            default => 'Configured recurrence',
        };
    }

    private function isValidV2FieldValue(string $inputType, mixed $value, bool $required): bool
    {
        if ($value === null) {
            return ! $required;
        }

        return match ($inputType) {
            'text', 'date' => is_string($value),
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'percentage' => (is_int($value) || is_float($value))
                && is_finite((float) $value)
                && $value >= 0
                && $value <= 100,
            'checkbox' => is_bool($value),
            default => false,
        };
    }

    private function v2InspectionDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, DailyCheckoutInspectionSessionService::TIMEZONE);
        } catch (\Throwable) {
            return null;
        }

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }

    /**
     * @param  array<string, mixed>  $submission
     * @param  list<array<string, mixed>>  $dueTasks
     */
    private function validateV2ScheduledTasks(
        array $submission,
        array $dueTasks,
    ): void {
        $expectedTasks = [];
        foreach ($dueTasks as $task) {
            $taskId = $this->canonicalChecklistString($task['id'] ?? null);
            if ($taskId === null || isset($expectedTasks[$taskId])) {
                throw ValidationException::withMessages([
                    'scheduled_tasks' => 'The Daily Checkout scheduled-duty contract could not be validated.',
                ]);
            }

            $expectedTasks[$taskId] = true;
        }

        $submittedTasks = [];
        foreach ($submission['scheduled_tasks'] ?? [] as $task) {
            $taskId = is_array($task) ? $this->canonicalChecklistString($task['id'] ?? null) : null;
            if ($taskId === null || isset($submittedTasks[$taskId]) || ! isset($expectedTasks[$taskId])) {
                throw ValidationException::withMessages([
                    'scheduled_tasks' => 'Only scheduled Daily Checkout duties due on the inspection date may be submitted.',
                ]);
            }

            $submittedTasks[$taskId] = true;
        }

        if (array_diff_key($expectedTasks, $submittedTasks) !== []
            || array_diff_key($submittedTasks, $expectedTasks) !== []) {
            throw ValidationException::withMessages([
                'scheduled_tasks' => 'Every scheduled Daily Checkout duty due on the inspection date must be submitted exactly once.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $submission
     * @param  array<string, mixed>  $checklist
     */
    private function validateV2CompartmentMatrix(array $submission, array $checklist): void
    {
        $expected = [];
        foreach ($checklist['compartments'] ?? [] as $compartment) {
            if (! is_array($compartment)) {
                throw ValidationException::withMessages([
                    'compartments' => 'The Daily Checkout checklist could not be validated.',
                ]);
            }

            $compartmentId = $this->canonicalChecklistString($compartment['id'] ?? null);
            $compartmentName = $this->canonicalChecklistString($compartment['name'] ?? $compartment['title'] ?? null);
            if ($compartmentId === null || $compartmentName === null || isset($expected[$compartmentId])
                || ! is_array($compartment['items'] ?? null)) {
                throw ValidationException::withMessages([
                    'compartments' => 'The Daily Checkout checklist could not be validated.',
                ]);
            }

            $expectedItems = [];
            foreach ($compartment['items'] as $item) {
                $itemId = is_array($item) ? $this->canonicalChecklistString($item['id'] ?? null) : null;
                $itemName = is_array($item) ? $this->canonicalChecklistString($item['name'] ?? null) : null;
                if ($itemId === null || $itemName === null || isset($expectedItems[$itemId])) {
                    throw ValidationException::withMessages([
                        'compartments' => 'The Daily Checkout checklist could not be validated.',
                    ]);
                }

                $expectedItems[$itemId] = $itemName;
            }

            $expected[$compartmentId] = [
                'name' => $compartmentName,
                'items' => $expectedItems,
            ];
        }

        $submittedById = [];
        foreach ($submission['compartments'] as $compartment) {
            $compartmentId = is_array($compartment) ? $this->canonicalChecklistString($compartment['id'] ?? null) : null;
            if ($compartmentId === null || isset($submittedById[$compartmentId]) || ! isset($expected[$compartmentId])) {
                throw ValidationException::withMessages([
                    'compartments' => 'Each current Daily Checkout compartment must be submitted exactly once.',
                ]);
            }

            $submittedById[$compartmentId] = $compartment;
        }

        if (array_diff_key($expected, $submittedById) !== [] || array_diff_key($submittedById, $expected) !== []) {
            throw ValidationException::withMessages([
                'compartments' => 'The submitted inspection must include every current Daily Checkout compartment exactly once.',
            ]);
        }

        $requiredDefects = [];
        foreach ($expected as $compartmentId => $expectedCompartment) {
            /** @var array<string, mixed> $submittedCompartment */
            $submittedCompartment = $submittedById[$compartmentId];
            if ($this->canonicalChecklistString($submittedCompartment['name'] ?? null) !== $expectedCompartment['name']
                || ! is_array($submittedCompartment['items'] ?? null)) {
                throw ValidationException::withMessages([
                    'compartments' => 'The submitted inspection does not match the current Daily Checkout checklist.',
                ]);
            }

            $submittedItems = [];
            foreach ($submittedCompartment['items'] as $item) {
                $itemId = is_array($item) ? $this->canonicalChecklistString($item['id'] ?? null) : null;
                if ($itemId === null || isset($submittedItems[$itemId]) || ! isset($expectedCompartment['items'][$itemId])
                    || ! is_array($item)
                    || $this->canonicalChecklistString($item['name'] ?? null) !== $expectedCompartment['items'][$itemId]) {
                    throw ValidationException::withMessages([
                        'compartments' => 'The submitted inspection does not match the current Daily Checkout checklist.',
                    ]);
                }

                $submittedItems[$itemId] = $item;
            }

            if (array_diff_key($expectedCompartment['items'], $submittedItems) !== []
                || array_diff_key($submittedItems, $expectedCompartment['items']) !== []) {
                throw ValidationException::withMessages([
                    'compartments' => 'The submitted inspection must contain every current Daily Checkout item exactly once.',
                ]);
            }

            foreach ($submittedItems as $itemId => $item) {
                if (($item['status'] ?? null) === 'Present') {
                    continue;
                }

                $key = $this->defectKey($compartmentId, $itemId, (string) ($item['status'] ?? ''));
                $requiredDefects[$key] = ($requiredDefects[$key] ?? 0) + 1;
            }
        }

        $submittedDefects = [];
        foreach ($submission['defects'] ?? [] as $defect) {
            $compartmentId = is_array($defect) ? $this->canonicalChecklistString($defect['compartment_id'] ?? null) : null;
            $itemId = is_array($defect) ? $this->canonicalChecklistString($defect['item_id'] ?? null) : null;
            if ($compartmentId === null || $itemId === null || ! isset($expected[$compartmentId])
                || ! isset($expected[$compartmentId]['items'][$itemId])
                || ! is_array($defect)
                || $this->canonicalChecklistString($defect['compartment'] ?? null) !== $expected[$compartmentId]['name']
                || $this->canonicalChecklistString($defect['item'] ?? null) !== $expected[$compartmentId]['items'][$itemId]) {
                throw ValidationException::withMessages([
                    'defects' => 'Every Missing or Damaged checklist item must have one matching defect record.',
                ]);
            }

            $key = $this->defectKey($compartmentId, $itemId, (string) ($defect['status'] ?? ''));
            $submittedDefects[$key] = ($submittedDefects[$key] ?? 0) + 1;
        }

        ksort($requiredDefects);
        ksort($submittedDefects);
        if ($submittedDefects !== $requiredDefects) {
            throw ValidationException::withMessages([
                'defects' => 'Every Missing or Damaged checklist item must have one matching defect record.',
            ]);
        }
    }

    private function defectKey(string $compartment, string $item, string $status): string
    {
        return implode("\0", [$compartment, $item, $status]);
    }

    private function canonicalChecklistString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
