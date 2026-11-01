<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusChangeoutItem;
use App\Models\ApparatusFleetEvent;
use App\Models\ApparatusInventoryChange;
use App\Models\ApparatusOperationalAlert;
use App\Models\Employee;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Display\DisplaySnapshotService;
use App\Services\Inventory\ChecklistRevisionStore;
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
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Moves a unit from one physical truck to another and records what the crew took and what they left
 * (docs/apparatus-changeouts.md). A crew's submission happens when it is sent: nobody approves it, any truck can be
 * chosen, and Fleet is told afterwards. The new truck is In Service and the old truck is Out of Service, every time.
 *
 * One writer: nothing else changes these tables or moves an identity for a changeout. Lock order: the alert, then both
 * trucks in ascending id. A commit never closes a ticket or a defect: a swap does not fix a truck.
 */
final class ApparatusChangeoutService
{
    private const ATTEMPTS = 3;

    /** Namespace for the changeout id derived from a checkout's client_submission_id. Fixed: a replay must derive the same id. */
    private const CHECKOUT_ID_NAMESPACE = '6f0c9a44-6a4e-5d0b-9b0e-3f1b7a2c8d11';

    public function __construct(
        private readonly ApparatusChangeoutPlanner $planner,
        private readonly VehicleSwapService $swaps,
        private readonly FleetVehicleCatalog $catalog,
        private readonly DailyCheckoutChecklistResolver $checklists,
        private readonly ChecklistRevisionStore $revisions,
        private readonly ChangeoutLineBuilder $lineBuilder,
        private readonly ChangeoutNewTruck $newTrucks,
        private readonly ApparatusChangeoutPresenter $presenter,
    ) {}

    /** @return array<string, array<int, mixed>> the submission contract, checked at the API boundary and again here */
    public static function rules(): array
    {
        return [
            'client_submission_id' => ['required', 'uuid'],
            'expected_vehicle_number' => ['present', 'nullable', 'string', 'max:64'],
            'expected_unit_slug' => ['required', 'string', 'max:255'],
            'alert_id' => ['sometimes', 'nullable', 'integer'],
            'to_apparatus_id' => ['sometimes', 'nullable', 'integer'],
            'to_vehicle_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'checklist_version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'exceptions' => ['sometimes', 'nullable', 'array', 'max:1000'],
            'exceptions.*' => ['array'],
            'exceptions.*.item_key' => ['required', 'string', 'max:255'],
            'exceptions.*.handling' => ['sometimes', 'nullable', Rule::in(ApparatusChangeoutItem::HANDLINGS)],
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
            return $this->attempt((int) $unit->getKey(), $actor, $input);
        } catch (UniqueConstraintViolationException) {
            // Two sends raced past the checks above, or two crews typed the same new truck. The database let one through.
            $replay = $this->replayOf($input);

            return $replay ?? $this->attempt((int) $unit->getKey(), $actor, $input);
        }
    }

    /**
     * A queued checkout from an old phone named a different truck: that is a swap, and it goes down the same path as
     * submit(). There is no equipment list, because nobody was asked for one. The caller holds the inspection's
     * transaction and has already locked the row, so this commits with the checkout or not at all, and Fleet is told only
     * after that. It is idempotent on the checkout's own id: the changeout's id is derived from it.
     *
     * @return array{changeout: ApparatusChangeout, created: bool, replayed: bool}
     *
     * @throws ChangeoutConflictException
     */
    public function recordFromCheckout(Apparatus $unit, User $actor, string $inspectionSubmissionId, string $reportedNumber, string $reporterName, ?string $memberNote): array
    {
        $note = trim("Reported at Daily Checkout by {$reporterName}. No equipment list was recorded. ".trim((string) $memberNote));
        $input = [
            ...$this->normalized($unit, $actor, [
                'client_submission_id' => Uuid::uuid5(self::CHECKOUT_ID_NAMESPACE, strtolower($inspectionSubmissionId))->toString(),
                'expected_vehicle_number' => $unit->vehicle_number,
                'expected_unit_slug' => $unit->slug,
                'to_vehicle_number' => $reportedNumber,
                'note' => mb_substr($note, 0, 1000),
            ]),
            'from_checkout' => true,
        ];

        $result = $this->replayOf($input) ?? $this->record((int) $unit->getKey(), $actor, $input);
        if ($result['created']) {
            $changeout = $result['changeout'];
            DB::afterCommit(fn () => $this->tellFleetAfterCommit($changeout));
        }

        return $result;
    }

