<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\ApparatusVehicleSwapRequest;
use App\Models\DailyCheckoutInspectionSession;
use App\Models\User;
use App\Services\ApparatusInspectionProcessingService;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Display\DisplaySnapshotService;
use App\Support\Fleet\FleetVehicleCatalog;
use App\Support\Fleet\VehicleNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Holds a possible truck swap reported at Daily Checkout and applies a Fleet
 * decision to it.
 *
 * While a report is pending the checkout is saved under its unit, nothing about
 * any apparatus changes, and none of its findings (defects, observations,
 * meter readings) are applied. A decision changes only the vehicle IDs (and,
 * for "replace", the demoted unit's status) and then applies the held findings
 * to the unit exactly as automatic processing would have.
 */
final class VehicleSwapService
{
    public const HELD_PROCESSING_STATUS = 'pending_vehicle_swap';

    public const DECISION_APPROVE_SWAP = 'approve_swap';

    public const DECISION_APPROVE_REPLACE = 'approve_replace';

    public const DECISION_REJECT = 'reject';

    public const DECISIONS = [self::DECISION_APPROVE_SWAP, self::DECISION_APPROVE_REPLACE, self::DECISION_REJECT];

    public const DEMOTE_RESERVE = 'Reserve';

    public const DEMOTE_OUT_OF_SERVICE = 'Out of Service';

    public function __construct(
        private readonly FleetVehicleCatalog $catalog,
        private readonly ApparatusInspectionProcessingService $processing,
        private readonly DailyCheckoutChecklistResolver $checklistResolver,
    ) {}

    /**
     * Compare the reported vehicle with the unit's assigned one. Null means the
     * member confirmed the assigned vehicle (or sent no report), so the checkout
     * is processed automatically as before.
     *
     * @return array{vehicle_number: string, listed: bool, reported_apparatus_id: int|null, answer: string, note: string|null}|null
     */
    public function assess(Apparatus $unit, mixed $report): ?array
    {
        if (! is_array($report)) {
            return null;
        }
        $reported = VehicleNumber::normalize($report['vehicle_number'] ?? null);
        if (! VehicleNumber::isWellFormed($reported)) {
            throw ValidationException::withMessages(['physical_vehicle.vehicle_number' => 'Enter the vehicle ID shown on the truck.']);
        }
        if (VehicleNumber::same($reported, $unit->vehicle_number)) {
            return null;
        }
        $answer = $report['swap_answer'] ?? null;
        $note = is_string($report['note'] ?? null) ? trim($report['note']) : '';
        if (! is_string($answer) || ! in_array($answer, ApparatusVehicleSwapRequest::ANSWERS, true)) {
            // The member confirmed the vehicle assigned when the checkout was started, and the
            // assignment changed before a queued (offline) checkout synced. Hold it for Fleet
            // rather than dropping a legitimate checkout.
            $answer = ApparatusVehicleSwapRequest::ANSWER_UNKNOWN;
            $note = trim($note.' [No answer given: the unit was reassigned after the member confirmed its vehicle.]');
        }
        /** @var Apparatus|null $holder */
        $holder = $this->catalog->otherHolders((string) $reported, (int) $unit->getKey())->first();

        return [
            'vehicle_number' => VehicleNumber::normalize($holder?->vehicle_number) ?? (string) $reported,
            'listed' => $holder !== null,
            'reported_apparatus_id' => $holder?->getKey(),
            'answer' => $answer,
            'note' => $note === '' ? null : mb_substr($note, 0, 1000),
        ];
    }

    /**
     * Record the report beside the saved checkout. Called inside the submission
     * transaction; the notification goes out only after it commits.
     *
     * @param  array{vehicle_number: string, listed: bool, reported_apparatus_id: int|null, answer: string, note: string|null}  $assessment
     */
    public function hold(
        ApparatusInspection $inspection,
        Apparatus $unit,
        array $assessment,
        string $reporterName,
        ?int $reporterUserId,
        ?string $baselineToken,
    ): ApparatusVehicleSwapRequest {
        $request = ApparatusVehicleSwapRequest::query()->create([
            'apparatus_inspection_id' => $inspection->getKey(),
            'apparatus_id' => $unit->getKey(),
            'reported_apparatus_id' => $assessment['reported_apparatus_id'],
            'reporter_user_id' => $reporterUserId,
            'reporter_name' => $reporterName,
            'unit_designation' => $unit->designation ?: $unit->name,
            'assigned_vehicle_number' => VehicleNumber::normalize($unit->vehicle_number),
            'reported_vehicle_number' => $assessment['vehicle_number'],
            'reported_vehicle_listed' => $assessment['listed'],
            'answer' => $assessment['answer'],
            'note' => $assessment['note'],
            'meter_baseline_token' => $baselineToken,
            'status' => ApparatusVehicleSwapRequest::STATUS_PENDING,
        ]);
        $requestId = (int) $request->getKey();
        DB::afterCommit(static function () use ($requestId): void {
            // The checkout is already saved; telling Fleet is best effort and never fails the response.
            try {
                app(VehicleSwapNotifier::class)->reported($requestId);
            } catch (Throwable $exception) {
                Log::error('Vehicle swap notification failed', ['vehicle_swap_request_id' => $requestId, 'exception' => $exception->getMessage()]);
            }
        });

        return $request;
    }

    /**
     * Other units that carry the reported vehicle right now, for the admin to weigh.
     *
     * @return Collection<int, Apparatus>
     */
    public function otherUnitsHolding(ApparatusVehicleSwapRequest $request): Collection
    {
        return $this->catalog->otherHolders($request->reported_vehicle_number, (int) $request->apparatus_id);
    }

    /**
     * Apply one Fleet decision. Idempotent: repeating the same decision returns
     * the recorded outcome; a different decision on a decided report is refused.
     *
     * @throws AuthorizationException when the actor lacks Fleet manage access
     * @throws VehicleSwapDecisionException when the report cannot take this decision
     */
    public function decide(
        int $requestId,
        User $actor,
        string $decision,
        ?string $note = null,
        ?string $demoteTo = null,
    ): ApparatusVehicleSwapRequest {
        if (! $actor->can('decide', ApparatusVehicleSwapRequest::class)) {
            throw new AuthorizationException('Fleet manage access is required to decide a vehicle swap.');
        }
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new InvalidArgumentException('Unknown vehicle swap decision.');
        }
        $note = $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 1000);
        if ($decision === self::DECISION_APPROVE_REPLACE) {
            $demoteTo ??= self::DEMOTE_RESERVE;
            if (! in_array($demoteTo, [self::DEMOTE_RESERVE, self::DEMOTE_OUT_OF_SERVICE], true)) {
                throw new InvalidArgumentException('The old vehicle can only be marked Reserve or Out of Service.');
            }
        } else {
            $demoteTo = null;
        }

        return DB::transaction(function () use ($requestId, $actor, $decision, $note, $demoteTo): ApparatusVehicleSwapRequest {
            $request = ApparatusVehicleSwapRequest::query()->lockForUpdate()->findOrFail($requestId);
            if (! $request->isPending()) {
                if ($request->status === $this->statusFor($decision)) {
                    return $request;
                }
                throw new VehicleSwapDecisionException('This report was already decided: '.ApparatusVehicleSwapRequest::statusLabel($request->status).'.');
            }

            $inspection = ApparatusInspection::query()->lockForUpdate()->findOrFail($request->apparatus_inspection_id);
            [$unit, $holders] = $this->lockUnits($request);
            $holder = $holders->first();
            $before = ['unit' => $this->snapshot($unit), 'other_unit' => $holder ? $this->snapshot($holder) : null];

            $retiredVehicle = null;
            if ($decision !== self::DECISION_REJECT) {
                $retiredVehicle = $this->applyApproval($request, $unit, $holders, $decision, $demoteTo);
            }

            $unit->refresh();
            $holder?->refresh();
            $after = ['unit' => $this->snapshot($unit), 'other_unit' => $holder ? $this->snapshot($holder) : null];
            $audit = [
                'decision' => $decision,
                'decided_by' => ['id' => $actor->getKey(), 'name' => $actor->name],
                'decided_at' => now()->toIso8601String(),
                'before' => $before,
                'after' => $after,
                'old_vehicle_without_unit' => $retiredVehicle,
                'old_vehicle_demoted_to' => $demoteTo,
            ];

            $this->applyHeldFindings($inspection, $unit, $request, $actor, $decision, $note, $audit);

            $request->update([
                'status' => $this->statusFor($decision),
                'decided_by_user_id' => $actor->getKey(),
                'decided_at' => now(),
                'decision_note' => $note,
                'decision_audit' => $audit,
            ]);

            DB::afterCommit(static function (): void {
                Cache::forget(DisplaySnapshotService::SNAPSHOT_CACHE_KEY);
                Cache::forget(DisplaySnapshotService::STATIONS_CACHE_KEY);
            });

            return $request;
        }, 3);
    }

    public function statusFor(string $decision): string
    {
        return match ($decision) {
            self::DECISION_APPROVE_SWAP => ApparatusVehicleSwapRequest::STATUS_APPROVED_SWAP,
            self::DECISION_APPROVE_REPLACE => ApparatusVehicleSwapRequest::STATUS_APPROVED_REPLACE,
            default => ApparatusVehicleSwapRequest::STATUS_REJECTED,
        };
    }

    /**
     * Lock the checked-out unit and every unit carrying the reported vehicle in
     * id order, so two decisions can never deadlock or interleave.
     *
     * @return array{0: Apparatus, 1: Collection<int, Apparatus>}
     */
    private function lockUnits(ApparatusVehicleSwapRequest $request): array
    {
        $ids = $this->catalog->otherHolders($request->reported_vehicle_number, (int) $request->apparatus_id)
            ->pluck('id')->push($request->apparatus_id)->unique()->sort()->values()->all();
        $locked = Apparatus::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

        /** @var Apparatus $unit */
        $unit = $locked->firstWhere('id', $request->apparatus_id);
        $holders = $locked
            ->filter(fn (Apparatus $row): bool => $row->getKey() !== $unit->getKey() && VehicleNumber::same($row->vehicle_number, $request->reported_vehicle_number))
            ->values();

        return [$unit, $holders];
    }

    /**
     * Change vehicle IDs only. Returns the old vehicle ID when no unit is left to carry it.
     *
     * @param  Collection<int, Apparatus>  $holders
     */
    private function applyApproval(
        ApparatusVehicleSwapRequest $request,
        Apparatus $unit,
        Collection $holders,
        string $decision,
        ?string $demoteTo,
    ): ?string {
        if (! VehicleNumber::same($unit->vehicle_number, $request->assigned_vehicle_number)) {
            throw new VehicleSwapDecisionException('The vehicle assigned to '.($unit->designation ?: 'this unit').' changed after this report. Reject it, or review the unit in Fleet first.');
        }
        if ($holders->count() > 1) {
            throw new VehicleSwapDecisionException('More than one unit carries vehicle '.$request->reported_vehicle_number.'. Correct the fleet records before approving.');
        }
        $holder = $holders->first();
        if ($decision === self::DECISION_APPROVE_SWAP && $holder === null) {
            throw new VehicleSwapDecisionException('Vehicle '.$request->reported_vehicle_number.' is not on any unit, so there is nothing to trade. Use the option where the new vehicle takes the unit.');
        }

        $oldVehicle = VehicleNumber::normalize($unit->vehicle_number);
        $newVehicle = VehicleNumber::normalize($holder?->vehicle_number) ?? $request->reported_vehicle_number;

        $unit->update(['vehicle_number' => $newVehicle]);
        if ($holder === null) {
            return $oldVehicle;
        }
        $holder->vehicle_number = $oldVehicle;
        if ($decision === self::DECISION_APPROVE_REPLACE && $demoteTo !== null && $holder->getAttribute('status') !== $demoteTo) {
            $holder->setAttribute('status', $demoteTo);
        }
        $holder->save();

        return null;
    }

    /**
     * Release the held checkout into the normal processing path, against the
     * unit it was saved under, and record the decision on its review history.
     *
     * @param  array<string, mixed>  $audit
     */
    private function applyHeldFindings(
        ApparatusInspection $inspection,
        Apparatus $unit,
        ApparatusVehicleSwapRequest $request,
        User $actor,
        string $decision,
        ?string $note,
        array $audit,
    ): void {
        $inspection->update(['processing_status' => null]);

        ApparatusInspectionReviewEvent::query()->create([
            'apparatus_inspection_id' => $inspection->getKey(),
            'previous_status' => (string) $inspection->review_status,
            'status' => match ($decision) {
                self::DECISION_APPROVE_SWAP => 'swap_approved',
                self::DECISION_APPROVE_REPLACE => 'swap_replaced',
                default => 'swap_rejected',
            },
            'internal_note' => $note,
            'changed_by_user_id' => $actor->getKey(),
            'metadata' => [
                'vehicle_swap_request_id' => $request->getKey(),
                'reported_vehicle_number' => $request->reported_vehicle_number,
                'audit' => $audit,
            ],
        ]);

        $this->processing->process($inspection, $unit, $request->meter_baseline_token, $this->checklistFor($inspection, $unit));
    }

    /** @return array<string, mixed> */
    private function checklistFor(ApparatusInspection $inspection, Apparatus $unit): array
    {
        $session = DailyCheckoutInspectionSession::query()->where('submitted_inspection_id', $inspection->getKey())->first();
        if ($session !== null && is_array($session->checklist_snapshot)) {
            return $session->checklist_snapshot;
        }
        $resolution = $this->checklistResolver->resolve($unit);

        return $resolution['usable'] && is_array($resolution['checklist']) ? $resolution['checklist'] : [];
    }

    /** @return array{id: mixed, designation: string|null, vehicle_number: string|null, status: string|null} */
    private function snapshot(Apparatus $unit): array
    {
        return [
            'id' => $unit->getKey(),
            'designation' => $unit->designation ?: $unit->name,
            'vehicle_number' => $unit->vehicle_number,
            'status' => $unit->getAttribute('status'),
        ];
    }
}
