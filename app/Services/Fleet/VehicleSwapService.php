<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\ApparatusVehicleSwapRequest;
use App\Models\DailyCheckoutInspectionSession;
use App\Models\Station;
use App\Models\User;
use App\Services\ApparatusInspectionProcessingService;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Display\DisplaySnapshotService;
use App\Services\InspectionMeterBaseline;
use App\Support\Fleet\FleetVehicleCatalog;
use App\Support\Fleet\VehicleNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    public const CHANGEOUT_NEW_TRUCK_STATUS = 'In Service';

    /** What makes a row the unit: its identity, station and frontline role. Never the truck's own attributes. */
    private const IDENTITY_FIELDS = ['unit_id', 'designation', 'name', 'slug', 'station_id', 'assignment', 'daily_checkout_requirement', 'daily_checkout_template'];

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
     * An apparatus row is the physical truck. A decision never moves a truck's
     * vehicle number, VIN, meters, defects, inspections or tickets; it moves the
     * unit designation (with its station and frontline role) between truck rows.
     *
     * @param  int|null  $mappedApparatusId  the existing truck record the member's vehicle is, when it was not on the fleet list
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
        ?int $mappedApparatusId = null,
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

        return DB::transaction(function () use ($requestId, $actor, $decision, $note, $demoteTo, $mappedApparatusId): ApparatusVehicleSwapRequest {
            $request = ApparatusVehicleSwapRequest::query()->lockForUpdate()->findOrFail($requestId);
            if (! $request->isPending()) {
                if ($request->status === $this->statusFor($decision)) {
                    return $request;
                }
                throw new VehicleSwapDecisionException('This report was already decided: '.ApparatusVehicleSwapRequest::statusLabel($request->status).'.');
            }

            $inspection = ApparatusInspection::query()->lockForUpdate()->findOrFail($request->apparatus_inspection_id);
            $truckId = $decision === self::DECISION_REJECT ? null : ($request->reported_apparatus_id ?? $mappedApparatusId);
            if ($decision !== self::DECISION_REJECT && $truckId === null) {
                throw new VehicleSwapDecisionException('Vehicle '.$request->reported_vehicle_number.' is not on the fleet list. Choose the existing truck record it is, or choose "No swap".');
            }
            if ($truckId !== null && $truckId === (int) $request->apparatus_id) {
                throw new VehicleSwapDecisionException('The reported truck is the one already assigned to this unit.');
            }

            // Lock both truck rows in id order so two decisions can never interleave.
            $ids = array_values(array_unique(array_filter([(int) $request->apparatus_id, $truckId])));
            sort($ids);
            $locked = Apparatus::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            /** @var Apparatus|null $assigned */
            $assigned = $locked->get((int) $request->apparatus_id);
            /** @var Apparatus|null $truck */
            $truck = $truckId === null ? null : $locked->get($truckId);
            if ($assigned === null || ($truckId !== null && $truck === null)) {
                throw new VehicleSwapDecisionException('A truck record in this report no longer exists.');
            }

            $before = ['assigned_truck' => $this->snapshot($assigned), 'reported_truck' => $truck ? $this->snapshot($truck) : null];
            $confirmed = $assigned;
            $baselineToken = $request->meter_baseline_token;
            if ($truck !== null) {
                $this->assertStillCurrent($request, $assigned, $truck);
                $this->moveUnitIdentity($assigned, $truck, $decision, $demoteTo);
                // The report's baseline described the assigned truck; judge the reading against the confirmed truck's own meters.
                $confirmed = $truck;
                $baselineToken = app(InspectionMeterBaseline::class)->issue($truck->refresh());
            }

            $assigned->refresh();
            $truck?->refresh();
            $audit = [
                'decision' => $decision,
                'decided_by' => ['id' => $actor->getKey(), 'name' => $actor->name],
                'decided_at' => now()->toIso8601String(),
                'before' => $before,
                'after' => ['assigned_truck' => $this->snapshot($assigned), 'reported_truck' => $truck ? $this->snapshot($truck) : null],
                'old_truck_demoted_to' => $demoteTo,
                'findings_applied_to_apparatus_id' => $confirmed->getKey(),
                'mapped_by_admin' => $request->reported_apparatus_id === null && $truck !== null,
            ];

            $this->applyHeldFindings($inspection, $confirmed, $request, $actor, $decision, $note, $audit, $baselineToken);

            $request->update([
                'status' => $this->statusFor($decision),
                'confirmed_apparatus_id' => $confirmed->getKey(),
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

    /** Refuse to approve against records that moved since the member reported. */
    private function assertStillCurrent(ApparatusVehicleSwapRequest $request, Apparatus $assigned, Apparatus $truck): void
    {
        if (($assigned->designation ?: $assigned->name) !== $request->unit_designation) {
            throw new VehicleSwapDecisionException('Unit '.$request->unit_designation.' moved to a different truck record after this report. Choose "No swap", or review the fleet records first.');
        }
        if ($request->reported_apparatus_id !== null && ! VehicleNumber::same($truck->vehicle_number, $request->reported_vehicle_number)) {
            throw new VehicleSwapDecisionException('The reported truck record no longer carries vehicle '.$request->reported_vehicle_number.'. Choose "No swap", or review the fleet records first.');
        }
    }

    /**
     * Move the unit designation, its station and its frontline role from the
     * assigned truck row to the confirmed truck row. vehicle_number, VIN,
     * meters, defects, inspections and tickets never move. Unique unit_id and
     * slug values are parked on placeholders so both updates happen in this one
     * locked transaction without a collision.
     */
    private function moveUnitIdentity(Apparatus $assigned, Apparatus $truck, string $decision, ?string $demoteTo): void
    {
        $identity = static fn (Apparatus $row): array => collect(self::IDENTITY_FIELDS)
            ->mapWithKeys(static fn (string $field): array => [$field => $row->getRawOriginal($field)])->all();
        $unitIdentity = $identity($assigned);
        $truckIdentity = $identity($truck);
        $assignedRole = FleetVehicleCatalog::roleFor($assigned->getAttribute('status'));
        $truckRole = FleetVehicleCatalog::roleFor($truck->getAttribute('status'));
        $assignedStatus = $assigned->getAttribute('status');
        $truckStatus = $truck->getAttribute('status');
        $swapRoles = in_array($assignedRole, [FleetVehicleCatalog::ROLE_FRONTLINE, FleetVehicleCatalog::ROLE_RESERVE], true)
            && in_array($truckRole, [FleetVehicleCatalog::ROLE_FRONTLINE, FleetVehicleCatalog::ROLE_RESERVE], true)
            && $assignedRole !== $truckRole;

        $assigned->forceFill(['unit_id' => 'swap-hold-'.$assigned->getKey(), 'slug' => 'swap-hold-'.$assigned->getKey()])->save();
        $truck->forceFill([...$unitIdentity, ...($swapRoles ? ['status' => $assignedStatus] : [])])->save();

        if ($decision === self::DECISION_APPROVE_SWAP) {
            $assigned->forceFill([...$truckIdentity, ...($swapRoles ? ['status' => $truckStatus] : [])])->save();

            return;
        }

        // Replace: the previously assigned truck loses the frontline designation and becomes a reserve or out-of-service truck.
        $assigned->forceFill([
            'unit_id' => 'reserve-'.$assigned->getKey(),
            // Never null: existing screens and filters label units by designation.
            'designation' => 'Reserve '.($assigned->vehicle_number ?: $assigned->getKey()),
            'slug' => 'reserve-'.$assigned->getKey(),
            'name' => 'Reserve (was '.($unitIdentity['designation'] ?: $unitIdentity['name'] ?: 'unit').')',
            'assignment' => null,
            'daily_checkout_requirement' => 'reserve',
            'status' => $demoteTo,
        ])->save();
    }

    /**
     * Move a unit to another truck for a changeout. The caller holds the transaction and has locked both rows in id order.
     * The new truck takes the unit's identity, and is In Service. The old truck takes the new truck's previous identity,
     * and is Out of Service. That is every swap, including a return, whatever either truck was before: the crew said so.
     * A truck holding another frontline unit trades identities, so that unit is now listed on the old truck, at its own
     * station.
     *
     * Both trucks are physically where the unit is, until Fleet moves the old one. A number-derived reserve identity
     * (R-1033, RESCUE 1033, r1033, reserve-<id>) is rewritten for the truck that now carries it, so a label never names
     * another truck. vehicle_number, VIN, meters, defects, inspections and tickets never move.
     *
     * @return array{before: array{from: array<string, mixed>, to: array<string, mixed>}, after: array{from: array<string, mixed>, to: array<string, mixed>}}
     */
    public function moveUnitForChangeout(Apparatus $from, Apparatus $to): array
    {
        $before = ['from' => $this->snapshot($from), 'to' => $this->snapshot($to)];
        $identity = static fn (Apparatus $row): array => collect(self::IDENTITY_FIELDS)
            ->mapWithKeys(static fn (string $field): array => [$field => $row->getRawOriginal($field)])->all();
        $unitIdentity = $identity($from);
        $vacated = $identity($to);
        $location = $this->locationOf($from);
        $traded = ! ApparatusChangeoutPlanner::isReserveTruck($to);

        // A traded frontline unit keeps its own name and its own station. Only a spare's number-derived label moves.
        if (! $traded) {
            foreach (['unit_id', 'designation', 'name', 'slug'] as $field) {
                $vacated[$field] = $this->rewrittenForTruck($vacated[$field], $field, $to, $from);
            }
        }
        foreach (['unit_id', 'slug'] as $field) {
            $vacated[$field] = $this->freeFor($field, $vacated[$field], $from, $to);
        }

        $from->forceFill(['unit_id' => 'swap-hold-'.$from->getKey(), 'slug' => 'swap-hold-'.$from->getKey()])->save();
        $to->forceFill([...$unitIdentity, 'status' => self::CHANGEOUT_NEW_TRUCK_STATUS, 'current_location' => $location])->save();
        $from->forceFill([
            ...$vacated,
            'status' => ApparatusChangeout::OLD_TRUCK_STATUS,
            'station_id' => $traded ? $vacated['station_id'] : $from->station_id,
            'current_location' => $location,
        ])->save();

        return ['before' => $before, 'after' => ['from' => $this->snapshot($from->refresh()), 'to' => $this->snapshot($to->refresh())]];
    }

    /** The station the unit is at, in the words the location field uses. A unit with no station keeps what it had. */
    private function locationOf(Apparatus $unit): mixed
    {
        $number = $unit->station_id === null ? null : Station::query()->whereKey($unit->station_id)->value('station_number');

        return $number === null ? $unit->getAttribute('current_location') : 'Station '.$number;
    }

    /** unit_id and slug are unique: a rewritten label can land on one another row still carries. */
    private function freeFor(string $column, mixed $value, Apparatus $from, Apparatus $to): mixed
    {
        $taken = is_string($value) && Apparatus::query()->where($column, $value)->whereKeyNot([$from->getKey(), $to->getKey()])->exists();

        return $taken ? $value.'-'.$from->getKey() : $value;
    }

    /**
     * A label that names the truck it was on names the truck that now carries it. Whole numbers only: 1033 is not
     * inside R-11033. A decide() reserve identity is named by row id, so it follows the row the same way.
     */
    private function rewrittenForTruck(mixed $value, string $field, Apparatus $previous, Apparatus $next): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        if (in_array($field, ['unit_id', 'slug'], true) && $value === 'reserve-'.$previous->getKey()) {
            return 'reserve-'.$next->getKey();
        }
        if (blank($previous->vehicle_number) || blank($next->vehicle_number)) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/(?<![0-9])'.preg_quote((string) $previous->vehicle_number, '/').'(?![0-9])/iu',
            static fn (): string => $field === 'slug' ? Str::slug((string) $next->vehicle_number) : (string) $next->vehicle_number,
            $value,
        );
    }

    /**
     * Release the held checkout into the normal processing path, against the
     * confirmed truck row, and record the decision on its review history.
     *
     * @param  array<string, mixed>  $audit
     */
    private function applyHeldFindings(
        ApparatusInspection $inspection,
        Apparatus $confirmed,
        ApparatusVehicleSwapRequest $request,
        User $actor,
        string $decision,
        ?string $note,
        array $audit,
        ?string $baselineToken,
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

        $this->processing->process($inspection, $confirmed, $baselineToken, $this->checklistFor($inspection, $confirmed));
    }

    /** @return array<string, mixed> */
    private function checklistFor(ApparatusInspection $inspection, Apparatus $confirmed): array
    {
        $session = DailyCheckoutInspectionSession::query()->where('submitted_inspection_id', $inspection->getKey())->first();
        if ($session !== null && is_array($session->checklist_snapshot)) {
            return $session->checklist_snapshot;
        }
        $resolution = $this->checklistResolver->resolve($confirmed);

        return $resolution['usable'] && is_array($resolution['checklist']) ? $resolution['checklist'] : [];
    }

    /** @return array<string, mixed> */
    private function snapshot(Apparatus $truck): array
    {
        return [
            'id' => $truck->getKey(),
            'designation' => $truck->designation,
            'unit_id' => $truck->getAttribute('unit_id'),
            'station_id' => $truck->station_id,
            'status' => $truck->getAttribute('status'),
            'vehicle_number' => $truck->vehicle_number,
            'vin' => $truck->getAttribute('vin'),
            'current_miles' => $truck->current_miles,
            'current_engine_hours' => $truck->current_engine_hours,
        ];
    }
}