    /**
     * Fleet has seen it. Nothing about a truck changes. Repeating it changes nothing.
     *
     * @throws AuthorizationException
     */
    public function acknowledge(int $changeoutId, User $actor): ApparatusChangeout
    {
        if (! $actor->canManageFleetSwaps()) {
            throw new AuthorizationException('Fleet manage access is required to acknowledge a vehicle change.');
        }

        return DB::transaction(function () use ($changeoutId, $actor): ApparatusChangeout {
            $changeout = ApparatusChangeout::query()->lockForUpdate()->findOrFail($changeoutId);
            if ($changeout->isAcknowledged()) {
                return $changeout;
            }
            $changeout->update(['acknowledged_at' => now(), 'acknowledged_by_user_id' => $actor->getKey()]);
            ApparatusFleetEvent::record('acknowledged', null, $changeout, $actor);

            return $changeout;
        }, self::ATTEMPTS)->refresh();
    }

    /** @return array<string, mixed> section 6 shape */
    public function summary(ApparatusChangeout $changeout, bool $withLines = false): array
    {
        return $this->presenter->summary($changeout, $withLines);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{changeout: ApparatusChangeout, created: bool, replayed: bool}
     */
    private function attempt(int $routeTruckId, User $actor, array $input): array
    {
        $result = DB::transaction(fn (): array => $this->record($routeTruckId, $actor, $input), self::ATTEMPTS);
        if ($result['created']) {
            $this->tellFleetAfterCommit($result['changeout']);
        }

        return $result;
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
        foreach ($valid['exceptions'] ?? [] as $exception) {
            $exceptions[(string) $exception['item_key']] = [
                'handling' => $exception['handling'] ?? null,
                'note' => self::clean($exception['note'] ?? null, 500),
            ];
        }
        ksort($exceptions, SORT_STRING);

        $input = [
            'apparatus_id' => (int) $unit->getKey(),
            'actor_id' => (int) $actor->getKey(),
            'expected_vehicle_number' => VehicleNumber::normalize($valid['expected_vehicle_number'] ?? null),
            'expected_unit_slug' => self::clean($valid['expected_unit_slug'], 255),
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
        // Vehicle numbers are not unique columns: two crews typing one new number take turns, so only the first makes it.
        if ($input['to_vehicle_number'] !== null && DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['changeout-truck:'.strtolower($input['to_vehicle_number'])]);
        }
        $choice = $this->replacement($input, $holder);
        $handoffOnly = $alert !== null && $this->planner->isHandoffOnly($alert, $holder)
            && ($choice['id'] === null || $choice['id'] === (int) $holder->getKey()) && $this->namesNoOtherNumber($input, $holder);

        $locked = $this->lockTrucks([(int) $holder->getKey(), $handoffOnly ? (int) $alert->apparatus_id : $choice['id']]);
        // The first send may have committed while this one waited on the locks.
        $replay = $this->replayOf($input);
        if ($replay !== null) {
            return $replay;
        }
        /** @var Apparatus $unit */
        $unit = $locked->get((int) $holder->getKey());
        $this->assertStillOnExpectedTruck($unit, $input);

        $changeout = $handoffOnly
            ? $this->recordHandoffOnly($unit, $locked->get((int) $alert->apparatus_id) ?? $unit, $alert, $actor, $input)
            : $this->recordMove($unit, $choice, $locked, $alert, $actor, $input);

        return ['changeout' => $changeout->refresh(), 'created' => true, 'replayed' => false];
    }

    /** The alert the crew named, or the unit's own scheduled change when they named none. Never a reason to refuse. */
    private function lockedAlert(?int $alertId, Apparatus $routeTruck): ?ApparatusOperationalAlert
    {
        $id = $this->planner->alertFor($routeTruck, $alertId)?->getKey();

        return $id === null ? null : ApparatusOperationalAlert::query()->lockForUpdate()->find($id);
    }

    /**
     * The truck the crew named. A picked truck is that truck. A typed number is the first other row carrying it, and a
     * number with no row is made into one when the move happens.
     *
     * @param  array<string, mixed>  $input
     * @return array{id: int|null, shared_with_truck: int|null}
     */
    private function replacement(array $input, Apparatus $unit): array
    {
        if ($input['to_apparatus_id'] !== null) {
            if (Apparatus::query()->whereKey($input['to_apparatus_id'])->exists()) {
                return ['id' => $input['to_apparatus_id'], 'shared_with_truck' => null];
            }
            if ($input['to_vehicle_number'] === null) {
                throw ValidationException::withMessages(['to_apparatus_id' => 'That truck is not on the fleet list. Pick one from the list, or type its number.']);
            }
        }
        if ($input['to_vehicle_number'] === null) {
            return ['id' => null, 'shared_with_truck' => null];
        }
        $holders = $this->catalog->holders($input['to_vehicle_number'])->pluck('id')->map(static fn (mixed $id): int => (int) $id);
        $others = $holders->reject(static fn (int $id): bool => $id === (int) $unit->getKey())->values();
        if ($others->isEmpty()) {
            return ['id' => null, 'shared_with_truck' => null];
        }

        return ['id' => $others->first(), 'shared_with_truck' => $holders->count() > 1 ? $holders->first(static fn (int $id): bool => $id !== $others->first()) : null];
    }

    /** @param array<string, mixed> $input */
    private function namesNoOtherNumber(array $input, Apparatus $unit): bool
    {
        return $input['to_vehicle_number'] === null || VehicleNumber::same($input['to_vehicle_number'], $unit->vehicle_number);
    }

    /**
     * Both trucks, in ascending id, so two moves can never interleave. A queued checkout has its route row locked first;
     * if that ever deadlocks with a move, the database refuses one and the checkout's own transaction retries it.
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

    /** @param array<string, mixed> $input */
    private function assertStillOnExpectedTruck(Apparatus $unit, array $input): void
    {
        $slug = $input['expected_unit_slug'];
        $sameUnit = $slug === $unit->slug;
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

    /**
     * @param  array{id: int|null, shared_with_truck: int|null}  $choice
     * @param  Collection<int, Apparatus>  $locked
     * @param  array<string, mixed>  $input
     */
    private function recordMove(Apparatus $unit, array $choice, Collection $locked, ?ApparatusOperationalAlert $alert, User $actor, array $input): ApparatusChangeout
    {
        $review = [];
        $newRecord = false;
        $to = $choice['id'] === null ? null : $locked->get($choice['id']);
        if ($to === null) {
            $number = $this->assertUsableNumber($input['to_vehicle_number'], $unit);
            $made = $this->newTrucks->create($number, $unit, $actor);
            $to = $made['truck'];
            $newRecord = true;
            $review[] = $made['review_note'];
        }
        if ((int) $to->getKey() === (int) $unit->getKey()) {
            throw ValidationException::withMessages(['to_apparatus_id' => 'That is the truck the unit is already on.']);
        }
        if (blank($to->vehicle_number)) {
            throw ValidationException::withMessages(['to_apparatus_id' => 'That truck has no vehicle number on file. Pick another, or type the number shown on the truck.']);
        }
        if ($choice['shared_with_truck'] !== null) {
            $review[] = "Two records share truck number {$to->vehicle_number}. This used the record listed as ".ApparatusChangeoutPlanner::unitLabel($to).'. Please review.';
        }

        $previous = $this->planner->previousChangeout((string) $unit->slug);
        $returnOf = $this->planner->isReturn($previous, $unit, $to) ? $previous : null;
        $displaced = ApparatusChangeoutPlanner::isReserveTruck($to) ? null : ApparatusChangeoutPlanner::unitLabel($to);
        if ($displaced !== null) {
            $fromNumber = $unit->vehicle_number ?: 'not recorded';
            $review[] = "{$displaced} was on truck {$to->vehicle_number} and is now listed on truck {$fromNumber}, which is ".ApparatusChangeout::OLD_TRUCK_STATUS.'. Please review.';
        }
        $alert = $alert !== null && $this->planner->alertCompletedBy($alert, $unit) ? $alert : null;

        // Lines come from the truck the unit is on, before anything moves.
        $changeout = $this->createChangeout($unit, $unit, $to, $alert, $actor, $input, $returnOf, ! $newRecord);
        $corrections = $this->correctionsOn($unit);
        // A checkout queued on a phone was performed on this list, and may post after the unit has gone.
        $this->revisions->remember($unit, $this->checklists->resolve($unit), null, true);
        $move = $this->swaps->moveUnitForChangeout($unit, $to);
        $this->revisions->remember($to, $this->checklists->resolve($to), null, false);

        $changeout->update([
            'move_audit' => [
                'mode' => ApparatusChangeoutPlanner::MODE_START,
                ...(($input['from_checkout'] ?? false) ? ['source' => 'daily_checkout'] : []),
                'before' => $move['before'],
                'after' => $move['after'],
                'corrections_left_on_truck' => $corrections,
                'old_truck_status' => ApparatusChangeout::OLD_TRUCK_STATUS,
                'new_truck_status' => VehicleSwapService::CHANGEOUT_NEW_TRUCK_STATUS,
                'new_truck_record' => $newRecord,
                'displaced_unit' => $displaced,
                'review_notes' => $review,
                'actor' => ['id' => $actor->getKey(), 'name' => $actor->name],
                'alert_id' => $changeout->alert_id,
            ],
        ]);
        ApparatusFleetEvent::record('committed', $alert, $changeout, $actor, array_filter(['from_vehicle_number' => $changeout->from_vehicle_number, 'to_vehicle_number' => $changeout->to_vehicle_number, 'displaced_unit' => $displaced]));
        if ($alert !== null) {
            app(ApparatusOperationalAlertService::class)->completeFromChangeout($alert, $changeout, $actor);
        }
        $this->forgetReadModelsAfterCommit();

        return $changeout;
    }

    /**
     * The identity is already on the truck, because the unit was moved by hand after the alert was raised (an admin editing
     * an apparatus can move a slug), so this records what the crew took and left against the existing pair. No identity
     * moves. The truck they left is Out of Service, as on every swap.
     *
     * @param  array<string, mixed>  $input
     */
    private function recordHandoffOnly(Apparatus $holder, Apparatus $alertTruck, ApparatusOperationalAlert $alert, User $actor, array $input): ApparatusChangeout
    {
        $changeout = $this->createChangeout($holder, $alertTruck, $holder, $alert, $actor, $input, null, true);
        $alertTruck->forceFill(['status' => ApparatusChangeout::OLD_TRUCK_STATUS])->save();
        $changeout->update([
            'move_audit' => [
                'mode' => ApparatusChangeoutPlanner::MODE_HANDOFF_ONLY,
                'before' => null,
                'after' => null,
                'corrections_left_on_truck' => $this->correctionsOn($alertTruck),
                'old_truck_status' => $alertTruck->getAttribute('status'),
                'new_truck_record' => false,
                'displaced_unit' => null,
                'review_notes' => [],
                'actor' => ['id' => $actor->getKey(), 'name' => $actor->name],
                'alert_id' => $alert->getKey(),
            ],
        ]);
        ApparatusFleetEvent::record('handoff_only', $alert, $changeout, $actor, ['from_vehicle_number' => $changeout->from_vehicle_number, 'to_vehicle_number' => $changeout->to_vehicle_number]);
        app(ApparatusOperationalAlertService::class)->completeFromChangeout($alert, $changeout, $actor);
        $this->forgetReadModelsAfterCommit();

        return $changeout;
    }

    private function assertUsableNumber(?string $number, Apparatus $unit): string
    {
        if ($number === null) {
            throw ValidationException::withMessages(['to_apparatus_id' => 'Choose the truck the unit is moving to.']);
        }
        if (! VehicleNumber::isWellFormed($number)) {
            throw ValidationException::withMessages(['to_vehicle_number' => 'Enter the vehicle number shown on the truck.']);
        }
        if (VehicleNumber::same($number, $unit->vehicle_number)) {
            throw ValidationException::withMessages(['to_vehicle_number' => 'That is the truck the unit is already on.']);
        }

        return $number;
    }

    /**
     * The record and its equipment lines. Lines always come from the truck holding the unit, which is the truck whose
     * list the crew saw. It is complete from the moment it exists: there is nothing left for anyone to settle.
     *
     * @param  array<string, mixed>  $input
     */
    private function createChangeout(Apparatus $unit, Apparatus $from, Apparatus $to, ?ApparatusOperationalAlert $alert, User $actor, array $input, ?ApparatusChangeout $returnOf, bool $listed): ApparatusChangeout
    {
        $equipment = $this->planner->equipment($unit);

        $changeout = ApparatusChangeout::query()->create([
            'client_submission_id' => $input['client_submission_id'],
            'payload_hash' => $input['payload_hash'],
            'alert_id' => $alert?->getKey(),
            'return_of_changeout_id' => $returnOf?->getKey(),
            'unit_slug' => (string) $unit->slug,
            'unit_designation' => ApparatusChangeoutPlanner::unitLabel($unit),
            'station_id' => $unit->station_id,
            'from_apparatus_id' => $from->getKey(),
            'to_apparatus_id' => $to->getKey(),
            'from_vehicle_number' => $from->vehicle_number,
            'to_vehicle_number' => (string) $to->vehicle_number,
            'to_vehicle_listed' => $listed,
            'status' => ApparatusChangeout::STATUS_COMPLETED,
            'handoff_status' => ApparatusChangeout::HANDOFF_COMPLETE,
            'checklist_version' => $equipment['checklist_version'],
            'note' => $input['note'],
            'submitted_by_user_id' => $actor->getKey(),
            'submitted_by_name' => (string) $actor->name,
            'effective_at' => now(),
        ]);
        // A checkout that named a truck said nothing about what was taken or left, so no line claims either.
        if (! ($input['from_checkout'] ?? false)) {
            $changeout->items()->createMany($this->lineBuilder->linesFor($equipment, $input['exceptions']));
        }

        return $changeout;
    }

    /** After commit and best effort: a problem telling Fleet never fails a swap that already happened. */
    private function tellFleetAfterCommit(ApparatusChangeout $changeout): void
    {
        try {
            app(VehicleSwapNotifier::class)->changeoutCommitted($changeout);
        } catch (Throwable $exception) {
            report($exception);
        }
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

    private static function clean(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $text = trim($value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
