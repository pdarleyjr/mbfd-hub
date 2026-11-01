<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusFleetEvent;
use App\Models\ApparatusOperationalAlert;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use App\Services\Notifications\NotificationDispatchService;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The one writer and reader of operational alerts (docs/apparatus-changeouts.md, sections 2, 7 and 9). An alert
 * describes a unit; it never changes an apparatus, a ticket or a defect. Reading an alert never changes it.
 */
class ApparatusOperationalAlertService
{
    public const NOTIFICATION_SUBMISSION_TYPE = 'fleet_operational_alert';

    public const STATE_PLANNED = 'planned';

    public const STATE_VEHICLE_CHANGED = 'vehicle_changed';

    private const STATE_LABELS = [
        self::STATE_PLANNED => 'Planned',
        self::STATE_VEHICLE_CHANGED => 'Vehicle changed',
    ];

    private const CONSOLE_ACTOR = 'Console';

    private const TEXT_FIELDS = ['title', 'message', 'reason', 'instructions', 'source'];

    public function __construct(private readonly NotificationDispatchService $notifications) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?User $actor): ApparatusOperationalAlert
    {
        if ($actor !== null) {
            $this->authorizeManager($actor);
        }
        $validated = $this->validated($data, true, is_numeric($data['apparatus_id'] ?? null) ? (int) $data['apparatus_id'] : null);
        $dedupeKey = $validated['dedupe_key'] ?? null;

        $existing = $this->byDedupeKey($dedupeKey);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($validated, $actor): ApparatusOperationalAlert {
                $unit = Apparatus::query()->findOrFail((int) $validated['apparatus_id']);
                if ($validated['category'] === ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP) {
                    $this->refuseSecondPlannedChangeout((string) $unit->slug, null);
                }
                $alert = ApparatusOperationalAlert::query()->create([
                    ...$validated,
                    ...$this->unitIdentity($unit),
                    'status' => ApparatusOperationalAlert::STATUS_ACTIVE,
                    'send_notification' => (bool) ($validated['send_notification'] ?? false),
                    'created_by_user_id' => $actor?->getKey(),
                    'updated_by_user_id' => $actor?->getKey(),
                ]);
                ApparatusFleetEvent::record('alert_created', $alert, null, $actor, array_filter([
                    'category' => $alert->category,
                    'source' => $alert->source,
                    'dedupe_key' => $alert->dedupe_key,
                ]), self::CONSOLE_ACTOR);
                $this->notifyOnce($alert->refresh(), $actor);

                return $alert;
            }, 3);
        } catch (QueryException $exception) {
            // Two saves raced: the same dedupe key returns the winner, and a second planned changeout gets the plain answer.
            $winner = $this->byDedupeKey($dedupeKey);
            if ($winner !== null) {
                return $winner;
            }
            $this->refuseSecondPlannedChangeout((string) Apparatus::query()->whereKey($validated['apparatus_id'])->value('slug'), null);

            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(ApparatusOperationalAlert $alert, array $data, User $actor): ApparatusOperationalAlert
    {
        $this->authorizeManager($actor);
        $validated = $this->validated($data, false, (int) $alert->apparatus_id);

        try {
            return DB::transaction(function () use ($alert, $validated, $actor): ApparatusOperationalAlert {
                $locked = $this->lockedOrFail($alert);
                if (! $locked->isActive()) {
                    throw ValidationException::withMessages(['status' => 'This alert is no longer active, so it cannot be edited. It stays in history.']);
                }

                $locked->fill($validated);
                $changed = [];
                foreach (array_keys($locked->getDirty()) as $field) {
                    $changed[$field] = ['from' => $this->eventValue($locked->getOriginal($field)), 'to' => $this->eventValue($locked->getAttribute($field))];
                }
                if ($changed === []) {
                    return $locked;
                }
                if ($locked->isUpcomingSwap()) {
                    $this->refuseSecondPlannedChangeout($locked->unit_slug, (int) $locked->getKey());
                }

                $locked->updated_by_user_id = $actor->getKey();
                $locked->save();
                ApparatusFleetEvent::record('alert_updated', $locked, null, $actor, ['changed' => $changed]);
                $this->notifyOnce($locked, $actor);

                return $locked->refresh();
            }, 3);
        } catch (QueryException $exception) {
            // Lost a race to the one-planned-changeout index: give the plain answer, not the database's.
            $this->refuseSecondPlannedChangeout($alert->unit_slug, (int) $alert->getKey());

            throw $exception;
        }
    }

    public function cancel(ApparatusOperationalAlert $alert, User $actor, ?string $note = null): ApparatusOperationalAlert
    {
        return $this->end($alert, $actor, $note, ApparatusOperationalAlert::STATUS_CANCELLED, 'alert_cancelled');
    }

    public function close(ApparatusOperationalAlert $alert, User $actor, ?string $note = null): ApparatusOperationalAlert
    {
        return $this->end($alert, $actor, $note, ApparatusOperationalAlert::STATUS_CLOSED, 'alert_closed');
    }

    /**
     * Completes the alert from a finished changeout of the same unit. Idempotent for the changeout that completed
     * it; any mismatch returns false and changes nothing. A ticket is never touched: a swap does not fix a truck.
     */
    public function completeFromChangeout(ApparatusOperationalAlert $alert, ApparatusChangeout $changeout, ?User $actor): bool
    {
        $completed = DB::transaction(function () use ($alert, $changeout, $actor): bool {
            $locked = ApparatusOperationalAlert::query()->lockForUpdate()->find($alert->getKey());
            $current = ApparatusChangeout::query()->find($changeout->getKey());
            if ($locked === null || $current === null || ! $this->belongsTogether($locked, $current)) {
                return false;
            }
            if ($locked->status === ApparatusOperationalAlert::STATUS_COMPLETED) {
                return (int) $locked->completed_by_changeout_id === (int) $current->getKey();
            }
            if (! $locked->isActive() || ! $current->isCompleted() || $current->handoff_status !== ApparatusChangeout::HANDOFF_COMPLETE) {
                return false;
            }

            $locked->update([
                'status' => ApparatusOperationalAlert::STATUS_COMPLETED,
                'completed_by_changeout_id' => $current->getKey(),
                'closed_at' => now(),
                'closed_by_user_id' => $actor?->getKey(),
                'updated_by_user_id' => $actor?->getKey() ?? $locked->updated_by_user_id,
            ]);
            ApparatusFleetEvent::record('alert_completed', $locked, $current, $actor, [], self::CONSOLE_ACTOR);

            return true;
        }, 3);
        if ($completed) {
            $alert->refresh();
        }

        return $completed;
    }

    /**
     * The alerts a station page shows, section 6 shape. One query however many alerts there are: the alerts with the
     * truck now holding each unit.
     *
     * @return list<array<string, mixed>>
     */
    public function forStation(int $stationId, ?User $viewer): array
    {
        $table = (new ApparatusOperationalAlert)->getTable();
        $alerts = ApparatusOperationalAlert::query()
            ->leftJoin('apparatuses as holder', 'holder.slug', '=', "{$table}.unit_slug")
            ->select("{$table}.*", 'holder.id as current_apparatus_id', 'holder.vehicle_number as current_vehicle_number')
            ->where("{$table}.status", ApparatusOperationalAlert::STATUS_ACTIVE)
            ->where(fn (Builder $query) => $query
                ->where("{$table}.station_id", $stationId)
                ->orWhereJsonContains("{$table}.additional_station_ids", $stationId))
            ->where(fn (Builder $query) => $query
                ->where("{$table}.category", ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP)
                ->orWhereNull("{$table}.ends_on")
                ->orWhere("{$table}.ends_on", '>=', HubTimezone::today()->toDateString()))
            ->get();
        if ($alerts->isEmpty()) {
            return [];
        }

        $canAct = $this->canAct($viewer);

        return $this->sorted($alerts)
            ->map(fn (ApparatusOperationalAlert $alert): array => $this->present($alert, $canAct))
            ->values()
            ->all();
    }

    /** @return Collection<int, ApparatusOperationalAlert> */
    public function activeForUnit(string $unitSlug): Collection
    {
        return $this->sorted(ApparatusOperationalAlert::query()->active()->forUnit($unitSlug)->get())->values();
    }

    private function end(ApparatusOperationalAlert $alert, User $actor, ?string $note, string $status, string $event): ApparatusOperationalAlert
    {
        $this->authorizeManager($actor);
        $cleanNote = $this->plainText($note);
        Validator::make(['note' => $cleanNote], ['note' => ['nullable', 'string', 'max:1000']])->validate();

        return DB::transaction(function () use ($alert, $actor, $cleanNote, $status, $event): ApparatusOperationalAlert {
            $locked = $this->lockedOrFail($alert);
            // A repeat, or a stale screen acting on an alert that already ended, changes nothing. It stays in history.
            if (! $locked->isActive()) {
                return $locked;
            }
            $locked->update([
                'status' => $status,
                'closed_by_user_id' => $actor->getKey(),
                'closed_at' => now(),
                'close_note' => $cleanNote,
                'updated_by_user_id' => $actor->getKey(),
            ]);
            ApparatusFleetEvent::record($event, $locked, null, $actor, array_filter(['note' => $cleanNote]));

            return $locked->refresh();
        }, 3);
    }

    /**
     * Sends once: notified_at is set in the same transaction as the alert, and the fan-out only starts after it
     * commits. A delivery problem can never undo the alert.
     */
    private function notifyOnce(ApparatusOperationalAlert $alert, ?User $actor): void
    {
        if (! $alert->send_notification || $alert->notified_at !== null || ! $alert->isActive()) {
            return;
        }
        $alert->forceFill(['notified_at' => now()])->save();
        $category = User::NOTIFICATION_PREFERENCE_FLEET_OPERATIONAL_ALERTS;
        $eventKey = NotificationDispatchService::eventKey($category, 'apparatus_operational_alert', (int) $alert->getKey(), 'created');

        $this->notifications->dispatch($category, new NewSubmissionNotification(
            submissionType: self::NOTIFICATION_SUBMISSION_TYPE,
            title: $alert->title,
            body: $alert->message,
            actionUrl: $alert->station_id !== null ? "/daily/stations/{$alert->station_id}" : '/daily/stations',
            icon: 'heroicon-o-truck',
            eventKey: $eventKey,
            pushSubject: $alert->unit_designation,
        ), eventKey: $eventKey);
        ApparatusFleetEvent::record('alert_notified', $alert, null, $actor, ['category' => $category], self::CONSOLE_ACTOR);
    }

    private function belongsTogether(ApparatusOperationalAlert $alert, ApparatusChangeout $changeout): bool
    {
        return $alert->unit_slug === $changeout->unit_slug
            && ($changeout->alert_id === null || (int) $changeout->alert_id === (int) $alert->getKey());
    }

    /** The unit is on the truck the alert was raised on, or a Daily Checkout decision already moved it. */
    private function workflowState(ApparatusOperationalAlert $alert): string
    {
        $holderId = $alert->getAttribute('current_apparatus_id');

        return $holderId !== null && (int) $holderId !== (int) $alert->apparatus_id ? self::STATE_VEHICLE_CHANGED : self::STATE_PLANNED;
    }

    /**
     * @return array{kind: string, label: string|null, apparatus_id: int|null, alert_id: int, changeout_id: null}
     */
    private function action(ApparatusOperationalAlert $alert, string $state, bool $canAct): array
    {
        $none = ['kind' => 'none', 'label' => null, 'apparatus_id' => null, 'alert_id' => (int) $alert->getKey(), 'changeout_id' => null];
        $holderId = $alert->getAttribute('current_apparatus_id');
        if (! $canAct || ! $alert->isUpcomingSwap() || $holderId === null) {
            return $none;
        }
        [$kind, $label] = $state === self::STATE_VEHICLE_CHANGED
            ? ['handoff', 'Record What You Took and Left']
            : ['start', 'Start Changeout'];

        return ['kind' => $kind, 'label' => $label, 'apparatus_id' => (int) $holderId, 'alert_id' => (int) $alert->getKey(), 'changeout_id' => null];
    }

    /** @return array<string, mixed> */
    private function present(ApparatusOperationalAlert $alert, bool $canAct): array
    {
        $state = $this->workflowState($alert);
        $holderId = $alert->getAttribute('current_apparatus_id');

        return [
            'id' => (int) $alert->getKey(),
            'category' => $alert->category,
            'category_label' => $alert->categoryLabel(),
            'title' => $this->plainText($alert->title),
            'message' => $this->plainText($alert->message),
            'reason' => $this->plainText($alert->reason),
            'instructions' => $this->plainText($alert->instructions),
            'unit_designation' => $alert->unit_designation,
            'vehicle_number' => $alert->vehicle_number,
            'current_vehicle_number' => $alert->getAttribute('current_vehicle_number'),
            'current_apparatus_id' => $holderId === null ? null : (int) $holderId,
            'schedule_label' => $alert->scheduleLabel(),
            'scheduled_date' => $alert->scheduled_date?->format('Y-m-d'),
            'scheduled_window' => $alert->scheduled_window,
            'scheduled_at' => $alert->scheduled_at?->utc()->toIso8601ZuluString(),
            'date_passed' => $alert->datePassed(),
            'workflow' => [
                'state' => $state,
                'label' => self::STATE_LABELS[$state],
                'changeout_id' => null,
            ],
            'action' => $this->action($alert, $state, $canAct),
        ];
    }

    /**
     * Upcoming vehicle changes first, then by date; an alert with no date goes after the dated ones.
     *
     * @template TCollection of Collection<int, ApparatusOperationalAlert>
     *
     * @param  TCollection  $alerts
     * @return TCollection
     */
    private function sorted(Collection $alerts): Collection
    {
        return $alerts->sortBy(fn (ApparatusOperationalAlert $alert): array => [
            $alert->isUpcomingSwap() ? 0 : 1,
            $alert->scheduled_at?->setTimezone(HubTimezone::NAME)->format('Y-m-d') ?? $alert->scheduled_date?->format('Y-m-d') ?? '9999-12-31',
            (int) $alert->getKey(),
        ]);
    }

    /** Who may be offered a changeout: the same rule as VehicleIdentificationController::authorizeUnit. */
    private function canAct(?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        return $viewer->canManageFleetSwaps()
            || ($viewer->isAuthenticationAllowed() && $viewer->employeeProfile instanceof Employee);
    }

    private function authorizeManager(User $actor): void
    {
        if (! $actor->canManageFleetSwaps()) {
            throw new AuthorizationException('Only Fleet managers can create or change operational alerts.');
        }
    }

    /** The friendly form of apparatus_alerts_one_active_swap_per_unit. */
    private function refuseSecondPlannedChangeout(string $unitSlug, ?int $exceptAlertId): void
    {
        $designation = ApparatusOperationalAlert::query()->active()->forUnit($unitSlug)
            ->where('category', ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP)
            ->when($exceptAlertId !== null, fn (Builder $query) => $query->whereKeyNot($exceptAlertId))
            ->value('unit_designation');
        if ($designation !== null) {
            throw ValidationException::withMessages(['category' => "{$designation} already has a planned changeout. Edit that one, or close it first."]);
        }
    }

    private function byDedupeKey(?string $dedupeKey): ?ApparatusOperationalAlert
    {
        return $dedupeKey === null ? null : ApparatusOperationalAlert::query()->where('dedupe_key', $dedupeKey)->first();
    }

    private function lockedOrFail(ApparatusOperationalAlert $alert): ApparatusOperationalAlert
    {
        return ApparatusOperationalAlert::query()->lockForUpdate()->findOrFail($alert->getKey());
    }

    /** @return array{unit_slug: string, unit_designation: string, vehicle_number: mixed, station_id: mixed} */
    private function unitIdentity(Apparatus $unit): array
    {
        $slug = trim((string) $unit->slug);
        $designation = trim((string) ($unit->designation ?: $unit->name ?: $unit->getAttribute('unit_id')));
        if ($slug === '' || $designation === '') {
            throw ValidationException::withMessages(['apparatus_id' => 'This apparatus has no unit identity, so an alert cannot be attached to it.']);
        }

        return [
            'unit_slug' => $slug,
            'unit_designation' => $designation,
            'vehicle_number' => $unit->vehicle_number,
            'station_id' => $unit->station_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data, bool $creating, ?int $apparatusId): array
    {
        foreach (self::TEXT_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->plainText($data[$field]);
            }
        }
        $validated = Validator::make($data, $this->rules($creating, $apparatusId))->validate();

        if (array_key_exists('scheduled_at', $validated) && $validated['scheduled_at'] !== null) {
            // A time with no offset is an Eastern wall clock. The stored value carries no offset, so convert first.
            $validated['scheduled_at'] = $validated['scheduled_at'] instanceof DateTimeInterface
                ? CarbonImmutable::instance($validated['scheduled_at'])->setTimezone((string) config('app.timezone'))
                : CarbonImmutable::parse((string) $validated['scheduled_at'], HubTimezone::NAME)->setTimezone((string) config('app.timezone'));
        }
        if (array_key_exists('additional_station_ids', $validated)) {
            $ids = array_values(array_unique(array_map('intval', $validated['additional_station_ids'] ?? [])));
            $validated['additional_station_ids'] = $ids === [] ? null : $ids;
        }

        return $validated;
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $creating, ?int $apparatusId): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            ...($creating ? [
                'apparatus_id' => ['required', 'integer', Rule::exists('apparatuses', 'id')],
                'dedupe_key' => ['nullable', 'string', 'max:120'],
            ] : []),
            'category' => [$required, 'string', Rule::in(array_keys(ApparatusOperationalAlert::CATEGORIES))],
            'title' => [$required, 'string', 'max:180'],
            'message' => [$required, 'string', 'max:1000'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:180'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'source' => ['sometimes', 'nullable', 'string', 'max:180'],
            'scheduled_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'scheduled_window' => ['sometimes', 'nullable', Rule::in(ApparatusOperationalAlert::WINDOWS)],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'additional_station_ids' => ['sometimes', 'nullable', 'array', 'max:20'],
            'additional_station_ids.*' => ['integer', Rule::exists('stations', 'id')],
            'planned_replacement_apparatus_id' => ['sometimes', 'nullable', 'integer', Rule::exists('apparatuses', 'id'), Rule::notIn(array_filter([$apparatusId]))],
            'apparatus_service_ticket_id' => ['sometimes', 'nullable', 'integer', Rule::exists('apparatus_service_tickets', 'id')->where('apparatus_id', $apparatusId)],
            'send_notification' => ['sometimes', 'boolean'],
        ];
    }

    /** Alerts are plain text everywhere they show. Removes real tags and control characters, and keeps "a < b". */
    private function plainText(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        do {
            $before = $value;
            $value = (string) preg_replace('/<\/?[a-z!][^>]*(>|$)/i', '', $value);
        } while ($value !== $before);
        $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value));

        return $value === '' ? null : $value;
    }

    private function eventValue(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface ? CarbonImmutable::instance($value)->utc()->toIso8601ZuluString() : $value;
    }
}
