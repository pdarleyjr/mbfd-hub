<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusChangeoutItem;
use App\Models\ApparatusFleetEvent;
use App\Models\ApparatusInventoryChange;
use App\Models\ApparatusOperationalAlert;
use App\Models\ApparatusServiceTicket;
use App\Models\Employee;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Defects\DefectContinuityService;
use App\Services\Display\DisplaySnapshotService;
use App\Services\Inventory\ChecklistRevisionStore;
use App\Services\Security\Captain5Access;
use App\Support\Fleet\FleetVehicleCatalog;
use App\Support\Fleet\VehicleNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Moves a unit from one physical truck to another and records what happened to its equipment
 * (docs/apparatus-changeouts.md). One writer: nothing else changes these tables or moves an identity for a changeout.
 *
 * Lock order: the alert, then the changeout (decisions and verification only), then both trucks in ascending id.
 * A commit never closes a ticket or a defect: a swap does not fix a truck.
 */
final class ApparatusChangeoutService
{
    public const DECISION_APPROVE = 'approve';

    public const DECISION_REJECT = 'reject';

    private const ATTEMPTS = 3;

    private const STATUS_MAINTENANCE = 'Maintenance';

    public function __construct(
        private readonly ApparatusChangeoutPlanner $planner,
        private readonly VehicleSwapService $swaps,
        private readonly DefectContinuityService $defects,
        private readonly FleetVehicleCatalog $catalog,
        private readonly DailyCheckoutChecklistResolver $checklists,
        private readonly ChecklistRevisionStore $revisions,
        private readonly ChangeoutLineBuilder $lineBuilder,
        private readonly ApparatusChangeoutPresenter $presenter,
    ) {}

