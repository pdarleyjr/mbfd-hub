<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DailyCheckoutRequirement;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\PublicApparatusResource;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\Employee;
use App\Models\User;
use App\Services\ApparatusInspectionApprovalService;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Display\DisplaySnapshotService;
use App\Support\Security\Base64Image;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
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
    public function index(): JsonResponse
    {
        $apparatuses = Apparatus::query()
            ->where('daily_checkout_requirement', DailyCheckoutRequirement::Required->value)
            ->get();

        return response()->json(PublicApparatusResource::collection($apparatuses)->resolve(request()));
    }

    public function checklist(Request $request, int $id, DailyCheckoutChecklistResolver $checklistResolver): JsonResponse
    {
        $inspectionDateInput = $request->validate([
            'inspection_date' => ['nullable', 'date_format:Y-m-d'],
        ])['inspection_date'] ?? null;
        $inspectionDate = CarbonImmutable::parse(
            $inspectionDateInput ?? CarbonImmutable::now(config('app.timezone'))->toDateString(),
            config('app.timezone'),
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
            'checklist_type' => $resolution['checklist_type'],
            'checklist_item_count' => $resolution['item_count'],
            'inspection_date' => $inspectionDate->toDateString(),
            'due_tasks' => $checklistResolver->dueTasksFor($checklist, $inspectionDate),
            // This unauthenticated route only needs a warning count. Never
            // serialize defect notes, photos, resolution history, or paths.
            'open_defects_count' => $apparatus->openDefects()->count(),
        ]);
    }

    public function storeInspection(
        Request $request,
        int $id,
        DailyCheckoutChecklistResolver $checklistResolver,
    ): JsonResponse {
        $validated = $request->validate([
            'client_submission_id' => ['required', 'uuid'],
            'checklist_version' => ['required', 'string', 'regex:/\\A[a-f0-9]{64}\\z/i'],
            'operator_name' => ['required', 'string', 'max:255'],
            'rank' => ['required', 'string', 'max:100'],
            'shift' => ['nullable', 'string', 'max:20'],
            'unit_number' => ['nullable', 'string', 'max:100'],
            'engine_hours' => ['nullable', 'numeric', 'min:0'],
            'miles' => ['nullable', 'integer', 'min:0'],
            'compartments' => ['required', 'array', 'min:1'],
            'compartments.*' => ['required', 'array'],
            'compartments.*.id' => ['required', 'string', 'max:255'],
            'compartments.*.name' => ['required', 'string', 'max:255'],
            'compartments.*.items' => ['required', 'array', 'min:1'],
            'compartments.*.items.*' => ['required', 'array'],
            'compartments.*.items.*.id' => ['nullable', 'string', 'max:255'],
            'compartments.*.items.*.name' => ['required', 'string', 'max:255'],
            'compartments.*.items.*.status' => ['required', 'string', 'in:Present,Missing,Damaged'],
            'compartments.*.items.*.notes' => ['nullable', 'string', 'max:2000'],
            'defects' => ['nullable', 'array', 'max:100'],
            'defects.*.compartment' => ['required', 'string', 'max:255'],
            'defects.*.item' => ['required', 'string', 'max:255'],
            'defects.*.status' => ['required', 'string', 'in:Missing,Damaged'],
            'defects.*.notes' => ['nullable', 'string', 'max:2000'],
            'defects.*.photo' => ['nullable', 'string', 'max:7000000'],
            'defects.*.compartment_id' => ['nullable', 'string', 'max:255'],
            'defects.*.item_id' => ['nullable', 'string', 'max:255'],
            'field_values' => ['nullable', 'array', 'max:100'],
            'field_values.*' => ['required', 'array'],
            'field_values.*.id' => ['required', 'string', 'max:255'],
            'field_values.*.value' => ['present'],
            'scheduled_tasks' => ['nullable', 'array', 'max:100'],
            'scheduled_tasks.*' => ['required', 'array'],
            'scheduled_tasks.*.id' => ['required', 'string', 'max:255'],
            'scheduled_tasks.*.status' => ['required', 'string', 'in:Present,Missing,Damaged'],
            'scheduled_tasks.*.notes' => ['nullable', 'string', 'max:2000'],
            'officer_signature' => ['nullable', 'string', 'max:7000000'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $clientSubmissionId = (string) $validated['client_submission_id'];
        $validated['checklist_version'] = strtolower((string) $validated['checklist_version']);
        $submissionPayloadHash = $this->submissionPayloadHash($validated);
        $existing = $this->findInspectionByClientSubmissionId($clientSubmissionId);
        if ($existing !== null) {
            return $this->idempotentInspectionResponse($existing, $id, $submissionPayloadHash);
        }

        $apparatus = Apparatus::findOrFail($id);
        if (! $apparatus->isDailyCheckoutRequired()) {
            return response()->json([
                'message' => 'This apparatus is not configured for Daily Checkout.',
            ], 409);
        }

        $resolution = $checklistResolver->resolve($apparatus);
        if (! $resolution['usable']) {
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

        if (! hash_equals((string) $resolution['checklist_version'], $validated['checklist_version'])) {
            return $this->checklistVersionMismatchResponse((string) $resolution['checklist_version']);
        }

        $this->validateCompleteChecklist($validated, $resolution['checklist'], $checklistResolver);

        $storedPaths = [];
        try {
            $prepared = $this->prepareImages($validated, $storedPaths);
            $result = DB::transaction(function () use ($id, $clientSubmissionId, $prepared, $checklistResolver, $submissionPayloadHash): array {
                $existing = $this->findInspectionByClientSubmissionId($clientSubmissionId, true);
                if ($existing !== null) {
                    return ['inspection' => $existing, 'created' => false];
                }

                $lockedApparatus = $this->lockApparatusForInspection($id);
                if (! $lockedApparatus->isDailyCheckoutRequired()) {
                    abort(409, 'This apparatus is not configured for Daily Checkout.');
                }

                $lockedResolution = $checklistResolver->resolve($lockedApparatus);
                if (! $lockedResolution['usable']) {
                    Log::warning('Daily Checkout checklist resolution failed after apparatus lock.', [
                        'apparatus_id' => $lockedApparatus->id,
                        'checklist_type' => $lockedResolution['checklist_type'],
                        'reason' => $lockedResolution['error'],
                    ]);

                    abort(503, 'The Daily Checkout checklist is unavailable. Contact an officer before continuing.');
                }

                if (! hash_equals((string) $lockedResolution['checklist_version'], (string) $prepared['checklist_version'])) {
                    return [
                        'inspection' => null,
                        'created' => false,
                        'checklist_version_mismatch' => true,
                        'checklist_version' => $lockedResolution['checklist_version'],
                    ];
                }
                $this->validateCompleteChecklist($prepared, $lockedResolution['checklist'], $checklistResolver);

                // The client may display a unit number, but the persisted identity must
                // always come from the apparatus selected by its unique route ID.
                $today = now()->format('Y-m-d');
                $designation = $lockedApparatus->designation ?? $lockedApparatus->name ?? 'UNK';
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
                if ($this->isV2Checklist($lockedResolution['checklist'])) {
                    $pendingEffects['checklist_v2'] = [
                        'field_values' => $prepared['field_values'],
                        'scheduled_tasks' => $prepared['scheduled_tasks'],
                    ];
                }

                $inspection = $this->createInspection([
                    'client_submission_id' => $clientSubmissionId,
                    'submission_payload_hash' => $submissionPayloadHash,
                    'checklist_version' => $lockedResolution['checklist_version'],
                    'apparatus_id' => $lockedApparatus->id,
                    'operator_name' => $prepared['operator_name'],
                    'rank' => $prepared['rank'],
                    'shift' => $prepared['shift'] ?? null,
                    'unit_number' => $lockedApparatus->vehicle_number,
                    'engine_hours' => $prepared['engine_hours'] ?? null,
                    'miles' => $prepared['miles'] ?? null,
                    'vehicle_number' => $lockedApparatus->vehicle_number,
                    'designation_at_time' => $lockedApparatus->designation,
                    'results' => $prepared['compartments'] ?? null,
                    'officer_signature' => $prepared['officer_signature_path'] ?? null,
                    'employee_id' => $prepared['employee_id'] ?? null,
                    // The public route has no authenticated device or user context.
                    // Retain its validated evidence, but never apply operational
                    // effects until an authorized reviewer approves it.
                    'pending_effects' => $pendingEffects,
                    'review_status' => 'pending_review',
                    'completed_at' => now(),
                ]);

                $inspectionRef = "INS-{$designationTag}-{$today}-".str_pad((string) $inspection->id, 6, '0', STR_PAD_LEFT);
                $inspection->update(['inspection_reference' => $inspectionRef]);

                DB::afterCommit(function (): void {
                    $this->forgetDisplayReadModels();
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

                return $this->idempotentInspectionResponse($inspection, $id, $submissionPayloadHash);
            }

            return response()->json($this->inspectionReceipt($inspection), 201);
        } catch (QueryException $exception) {
            $this->deleteStoredPaths($storedPaths);
            if (! $this->isClientSubmissionIdUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = $this->findInspectionByClientSubmissionId($clientSubmissionId);
            if ($existing !== null) {
                return $this->idempotentInspectionResponse($existing, $id, $submissionPayloadHash);
            }

            throw $exception;
        } catch (Throwable $exception) {
            $this->deleteStoredPaths($storedPaths);

            throw $exception;
        }
    }

    /**
     * Approve a pending-review inspection (authenticated/authorized only).
     *
     * SECURITY (H-01): this is the only path that may flip an apparatus to
     * "Out of Service" as a result of a reported critical defect. The public
     * submission endpoint records the inspection as 'pending_review'; an
     * authorized reviewer confirms it here, which applies the operational hold.
     */
    public function approveInspection(
        Request $request,
        ApparatusInspection $inspection,
        ApparatusInspectionApprovalService $approvalService,
    ): JsonResponse {
        $reviewer = $request->user();
        abort_unless($reviewer instanceof User && $reviewer->can('approve', $inspection), 403);

        $inspection = $approvalService->approve((int) $inspection->getKey(), $reviewer);

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
    ): JsonResponse {
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

        foreach ($data['defects'] ?? [] as $index => &$defect) {
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
    ): void {
        if ($this->isV2Checklist($checklist)) {
            $this->validateCompleteV2Checklist($submission, $checklist, $checklistResolver);

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

        $this->validateV2ScheduledTasks($submission, $checklist, $inspectionDate, $checklistResolver);
        $this->validateV2CompartmentMatrix($submission, $checklist);
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
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone'));
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
     * @param  array<string, mixed>  $checklist
     */
    private function validateV2ScheduledTasks(
        array $submission,
        array $checklist,
        CarbonImmutable $inspectionDate,
        DailyCheckoutChecklistResolver $checklistResolver,
    ): void {
        $expectedTasks = [];
        foreach ($checklistResolver->dueTasksFor($checklist, $inspectionDate) as $task) {
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
            if (! is_array($submittedCompartment)
                || $this->canonicalChecklistString($submittedCompartment['name'] ?? null) !== $expectedCompartment['name']
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