    /** @return array<string, array<int, mixed>> the submission contract, checked at the API boundary and again here */
    public static function rules(): array
    {
        return [
            'client_submission_id' => ['required', 'uuid'],
            'expected_vehicle_number' => ['present', 'nullable', 'string', 'max:64'],
            'expected_unit_slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'alert_id' => ['sometimes', 'nullable', 'integer'],
            'to_apparatus_id' => ['sometimes', 'nullable', 'integer'],
            'to_vehicle_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'checklist_version' => ['required_with:exceptions', 'nullable', 'string', 'max:64'],
            'exceptions' => ['sometimes', 'nullable', 'array', 'max:1000'],
            'exceptions.*' => ['array'],
            'exceptions.*.item_key' => ['required', 'string', 'max:255'],
            'exceptions.*.handling' => ['sometimes', 'nullable', Rule::in(ApparatusChangeoutItem::HANDLINGS)],
            'exceptions.*.quantity' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'exceptions.*.condition' => ['sometimes', 'nullable', Rule::in(ApparatusChangeoutItem::CONDITIONS)],
            'exceptions.*.note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{changeout: ApparatusChangeout, created: bool, replayed: bool}
     *
     * @throws ChangeoutConflictException
     * @throws AuthorizationException
     */
    public function submit(Apparatus $unit, User $actor, array $payload): array
    {
        $this->authorizeSubmitter($actor);
        $input = $this->normalized($unit, $actor, $payload);

        $replay = $this->replayOf($input);
        if ($replay !== null) {
            return $replay;
        }

        try {
            return DB::transaction(fn (): array => $this->record((int) $unit->getKey(), $actor, $input), self::ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            // Two sends raced past the checks above. The database let one through: answer as if this one came second.
            $replay = $this->replayOf($input);
            if ($replay !== null) {
                return $replay;
            }
            $pending = $this->planner->pendingFor((string) $unit->fresh()?->slug);
            if ($pending !== null) {
                throw $this->alreadyPending($pending, $actor);
            }
            throw $exception;
        }
    }

    /**
     * Fleet's answer on a waiting changeout. Approve re-checks the records and runs the same commit as a direct
     * changeout, with the crew's saved equipment lines. $mappedApparatusId says which truck an unlisted number is.
     *
     * @throws AuthorizationException
     * @throws ChangeoutConflictException
     */
    public function decide(int $changeoutId, User $actor, string $decision, ?string $note = null, ?int $mappedApparatusId = null, ?string $oldTruckStatus = null): ApparatusChangeout
    {
        if (! $actor->canManageFleetSwaps()) {
            throw new AuthorizationException('Fleet manage access is required to decide a vehicle change.');
        }
        Validator::make(
            ['decision' => $decision, 'old_truck_status' => $oldTruckStatus],
            [
                'decision' => ['required', Rule::in([self::DECISION_APPROVE, self::DECISION_REJECT])],
                'old_truck_status' => ['nullable', Rule::in(ApparatusOperationalAlert::OLD_TRUCK_STATUSES)],
            ],
        )->validate();
        $note = self::clean($note, 1000);
        $alertId = ApparatusChangeout::query()->whereKey($changeoutId)->value('alert_id');

        return DB::transaction(function () use ($changeoutId, $alertId, $actor, $decision, $note, $mappedApparatusId, $oldTruckStatus): ApparatusChangeout {
            $alert = $alertId === null ? null : ApparatusOperationalAlert::query()->lockForUpdate()->find($alertId);
            $changeout = ApparatusChangeout::query()->lockForUpdate()->findOrFail($changeoutId);

            if (! $changeout->isPendingFleet()) {
                $same = $changeout->status === ($decision === self::DECISION_APPROVE ? ApparatusChangeout::STATUS_COMPLETED : ApparatusChangeout::STATUS_REJECTED);
                if ($same) {
                    return $changeout;
                }
                throw new ChangeoutConflictException(ChangeoutConflictException::ALREADY_DECIDED, 'This vehicle change was already answered: '.ApparatusChangeout::statusLabel($changeout->status).'. Nothing else changed.', ['changeout_id' => (int) $changeout->getKey()]);
            }

            $decided = ['decided_by_user_id' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => $note];
            if ($decision === self::DECISION_REJECT) {
                $changeout->update([...$decided, 'status' => ApparatusChangeout::STATUS_REJECTED]);
                ApparatusFleetEvent::record('decided_rejected', $alert, $changeout, $actor, array_filter(['note' => $note]));

                return $changeout;
            }

            $this->approve($changeout, $alert, $actor, $decided, $mappedApparatusId, $oldTruckStatus);

            return $changeout;
        }, self::ATTEMPTS)->refresh();
    }

    /**
     * The submitter or Fleet withdraws a waiting changeout. The record stays; no apparatus ever changed.
     *
     * @throws AuthorizationException
     * @throws ChangeoutConflictException
     */
    public function cancel(int $changeoutId, User $actor, ?string $note = null): ApparatusChangeout
    {
        $note = self::clean($note, 1000);

        return DB::transaction(function () use ($changeoutId, $actor, $note): ApparatusChangeout {
            $changeout = ApparatusChangeout::query()->lockForUpdate()->findOrFail($changeoutId);
            if (! $actor->canManageFleetSwaps() && (int) $changeout->submitted_by_user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('Only the person who sent this, or Fleet, can cancel it.');
            }
            if ($changeout->status === ApparatusChangeout::STATUS_CANCELLED) {
                return $changeout;
            }
            if (! $changeout->isPendingFleet()) {
                throw new ChangeoutConflictException(ChangeoutConflictException::ALREADY_DECIDED, 'This vehicle change was already answered: '.ApparatusChangeout::statusLabel($changeout->status).'. It can no longer be cancelled.', ['changeout_id' => (int) $changeout->getKey()]);
            }
            $changeout->update(['status' => ApparatusChangeout::STATUS_CANCELLED, 'decided_by_user_id' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => $note]);
            ApparatusFleetEvent::record('cancelled', null, $changeout, $actor, array_filter(['note' => $note]));

            return $changeout;
        }, self::ATTEMPTS)->refresh();
    }

    /**
     * Records the separate accountability step on one open line. Settling the last open line completes the handoff
     * and, when it belongs to one, the alert. A needs_review line is Fleet's to settle, and takes a note.
     *
     * @throws AuthorizationException
     * @throws ChangeoutConflictException
     */
    public function verifyLine(int $changeoutId, int $lineId, User $actor, ?string $note = null): ApparatusChangeout
    {
        $note = self::clean($note, 500);
        $alertId = ApparatusChangeout::query()->whereKey($changeoutId)->value('alert_id');

        return DB::transaction(function () use ($changeoutId, $lineId, $alertId, $actor, $note): ApparatusChangeout {
            $alert = $alertId === null ? null : ApparatusOperationalAlert::query()->lockForUpdate()->find($alertId);
            $changeout = ApparatusChangeout::query()->lockForUpdate()->findOrFail($changeoutId);
            if (! $this->canVerify($actor, $changeout)) {
                throw new AuthorizationException('You do not have authority to verify this item.');
            }
            /** @var ApparatusChangeoutItem $line */
            $line = $changeout->items()->lockForUpdate()->findOrFail($lineId);
            if ($line->custody_status === ApparatusChangeoutItem::CUSTODY_VERIFIED) {
                return $changeout;
            }
            if (! $changeout->isCompleted()) {
                throw new ChangeoutConflictException(ChangeoutConflictException::NOT_COMPLETED, 'This vehicle change has not happened yet, so its items cannot be verified. Nothing was saved. Try again after Fleet decides.', ['changeout_id' => (int) $changeout->getKey()]);
            }
            if (! $line->isOpen()) {
                throw ValidationException::withMessages(['line' => 'This item is already settled and needs nothing else.']);
            }
            if ($line->custody_status === ApparatusChangeoutItem::CUSTODY_NEEDS_REVIEW) {
                if (! $actor->canManageFleetSwaps()) {
                    throw new AuthorizationException('Only Fleet can settle an item marked for review.');
                }
                if ($note === null) {
                    throw ValidationException::withMessages(['note' => 'Say what you found, so the record shows how this was settled.']);
                }
            }

            $line->update([
                'custody_status' => ApparatusChangeoutItem::CUSTODY_VERIFIED,
                'verified_by_user_id' => $actor->getKey(),
                'verified_at' => now(),
                'verification_note' => $note,
            ]);
            ApparatusFleetEvent::record('line_verified', $alert, $changeout, $actor, array_filter(['line_id' => (int) $line->getKey(), 'item_key' => $line->item_key, 'note' => $note]));

            if (! $changeout->items()->whereIn('custody_status', ApparatusChangeoutItem::OPEN_CUSTODY)->exists()) {
                ApparatusFleetEvent::record('reconciled', $alert, $changeout, $actor);
            }
            $this->settleHandoff($changeout, $alert, $actor);

            return $changeout;
        }, self::ATTEMPTS)->refresh();
    }

    public function canVerify(User $user, ApparatusChangeout $changeout): bool
    {
        return $user->canManageFleetSwaps()
            || ($this->isRescueEquipment($changeout) && app(Captain5Access::class)->canAct($user));
    }

    /**
     * Section 6 shape, plus next_daily_checkout_path. Controlled lines are the name only unless the viewer can verify
     * them; the default viewer is a member.
     *
     * @return array<string, mixed>
     */
    public function summary(ApparatusChangeout $changeout, ?User $viewer = null, bool $withLines = false): array
    {
        return $this->presenter->summary($changeout, $viewer !== null && $this->canVerify($viewer, $changeout), $viewer?->canManageFleetSwaps() ?? false, $withLines);
    }

    private function authorizeSubmitter(User $actor): void
    {
        if ($actor->canManageFleetSwaps()) {
            return;
        }
        if (! $actor->isAuthenticationAllowed() || ! $actor->employeeProfile instanceof Employee) {
            throw new AuthorizationException('An active member profile is required to record a vehicle change.');
        }
    }

    /**
     * Canonical input, and the hash a replay is compared against. Includes the truck and the actor: the same id sent
     * by someone else, or for another truck, is not a replay.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalized(Apparatus $unit, User $actor, array $payload): array
    {
        $valid = Validator::make($payload, self::rules())->validate();

        $exceptions = [];
        foreach (array_values($valid['exceptions'] ?? []) as $index => $exception) {
            $key = (string) $exception['item_key'];
            if (isset($exceptions[$key])) {
                throw ValidationException::withMessages(["exceptions.{$index}.item_key" => 'This item was listed twice. Send one choice for each item.']);
            }
            $exceptions[$key] = [
                'handling' => $exception['handling'] ?? null,
                'quantity' => isset($exception['quantity']) ? (int) $exception['quantity'] : null,
                'condition' => $exception['condition'] ?? ApparatusChangeoutItem::CONDITION_OK,
                'note' => self::clean($exception['note'] ?? null, 500),
            ];
        }
        ksort($exceptions, SORT_STRING);

        $input = [
            'apparatus_id' => (int) $unit->getKey(),
            'actor_id' => (int) $actor->getKey(),
            'expected_vehicle_number' => VehicleNumber::normalize($valid['expected_vehicle_number'] ?? null),
            'expected_unit_slug' => self::clean($valid['expected_unit_slug'] ?? null, 255),
            'alert_id' => isset($valid['alert_id']) ? (int) $valid['alert_id'] : null,
            'to_apparatus_id' => isset($valid['to_apparatus_id']) ? (int) $valid['to_apparatus_id'] : null,
            'to_vehicle_number' => VehicleNumber::normalize($valid['to_vehicle_number'] ?? null),
            'checklist_version' => self::clean($valid['checklist_version'] ?? null, 64),
            'exceptions' => $exceptions,
            'note' => self::clean($valid['note'] ?? null, 1000),
        ];

        return [
            ...$input,
            'client_submission_id' => strtolower((string) $valid['client_submission_id']),
            'payload_hash' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{changeout: ApparatusChangeout, created: bool, replayed: bool}|null
     */
    private function replayOf(array $input): ?array
    {
        $existing = ApparatusChangeout::query()->where('client_submission_id', $input['client_submission_id'])->first();
        if ($existing === null) {
            return null;
        }
        if (! hash_equals($existing->payload_hash, $input['payload_hash'])) {
            throw new ChangeoutConflictException(ChangeoutConflictException::REPLAY_CONFLICT, 'This was already sent with different choices. Nothing new was saved. Reload the page, review it and send it again.', ['changeout_id' => (int) $existing->getKey()]);
        }

        return ['changeout' => $existing, 'created' => false, 'replayed' => true];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{changeout: ApparatusChangeout, created: bool, replayed: bool}
     */
    private function record(int $routeTruckId, User $actor, array $input): array
    {
        $routeTruck = Apparatus::query()->findOrFail($routeTruckId);
        $alert = $this->lockedAlert($input['alert_id'], $routeTruck);
        $holder = $this->planner->resolveUnit($routeTruck, $alert);
        $toId = $this->replacementId($input, $holder);
        $handoffOnly = $alert !== null && $this->planner->isHandoffOnly($alert, $holder)
            && ($toId === null || $toId === (int) $holder->getKey()) && $this->namesNoOtherNumber($input, $holder);

        $locked = $this->lockTrucks([(int) $holder->getKey(), $handoffOnly ? (int) $alert->apparatus_id : $toId]);
        // The first send may have committed while this one waited on the locks.
        $replay = $this->replayOf($input);
        if ($replay !== null) {
            return $replay;
        }
        /** @var Apparatus $unit */
        $unit = $locked->get((int) $holder->getKey());
        $to = $handoffOnly || $toId === null ? null : $this->requiredTruck($locked, $toId);

        $this->assertStillOnExpectedTruck($unit, $input);
        $pending = $this->planner->pendingFor((string) $unit->slug);
        if ($pending !== null) {
            throw $this->alreadyPending($pending, $actor);
        }
        if ($alert !== null) {
            $this->planner->assertAlertFor($alert, $unit);
            $this->assertAlertNotRecorded($alert);
            if (! $handoffOnly && ! $this->planner->alertFits($alert, $unit, $to)) {
                if ($input['alert_id'] !== null) {
                    throw ValidationException::withMessages(['alert_id' => 'That notice is for a different truck, so it cannot go with this change. Nothing was saved. Send this without the notice and Fleet will confirm it.']);
                }
                $alert = null;
            }
        }

        $changeout = $handoffOnly
            ? $this->recordHandoffOnly($unit, $this->requiredTruck($locked, (int) $alert->apparatus_id), $alert, $actor, $input)
            : $this->recordMove($unit, $to, $alert, $actor, $input);

        return ['changeout' => $changeout->refresh(), 'created' => true, 'replayed' => false];
    }

    /** The alert the member named, or the unit's own scheduled change when they named none. */
    private function lockedAlert(?int $alertId, Apparatus $routeTruck): ?ApparatusOperationalAlert
    {
        $id = $alertId ?? $this->planner->openSwapAlert((string) $routeTruck->slug)?->getKey();
        if ($id === null) {
            return null;
        }
        $alert = ApparatusOperationalAlert::query()->lockForUpdate()->find($id);
        if ($alert === null && $alertId !== null) {
            throw ValidationException::withMessages(['alert_id' => 'That notice no longer exists.']);
        }

        return $alert;
    }

    /**
     * The truck the member named. An unlisted number has no truck, and a number two records share is left for Fleet.
     *
     * @param  array<string, mixed>  $input
     */
    private function replacementId(array $input, Apparatus $unit): ?int
    {
        if ($input['to_apparatus_id'] !== null) {
            $truck = Apparatus::query()->find($input['to_apparatus_id']);
            if ($truck === null) {
                throw ValidationException::withMessages(['to_apparatus_id' => 'That truck is not on the fleet list. Pick one from the list, or type its number.']);
            }
            if ($input['to_vehicle_number'] !== null && ! VehicleNumber::same($input['to_vehicle_number'], $truck->vehicle_number)) {
                throw ValidationException::withMessages(['to_vehicle_number' => 'The truck you picked and the number you typed do not match.']);
            }

            return (int) $truck->getKey();
        }
        if ($input['to_vehicle_number'] === null) {
            return null;
        }
        $ids = $this->catalog->holders($input['to_vehicle_number'])->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /** @param array<string, mixed> $input */
    private function namesNoOtherNumber(array $input, Apparatus $unit): bool
    {
        return $input['to_vehicle_number'] === null || VehicleNumber::same($input['to_vehicle_number'], $unit->vehicle_number);
    }

    /**
     * Both trucks, in ascending id: the order VehicleSwapService uses, so two moves can never interleave.
     *
     * @param  array<int, int|null>  $ids
     * @return Collection<int, Apparatus>
     */
    private function lockTrucks(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);

        return Apparatus::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /** @param Collection<int, Apparatus> $locked */
    private function requiredTruck(Collection $locked, int $id): Apparatus
    {
        $truck = $locked->get($id);
        if (! $truck instanceof Apparatus) {
            throw ValidationException::withMessages(['to_apparatus_id' => 'That truck is no longer on the fleet list.']);
        }

        return $truck;
    }

    /** @param array<string, mixed> $input */
    private function assertStillOnExpectedTruck(Apparatus $unit, array $input): void
    {
        $slug = $input['expected_unit_slug'];
        $sameUnit = $slug === null || $slug === $unit->slug;
        $expected = $input['expected_vehicle_number'];
        $current = VehicleNumber::normalize($unit->vehicle_number);
        $sameTruck = $expected === null ? $current === null : VehicleNumber::same($expected, $current);
        if ($sameUnit && $sameTruck) {
            return;
        }

        $truck = $sameUnit ? $unit : ($this->planner->holderOf($slug) ?? $unit);
        $label = ApparatusChangeoutPlanner::unitLabel($truck);
        $where = filled($truck->vehicle_number) ? " It is on truck {$truck->vehicle_number} now." : '';
        throw new ChangeoutConflictException(
            ChangeoutConflictException::STALE,
            "{$label} is no longer on truck ".($expected ?? 'that you started from').".{$where} Nothing was saved, and your choices are still on this screen. Review the truck, then send it again.",
            ['current_vehicle_number' => $truck->vehicle_number, 'current_apparatus_id' => (int) $truck->getKey(), 'unit_designation' => $label],
        );
    }

    private function assertAlertNotRecorded(ApparatusOperationalAlert $alert): void
    {
        $recorded = $this->planner->recordedChangeoutId($alert);
        if ($recorded !== null) {
            throw new ChangeoutConflictException(ChangeoutConflictException::ALREADY_RECORDED, 'The handoff for this notice is already recorded. Nothing new was saved. Open that record to see what is left.', ['changeout_id' => $recorded]);
        }
    }

    /** @param array<string, mixed> $input */
    private function recordMove(Apparatus $unit, ?Apparatus $to, ?ApparatusOperationalAlert $alert, User $actor, array $input): ApparatusChangeout
    {
        $this->assertUsableReplacement($unit, $to, $input);
        $previous = $this->planner->previousChangeout((string) $unit->slug);
        $this->assertTruckAvailable($unit, $to, $alert, $actor, $previous);
        $returnOf = $this->planner->isReturn($previous, $unit, $to) ? $previous : null;
        $direct = $this->planner->isDirect($unit, $to, $actor, $alert, $previous, $this->planner->availability((string) $unit->slug));

        // Lines come from the truck the unit is on, before anything moves.
        $changeout = $this->createChangeout($unit, $unit, $to, $alert, $actor, $input, $returnOf);
        ApparatusFleetEvent::record('submitted', $alert, $changeout, $actor, ['status' => $direct ? ApparatusChangeout::STATUS_COMPLETED : ApparatusChangeout::STATUS_PENDING_FLEET, 'to_vehicle_number' => $changeout->to_vehicle_number]);

        if (! $direct) {
            $this->settleHandoff($changeout, null, null);
            app(VehicleSwapNotifier::class)->changeoutWaiting($changeout);

            return $changeout;
        }

        $this->commitMove($changeout, $unit, $to, $alert, $actor, null);

        return $changeout;
    }

    /**
     * The identity is already on the truck (Fleet approved a Daily Checkout report first), so this records the
     * equipment against the existing pair with no move.
     *
     * @param  array<string, mixed>  $input
     */
    private function recordHandoffOnly(Apparatus $holder, Apparatus $alertTruck, ApparatusOperationalAlert $alert, User $actor, array $input): ApparatusChangeout
    {
        $changeout = $this->createChangeout($holder, $alertTruck, $holder, $alert, $actor, $input, null);
        ApparatusFleetEvent::record('submitted', $alert, $changeout, $actor, ['status' => ApparatusChangeout::STATUS_COMPLETED, 'to_vehicle_number' => $changeout->to_vehicle_number]);
        $changeout->update([
            'status' => ApparatusChangeout::STATUS_COMPLETED,
            'effective_at' => now(),
            'move_audit' => [
                'mode' => ApparatusChangeoutPlanner::MODE_HANDOFF_ONLY,
                'before' => null,
                'after' => null,
                'corrections_left_on_truck' => $this->correctionsOn($alertTruck),
                'actor' => ['id' => $actor->getKey(), 'name' => $actor->name],
                'alert_id' => $alert->getKey(),
            ],
        ]);
        ApparatusFleetEvent::record('handoff_only', $alert, $changeout, $actor, ['from_vehicle_number' => $changeout->from_vehicle_number, 'to_vehicle_number' => $changeout->to_vehicle_number]);
        $this->fileFindings($changeout, $alertTruck, $actor);
        $this->settleHandoff($changeout, $alert, $actor);
        $this->forgetReadModelsAfterCommit();

        return $changeout;
    }

    /** @param array<string, mixed> $input */
    private function assertUsableReplacement(Apparatus $unit, ?Apparatus $to, array $input): void
    {
        $number = $input['to_vehicle_number'];
        if ($to === null && $number === null) {
            throw ValidationException::withMessages(['to_apparatus_id' => 'Choose the truck the unit is moving to.']);
        }
        if ($to === null && ! VehicleNumber::isWellFormed($number)) {
            throw ValidationException::withMessages(['to_vehicle_number' => 'Enter the vehicle number shown on the truck.']);
        }
        if ($to !== null && (int) $to->getKey() === (int) $unit->getKey()) {
            throw ValidationException::withMessages(['to_apparatus_id' => 'That is the truck the unit is already on.']);
        }
        if ($to !== null && blank($to->vehicle_number)) {
            throw ValidationException::withMessages(['to_apparatus_id' => 'That truck has no vehicle number on file. Ask Fleet to add it, or pick another.']);
        }
    }

    /**
     * A truck another unit is about to move onto is taken, and a truck Fleet planned for this change can stop being
     * ready. Fleet managers are the ones who can overrule either. Other trucks that are not ready go to Fleet.
     */
    private function assertTruckAvailable(Apparatus $unit, ?Apparatus $to, ?ApparatusOperationalAlert $alert, User $actor, ?ApparatusChangeout $previous): void
    {
        if ($to === null) {
            return;
        }
        if ($actor->canManageFleetSwaps()) {
            // Fleet overrules a claim, but a changeout never displaces a frontline unit, not even Fleet's own.
            if (ApparatusChangeoutPlanner::sameType($to, $unit) && ! ApparatusChangeoutPlanner::isReserveTruck($to)) {
                throw $this->assignedElsewhere($to);
            }

            return;
        }
        $availability = $this->planner->availability((string) $unit->slug);
        $claims = $availability['claims'];
        $planned = $alert !== null && (int) $alert->planned_replacement_apparatus_id === (int) $to->getKey();
        if (! isset($claims[(int) $to->getKey()]) && ! ($planned && ! $this->planner->isEligible($to, $unit, $availability))) {
            return;
        }

        throw new ChangeoutConflictException(
            ChangeoutConflictException::TRUCK_UNAVAILABLE,
            "Truck {$to->vehicle_number} is no longer available for this change. Nothing was saved, and your choices are still on this screen. Pick another truck, or call Fleet.",
            $this->planner->choices($unit, $actor, $alert, $previous),
        );
    }

    /**
     * The record and its equipment lines. Lines always come from the truck holding the unit, which is the truck whose
     * list the member saw. Saved as waiting: a commit moves it on from there, inside the same transaction.
     *
     * @param  array<string, mixed>  $input
     */
    private function createChangeout(Apparatus $unit, Apparatus $from, ?Apparatus $to, ?ApparatusOperationalAlert $alert, User $actor, array $input, ?ApparatusChangeout $returnOf): ApparatusChangeout
    {
        $equipment = $this->planner->equipment($unit);
        $lines = $this->lineBuilder->linesFor($equipment, $input, $returnOf);

        $changeout = ApparatusChangeout::query()->create([
            'client_submission_id' => $input['client_submission_id'],
            'payload_hash' => $input['payload_hash'],
            'alert_id' => $alert?->getKey(),
            'return_of_changeout_id' => $returnOf?->getKey(),
            'unit_slug' => (string) $unit->slug,
            'unit_designation' => ApparatusChangeoutPlanner::unitLabel($unit),
            'station_id' => $unit->station_id,
            'from_apparatus_id' => $from->getKey(),
            'to_apparatus_id' => $to?->getKey(),
            'from_vehicle_number' => $from->vehicle_number,
            'to_vehicle_number' => $to->vehicle_number ?? (string) $input['to_vehicle_number'],
            'to_vehicle_listed' => $to !== null || $this->catalog->holders((string) $input['to_vehicle_number'])->exists(),
            'status' => ApparatusChangeout::STATUS_PENDING_FLEET,
            'handoff_status' => ApparatusChangeout::HANDOFF_PENDING_VERIFICATION,
            'checklist_version' => $equipment['checklist_version'],
            'note' => $input['note'],
            'submitted_by_user_id' => $actor->getKey(),
            'submitted_by_name' => (string) $actor->name,
        ]);
        $changeout->items()->createMany($lines);

        return $changeout;
    }

    /**
     * @param  array{decided_by_user_id: int|string, decided_at: mixed, decision_note: string|null}  $decided
     */
    private function approve(ApparatusChangeout $changeout, ?ApparatusOperationalAlert $alert, User $actor, array $decided, ?int $mappedApparatusId, ?string $oldTruckStatus): void
    {
        $toId = $changeout->to_apparatus_id !== null ? (int) $changeout->to_apparatus_id : $mappedApparatusId;
        if ($toId === null) {
            throw new ChangeoutConflictException(ChangeoutConflictException::TRUCK_REQUIRED, "Vehicle {$changeout->to_vehicle_number} is not on the fleet list. Choose the existing truck record it is, or decline this. The crew's equipment choices are saved.", ['changeout_id' => (int) $changeout->getKey()]);
        }
        $locked = $this->lockTrucks([(int) $changeout->from_apparatus_id, $toId]);
        $from = $locked->get((int) $changeout->from_apparatus_id);
        $to = $locked->get($toId);
        if (! $from instanceof Apparatus || ! $to instanceof Apparatus || (int) $from->getKey() === (int) $to->getKey() || blank($to->vehicle_number)) {
            throw new ChangeoutConflictException(ChangeoutConflictException::TRUCK_REQUIRED, 'That truck record cannot take this unit. Choose a different truck, or decline this. The crew\'s equipment choices are saved.', ['changeout_id' => (int) $changeout->getKey()]);
        }
        if ($from->slug !== $changeout->unit_slug) {
            $holder = $this->planner->holderOf($changeout->unit_slug);
            throw new ChangeoutConflictException(ChangeoutConflictException::STALE, "{$changeout->unit_designation} is no longer on truck {$changeout->from_vehicle_number}, so this cannot be approved as sent. Nothing changed. Review the fleet records, then decline it or ask the crew to send it again.", [
                'current_vehicle_number' => $holder?->vehicle_number,
                'current_apparatus_id' => $holder === null ? null : (int) $holder->getKey(),
                'unit_designation' => $changeout->unit_designation,
            ]);
        }
        if ($changeout->to_apparatus_id !== null && ! VehicleNumber::same($to->vehicle_number, $changeout->to_vehicle_number)) {
            throw new ChangeoutConflictException(ChangeoutConflictException::STALE, "That truck record no longer carries vehicle {$changeout->to_vehicle_number}. Nothing changed. Review the fleet records, then decline it or ask the crew to send it again.", ['changeout_id' => (int) $changeout->getKey()]);
        }

        if (! ApparatusChangeoutPlanner::isReserveTruck($to)) {
            throw $this->assignedElsewhere($to);
        }

        $mapped = $changeout->to_apparatus_id === null;
        $reported = $changeout->to_vehicle_number;
        $previous = $this->planner->previousChangeout((string) $changeout->unit_slug);
        $changeout->update([
            ...$decided,
            'to_apparatus_id' => $to->getKey(),
            'to_vehicle_number' => (string) $to->vehicle_number,
            'return_of_changeout_id' => $changeout->return_of_changeout_id ?? ($this->planner->isReturn($previous, $from, $to) ? $previous?->getKey() : null),
        ]);
        ApparatusFleetEvent::record('decided_approved', $alert, $changeout, $actor, array_filter(['note' => $decided['decision_note'], 'reported_vehicle_number' => $mapped ? $reported : null]));

        $this->commitMove($changeout, $from, $to, $alert, $actor, $oldTruckStatus, $mapped ? $reported : null);
    }

    /** The one commit path, direct or approved: move, findings, alert, then the caches once it is really committed. */
    private function commitMove(ApparatusChangeout $changeout, Apparatus $from, Apparatus $to, ?ApparatusOperationalAlert $alert, User $actor, ?string $oldTruckStatus, ?string $reportedNumber = null): void
    {
        $liveAlert = $alert !== null && $alert->isActive() && $alert->unit_slug === $changeout->unit_slug && $this->planner->alertFits($alert, $from, $to) ? $alert : null;
        // A truck with an open ticket is going to the shop, not back in the reserve pool.
        $oldStatus = $oldTruckStatus ?? $liveAlert->old_truck_status_after ?? ($this->hasOpenTicket($from) ? self::STATUS_MAINTENANCE : null);
        $corrections = $this->correctionsOn($from);
        // A checkout queued on a phone was performed on this list, and may post after the unit has gone.
        $this->revisions->remember($from, $this->checklists->resolve($from), null, true);
        $move = $this->swaps->moveUnitForChangeout($from, $to, $oldStatus);
        $this->revisions->remember($to, $this->checklists->resolve($to), null, false);

        $changeout->update([
            'status' => ApparatusChangeout::STATUS_COMPLETED,
            'effective_at' => now(),
            'move_audit' => [
                'mode' => ApparatusChangeoutPlanner::MODE_START,
                'before' => $move['before'],
                'after' => $move['after'],
                'corrections_left_on_truck' => $corrections,
                'old_truck_status_after' => $oldStatus,
                'actor' => ['id' => $actor->getKey(), 'name' => $actor->name],
                'alert_id' => $changeout->alert_id,
                'reported_vehicle_number' => $reportedNumber,
            ],
        ]);
        ApparatusFleetEvent::record('committed', $alert, $changeout, $actor, ['from_vehicle_number' => $changeout->from_vehicle_number, 'to_vehicle_number' => $changeout->to_vehicle_number]);
        $this->fileFindings($changeout, $from, $actor);
        $this->settleHandoff($changeout, $liveAlert, $actor);
        $this->forgetReadModelsAfterCommit();
    }

    /** Missing and damaged lines become ordinary open defects on the truck the item was on. */
    private function fileFindings(ApparatusChangeout $changeout, Apparatus $truck, User $actor): void
    {
        $reporter = $changeout->submitted_by_user_id !== null && (int) $changeout->submitted_by_user_id !== (int) $actor->getKey()
            ? ($changeout->submittedBy ?? $actor) : $actor;
        $findings = $changeout->items()->whereIn('condition', [ApparatusChangeoutItem::CONDITION_MISSING, ApparatusChangeoutItem::CONDITION_DAMAGED])
            ->whereNull('apparatus_defect_id')->orderBy('id')->get();
        foreach ($findings as $line) {
            $isField = $line->compartment_id === null;
            $defect = $this->defects->recordHandoffFinding(
                $truck,
                (string) $line->compartment_name,
                $line->item_name,
                (string) $line->condition,
                $line->note,
                $reporter,
                $isField ? null : mb_substr((string) $line->compartment_id, 0, 128),
                $isField ? null : mb_substr($line->item_key, 0, 128),
            );
            $line->update(['apparatus_defect_id' => $defect->getKey()]);
        }
    }

    /** No lines open means the handoff is complete, and that alone completes the alert it belongs to. */
    private function settleHandoff(ApparatusChangeout $changeout, ?ApparatusOperationalAlert $alert, ?User $actor): void
    {
        $open = $changeout->items()->whereIn('custody_status', ApparatusChangeoutItem::OPEN_CUSTODY)->exists();
        $changeout->update(['handoff_status' => $open ? ApparatusChangeout::HANDOFF_PENDING_VERIFICATION : ApparatusChangeout::HANDOFF_COMPLETE]);

        if (! $open && $alert !== null && $alert->isActive() && $alert->unit_slug === $changeout->unit_slug) {
            app(ApparatusOperationalAlertService::class)->completeFromChangeout($alert, $changeout, $actor);
        }
    }

    private function hasOpenTicket(Apparatus $truck): bool
    {
        return ApparatusServiceTicket::query()->open()->where('apparatus_id', $truck->getKey())->exists();
    }

    private function assignedElsewhere(Apparatus $truck): ChangeoutConflictException
    {
        return new ChangeoutConflictException(
            ChangeoutConflictException::TRUCK_UNAVAILABLE,
            "Truck {$truck->vehicle_number} is assigned to ".ApparatusChangeoutPlanner::unitLabel($truck).'. Change that unit first.',
        );
    }

    private function correctionsOn(Apparatus $truck): int
    {
        return ApparatusInventoryChange::query()->where('apparatus_id', $truck->getKey())->active()->count();
    }

    private function forgetReadModelsAfterCommit(): void
    {
        DB::afterCommit(static function (): void {
            Cache::forget(DisplaySnapshotService::SNAPSHOT_CACHE_KEY);
            Cache::forget(DisplaySnapshotService::STATIONS_CACHE_KEY);
        });
    }

    private function alreadyPending(ApparatusChangeout $pending, User $actor): ChangeoutConflictException
    {
        return new ChangeoutConflictException(
            ChangeoutConflictException::ALREADY_PENDING,
            "Fleet already has a vehicle change waiting for {$pending->unit_designation}. Nothing new was saved and no truck changed. Wait for Fleet's answer, or call Fleet.",
            ['changeout_id' => (int) $pending->getKey(), 'changeout' => $this->summary($pending, $actor)],
        );
    }

    private function isRescueEquipment(ApparatusChangeout $changeout): bool
    {
        $changeout->loadMissing(['fromApparatus:id,type', 'toApparatus']);

        return str_contains(strtolower((string) $changeout->fromApparatus?->type), 'rescue')
            || str_contains(strtolower((string) $changeout->toApparatus?->type), 'rescue');
    }

    private static function clean(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $text = trim($value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
