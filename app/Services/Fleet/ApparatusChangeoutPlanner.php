<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Enums\DailyCheckoutRequirement;
use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusChangeoutItem;
use App\Models\ApparatusOperationalAlert;
use App\Models\ApparatusServiceTicket;
use App\Models\Station;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Inventory\EquipmentIdentity;
use App\Support\Fleet\VehicleNumber;
use App\Support\HubTimezone;
use Illuminate\Validation\ValidationException;

/**
 * Everything a member needs to start a changeout, and the read-only rules the changeout service also applies:
 * which trucks are eligible, what a truck's equipment lines are, and what they default to on a return.
 * Nothing here writes. See docs/apparatus-changeouts.md sections 4 to 6.
 */
final class ApparatusChangeoutPlanner
{
    public const MODE_START = 'start';

    public const MODE_HANDOFF_ONLY = 'handoff_only';

    /** Controlled lines are grouped apart: their compartment is a location, and members see the name only. */
    public const CONTROLLED_GROUP_ID = 'controlled';

    public const CONTROLLED_GROUP_NAME = 'Controlled items';

    public const OFFICER_FIELDS_NAME = 'Officer checklist';

    /** Stands in for the whole list when a truck has no usable checklist, so Fleet has to settle it. */
    public const NO_CHECKLIST_KEY = 'checklist:unavailable';

    private const ELIGIBLE_STATUSES = ['available', 'reserve'];

    /** How long the crew on a new truck is told what stayed on the old one. */
    private const HANDOFF_NOTICE_DAYS = 14;

    public function __construct(private readonly DailyCheckoutChecklistResolver $resolver) {}

    /** @return array<string, mixed> section 6 shape, with additive slug, direct and return_handling keys */
    public function context(Apparatus $unit, User $actor, ?int $alertId = null): array
    {
        $fresh = $unit->refresh();
        $alert = $alertId === null ? $this->openSwapAlert((string) $fresh->slug) : ApparatusOperationalAlert::query()->find($alertId);
        if ($alertId !== null && $alert === null) {
            throw ValidationException::withMessages(['alert_id' => 'That notice no longer exists.']);
        }
        $holder = $this->resolveUnit($fresh, $alert);
        if ($alertId !== null) {
            $this->assertAlertFor($alert, $holder);
        }
        $previous = $this->previousChangeout((string) $holder->slug);
        $recorded = $alert === null ? null : $this->recordedChangeoutId($alert);
        $handoffOnly = $alert !== null && $recorded === null && $this->isHandoffOnly($alert, $holder);
        $choices = $handoffOnly ? ['candidates' => [], 'other_vehicles' => []] : $this->choices($holder, $actor, $alert, $previous);
        $pending = $this->pendingFor((string) $holder->slug);

        return [
            'unit' => [
                'apparatus_id' => (int) $holder->getKey(),
                'designation' => self::unitLabel($holder),
                'slug' => $holder->slug,
                'vehicle_number' => $holder->vehicle_number,
                'station_id' => $holder->station_id,
                'station_label' => $this->stationName($holder),
                'status' => $holder->getAttribute('status'),
            ],
            'alert' => $alert === null ? null : [
                'id' => (int) $alert->getKey(),
                'title' => $alert->title,
                'reason' => $alert->reason,
                'schedule_label' => $alert->scheduleLabel(),
                'vehicle_number' => $alert->vehicle_number,
                'planned_replacement_apparatus_id' => $alert->planned_replacement_apparatus_id,
                'changeout_id' => $recorded,
            ],
            'mode' => $handoffOnly ? self::MODE_HANDOFF_ONLY : self::MODE_START,
            ...$choices,
            'equipment' => $this->equipmentPayload($holder, $previous),
            'open_issues_on_truck' => $holder->openDefects()->count(),
            'previous_changeout' => $previous === null ? null : $this->previousPayload($previous),
            'pending_changeout' => $pending === null ? null : app(ApparatusChangeoutService::class)->summary($pending, $actor),
            'can_fleet_commit' => $actor->canManageFleetSwaps(),
        ];
    }

    /** The active scheduled change for this unit that no changeout has already carried out. */
    public function openSwapAlert(string $unitSlug): ?ApparatusOperationalAlert
    {
        return ApparatusOperationalAlert::query()->active()->forUnit($unitSlug)
            ->where('category', ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP)
            ->whereDoesntHave('changeouts', static fn ($query) => $query->where('status', ApparatusChangeout::STATUS_COMPLETED))
            ->orderBy('id')
            ->first();
    }

    public function recordedChangeoutId(ApparatusOperationalAlert $alert): ?int
    {
        $id = ApparatusChangeout::query()->where('alert_id', $alert->getKey())
            ->where('status', ApparatusChangeout::STATUS_COMPLETED)->orderByDesc('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * A page opened on the alert's old truck, after the unit moved away, still means the alert's unit: the row it
     * names now carries a different identity. Any other unit stays itself and is refused by assertAlertFor.
     */
    public function resolveUnit(Apparatus $truck, ?ApparatusOperationalAlert $alert): Apparatus
    {
        if ($alert === null || (int) $alert->apparatus_id !== (int) $truck->getKey() || $truck->slug === $alert->unit_slug) {
            return $truck;
        }

        return $this->holderOf($alert->unit_slug) ?? $truck;
    }

    public function holderOf(?string $unitSlug): ?Apparatus
    {
        return $unitSlug === null ? null : Apparatus::query()->where('slug', $unitSlug)->first();
    }

    /** An alert is only usable while it is active, and only by the unit it is about. */
    public function assertAlertFor(ApparatusOperationalAlert $alert, Apparatus $unit): void
    {
        if (! $alert->isActive()) {
            throw ValidationException::withMessages(['alert_id' => 'That notice has already been closed. Reload the page to see what is current.']);
        }
        if ($alert->unit_slug !== $unit->slug) {
            throw ValidationException::withMessages(['alert_id' => 'That notice is about a different unit.']);
        }
    }

    public function isHandoffOnly(ApparatusOperationalAlert $alert, Apparatus $unit): bool
    {
        $planned = $alert->planned_replacement_apparatus_id;

        return $alert->isActive() && $alert->unit_slug === $unit->slug && (int) $alert->apparatus_id !== (int) $unit->getKey()
            && ($planned === null || (int) $planned === (int) $unit->getKey());
    }

    /** An alert belongs to a move only from the truck it was raised on, and to the truck it planned if it named one. */
    public function alertFits(ApparatusOperationalAlert $alert, Apparatus $from, ?Apparatus $to): bool
    {
        $planned = $alert->planned_replacement_apparatus_id;

        return (int) $alert->apparatus_id === (int) $from->getKey()
            && ($planned === null || ($to !== null && (int) $planned === (int) $to->getKey()));
    }

    /** Fleet's schedule is what authorizes a crew: its day has to have come, in Eastern time. No date is no wait. */
    public function scheduleArrived(ApparatusOperationalAlert $alert): bool
    {
        $day = $alert->scheduled_at?->setTimezone(HubTimezone::NAME)->format('Y-m-d') ?? $alert->scheduled_date?->format('Y-m-d');

        return $day === null || $day <= HubTimezone::today()->format('Y-m-d');
    }

    /** A truck holding a frontline unit is never a replacement: a changeout does not displace another unit. */
    public static function isReserveTruck(Apparatus $truck): bool
    {
        return ($truck->getAttributes()['daily_checkout_requirement'] ?? null) === DailyCheckoutRequirement::Reserve->value;
    }

    /**
     * What stands in the way of a truck, for a whole list in two queries.
     *
     * @return array{claims: array<int, string>, ticketed: array<int, true>}
     */
    public function availability(string $unitSlug): array
    {
        return [
            'claims' => $this->claimedTrucks($unitSlug),
            'ticketed' => array_fill_keys(ApparatusServiceTicket::query()->open()->distinct()->pluck('apparatus_id')->map(static fn (mixed $id): int => (int) $id)->all(), true),
        ];
    }

    /**
     * Trucks another unit is about to move onto: a planned replacement on an active alert, or a truck named in a
     * waiting decision.
     *
     * @return array<int, string> truck id => the unit that holds the claim
     */
    public function claimedTrucks(string $unitSlug): array
    {
        $claims = [];
        ApparatusOperationalAlert::query()->active()
            ->where('category', ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP)
            ->whereNotNull('planned_replacement_apparatus_id')
            ->where('unit_slug', '!=', $unitSlug)
            ->get(['planned_replacement_apparatus_id', 'unit_designation'])
            ->each(static function (ApparatusOperationalAlert $alert) use (&$claims): void {
                $claims[(int) $alert->planned_replacement_apparatus_id] = (string) $alert->unit_designation;
            });
        ApparatusChangeout::query()->where('status', ApparatusChangeout::STATUS_PENDING_FLEET)
            ->whereNotNull('to_apparatus_id')
            ->where('unit_slug', '!=', $unitSlug)
            ->get(['to_apparatus_id', 'unit_designation'])
            ->each(static function (ApparatusChangeout $changeout) use (&$claims): void {
                $claims[(int) $changeout->to_apparatus_id] = (string) $changeout->unit_designation;
            });

        return $claims;
    }

    public static function sameType(Apparatus $a, Apparatus $b): bool
    {
        $first = trim((string) $a->type);

        return $first !== '' && strcasecmp($first, trim((string) $b->type)) === 0;
    }

    /**
     * Section 4.1. FleetVehicleCatalog::roleFor answers a different question (how to label a truck) and is not used.
     *
     * @param  array{claims: array<int, string>, ticketed: array<int, true>}  $availability
     */
    public function isEligible(Apparatus $truck, Apparatus $unit, array $availability): bool
    {
        return filled($truck->vehicle_number)
            && (int) $truck->getKey() !== (int) $unit->getKey()
            && self::sameType($truck, $unit)
            && in_array(strtolower(trim((string) $truck->getAttribute('status'))), self::ELIGIBLE_STATUSES, true)
            && self::isReserveTruck($truck)
            && ! isset($availability['claims'][(int) $truck->getKey()])
            && ! isset($availability['ticketed'][(int) $truck->getKey()]);
    }

    /**
     * Section 4.2. Only a known reserve truck of the same type can move without a Fleet decision, and a Fleet manager
     * submitting is that decision. For everyone else the truck must be eligible, and an alert has to fit this move and
     * its day has to have come.
     *
     * @param  array{claims: array<int, string>, ticketed: array<int, true>}  $availability
     */
    public function isDirect(Apparatus $unit, ?Apparatus $to, User $actor, ?ApparatusOperationalAlert $alert, ?ApparatusChangeout $previous, array $availability): bool
    {
        if ($to === null || ! self::sameType($to, $unit) || ! self::isReserveTruck($to)) {
            return false;
        }
        if ($actor->canManageFleetSwaps()) {
            return true;
        }
        if (! $this->isEligible($to, $unit, $availability)) {
            return false;
        }
        $scheduled = $alert !== null && $alert->isActive() && $alert->isUpcomingSwap()
            && $this->alertFits($alert, $unit, $to) && $this->scheduleArrived($alert);

        return $scheduled || $this->isReturn($previous, $unit, $to);
    }

    /** Going back to the truck this unit last left, from the truck that changeout put it on. */
    public function isReturn(?ApparatusChangeout $previous, Apparatus $unit, ?Apparatus $to): bool
    {
        return $previous !== null && $to !== null
            && (int) $previous->from_apparatus_id === (int) $to->getKey()
            && (int) $previous->to_apparatus_id === (int) $unit->getKey();
    }

    public function previousChangeout(string $unitSlug): ?ApparatusChangeout
    {
        return ApparatusChangeout::query()->with(['items', 'submittedBy'])
            ->where('unit_slug', $unitSlug)
            ->where('status', ApparatusChangeout::STATUS_COMPLETED)
            ->orderByDesc('id')
            ->first();
    }

    public function pendingFor(string $unitSlug): ?ApparatusChangeout
    {
        return ApparatusChangeout::query()->where('unit_slug', $unitSlug)
            ->where('status', ApparatusChangeout::STATUS_PENDING_FLEET)->first();
    }

    /**
     * Replacement trucks of the unit's type, section 6 shape.
     *
     * @return array{candidates: list<array<string, mixed>>, other_vehicles: list<array<string, mixed>>}
     */
    public function choices(Apparatus $unit, User $actor, ?ApparatusOperationalAlert $alert, ?ApparatusChangeout $previous): array
    {
        $availability = $this->availability((string) $unit->slug);
        $trucks = Apparatus::query()->with('station:id,name')
            ->whereNotNull('vehicle_number')->where('vehicle_number', '!=', '')
            ->where('id', '!=', $unit->getKey())
            ->whereRaw('LOWER(type) = ?', [strtolower(trim((string) $unit->type))])
            ->get()
            ->sort(static fn (Apparatus $a, Apparatus $b): int => strnatcasecmp((string) $a->vehicle_number, (string) $b->vehicle_number));

        $candidates = $others = [];
        foreach ($trucks as $truck) {
            $row = [
                'apparatus_id' => (int) $truck->getKey(),
                'vehicle_number' => VehicleNumber::normalize($truck->vehicle_number),
                'label' => self::unitLabel($truck),
                'location' => $truck->getAttribute('current_location') ?: $this->stationName($truck),
                'status' => $truck->getAttribute('status'),
            ];
            $direct = $this->isDirect($unit, $truck, $actor, $alert, $previous, $availability);
            if ($this->isEligible($truck, $unit, $availability)) {
                $candidates[] = [...$row, 'direct' => $direct, 'is_return' => $this->isReturn($previous, $unit, $truck)];
            } else {
                $others[] = [...$row, 'direct' => $direct];
            }
        }

        return ['candidates' => $candidates, 'other_vehicles' => $others];
    }

    /**
     * The effective checklist of a truck as equipment lines, with stable keys. Controlled officer fields become
     * lines too (field:<id>), since the narcotics box is not a compartment item.
     *
     * @return array{checklist_version: string|null, usable: bool, lines: list<array<string, mixed>>}
     */
    public function equipment(Apparatus $truck): array
    {
        $resolution = $this->resolver->resolve($truck);
        if (! $resolution['usable'] || ! is_array($resolution['checklist'])) {
            return ['checklist_version' => null, 'usable' => false, 'lines' => []];
        }
        $checklist = $resolution['checklist'];
        $lines = $seen = [];
        foreach ($checklist['compartments'] ?? [] as $compartment) {
            $compartmentId = (string) $compartment['id'];
            foreach ($compartment['items'] ?? [] as $index => $item) {
                $key = (string) ($item['id'] ?? $compartmentId.'-item-'.($index + 1));
                // Older templates only promise unique ids inside a compartment.
                $key = isset($seen[$key]) ? $compartmentId.':'.$key : $key;
                $seen[$key] = true;
                $lines[] = [
                    'item_key' => $key,
                    'equipment_key' => EquipmentIdentity::forItem($item),
                    'compartment_id' => $compartmentId,
                    'compartment_name' => (string) ($compartment['name'] ?? $compartment['title']),
                    'item_name' => (string) $item['name'],
                    'expected_quantity' => is_int($item['expectedQuantity'] ?? null) ? $item['expectedQuantity'] : null,
                    'item_class' => ControlledEquipment::matches((string) $item['name']) ? ApparatusChangeoutItem::CLASS_CONTROLLED : ApparatusChangeoutItem::CLASS_STANDARD,
                ];
            }
        }
        foreach ($checklist['fields'] ?? $checklist['officerChecklist'] ?? [] as $field) {
            if (is_array($field) && ControlledEquipment::matches((string) ($field['name'] ?? ''))) {
                $lines[] = [
                    'item_key' => 'field:'.$field['id'],
                    'equipment_key' => null,
                    'compartment_id' => null,
                    'compartment_name' => self::OFFICER_FIELDS_NAME,
                    'item_name' => (string) $field['name'],
                    'expected_quantity' => null,
                    'item_class' => ApparatusChangeoutItem::CLASS_CONTROLLED,
                ];
            }
        }

        return ['checklist_version' => $resolution['checklist_version'], 'usable' => true, 'lines' => $lines];
    }

    /**
     * What a line starts as when the member says nothing about it. On a return (section 5), lines moved last time go
     * back, lines left behind are already on the truck, and anything unsettled has to be confirmed again.
     *
     * @param  array<string, mixed>  $line
     * @return array{handling: string, quantity_moved: int|null, note: string|null}
     */
    public function lineDefault(array $line, ?ApparatusChangeoutItem $last): array
    {
        if ($line['item_class'] === ApparatusChangeoutItem::CLASS_CONTROLLED) {
            return ['handling' => ApparatusChangeoutItem::HANDLING_REVIEW, 'quantity_moved' => null, 'note' => null];
        }
        if ($last === null) {
            return ['handling' => ApparatusChangeoutItem::HANDLING_TRANSFER, 'quantity_moved' => null, 'note' => null];
        }

        return match ($last->custody_status) {
            ApparatusChangeoutItem::CUSTODY_MOVED => ['handling' => ApparatusChangeoutItem::HANDLING_TRANSFER, 'quantity_moved' => $this->carriedQuantity($line, $last), 'note' => null],
            ApparatusChangeoutItem::CUSTODY_LEFT_BEHIND => ['handling' => ApparatusChangeoutItem::HANDLING_LEAVE, 'quantity_moved' => null, 'note' => $last->note],
            default => ['handling' => ApparatusChangeoutItem::HANDLING_REVIEW, 'quantity_moved' => null, 'note' => $last->note],
        };
    }

    public static function unitLabel(Apparatus $truck): string
    {
        return (string) ($truck->designation ?: $truck->name ?: $truck->getAttribute('unit_id') ?: $truck->vehicle_number);
    }

    /**
     * Shown with the new truck's checklist: where the crew came from and what stayed there. The newest changeout onto
     * this row, while this row still holds that unit. Controlled lines are the name only.
     *
     * @return array{from_vehicle_number: string|null, effective_at: string|null, left_behind: list<array{name: string, note: string|null}>}|null
     */
    public function handoffNotice(Apparatus $truck): ?array
    {
        $changeout = ApparatusChangeout::query()
            ->where('to_apparatus_id', $truck->getKey())
            ->where('status', ApparatusChangeout::STATUS_COMPLETED)
            ->where('effective_at', '>=', now()->subDays(self::HANDOFF_NOTICE_DAYS))
            ->orderByDesc('id')
            ->first();
        if ($changeout === null || $changeout->unit_slug !== $truck->slug) {
            return null;
        }
        $left = $changeout->items()
            ->where(static fn ($query) => $query->where('custody_status', ApparatusChangeoutItem::CUSTODY_LEFT_BEHIND)
                ->orWhere(static fn ($controlled) => $controlled->where('item_class', ApparatusChangeoutItem::CLASS_CONTROLLED)->where('handling', ApparatusChangeoutItem::HANDLING_LEAVE)))
            ->orderBy('id')
            ->get();

        return [
            'from_vehicle_number' => $changeout->from_vehicle_number,
            'effective_at' => $changeout->effective_at?->utc()->toIso8601ZuluString(),
            'left_behind' => $left->map(static fn (ApparatusChangeoutItem $item): array => [
                'name' => $item->item_name,
                'note' => $item->isControlled() ? null : $item->note,
            ])->values()->all(),
        ];
    }

    private function stationName(Apparatus $truck): ?string
    {
        $station = $truck->station;

        return $station instanceof Station ? (string) $station->name : null;
    }

    /** @param array<string, mixed> $line */
    private function carriedQuantity(array $line, ApparatusChangeoutItem $last): ?int
    {
        $expected = $line['expected_quantity'];

        return $last->quantity_moved !== null && $expected !== null && $last->quantity_moved <= $expected ? $last->quantity_moved : null;
    }

    /** @return array<string, mixed> */
    private function equipmentPayload(Apparatus $truck, ?ApparatusChangeout $previous): array
    {
        $equipment = $this->equipment($truck);
        $lastLines = $previous !== null && (int) $previous->to_apparatus_id === (int) $truck->getKey()
            ? $previous->items->keyBy('item_key') : collect();

        $groups = $controlled = [];
        foreach ($equipment['lines'] as $line) {
            $isControlled = $line['item_class'] === ApparatusChangeoutItem::CLASS_CONTROLLED;
            $default = $this->lineDefault($line, null);
            /** @var ApparatusChangeoutItem|null $last */
            $last = $lastLines->get($line['item_key']);
            $row = [
                'item_key' => $line['item_key'],
                'name' => $line['item_name'],
                'expected_quantity' => $isControlled ? null : $line['expected_quantity'],
                'controlled' => $isControlled,
                'default_handling' => $default['handling'],
                'previous' => $last === null ? null : $this->previousLine($line, $last, $isControlled),
            ];
            if ($isControlled) {
                $controlled[] = $row;

                continue;
            }
            $groups[$line['compartment_id']] ??= ['id' => $line['compartment_id'], 'name' => $line['compartment_name'], 'items' => []];
            $groups[$line['compartment_id']]['items'][] = $row;
        }
        if ($controlled !== []) {
            $groups[self::CONTROLLED_GROUP_ID] = ['id' => self::CONTROLLED_GROUP_ID, 'name' => self::CONTROLLED_GROUP_NAME, 'items' => $controlled];
        }

        return [
            'checklist_version' => $equipment['checklist_version'],
            'usable' => $equipment['usable'],
            'compartments' => array_values($groups),
        ];
    }

    /**
     * What happened to this line last time, for a return. return_handling is the line's default when the member
     * picks the truck they last left.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function previousLine(array $line, ApparatusChangeoutItem $last, bool $isControlled): array
    {
        $returning = $this->lineDefault($line, $last);
        if ($isControlled) {
            return ['custody_status' => $last->custody_status, 'return_handling' => $returning['handling']];
        }

        return [
            'custody_status' => $last->custody_status,
            'handling' => $last->handling,
            'quantity_moved' => $last->quantity_moved,
            'note' => $last->note,
            'return_handling' => $returning['handling'],
            'return_quantity' => $returning['quantity_moved'],
        ];
    }

    /** @return array<string, mixed> */
    private function previousPayload(ApparatusChangeout $previous): array
    {
        $items = $previous->items;

        return [
            'id' => (int) $previous->getKey(),
            'from_apparatus_id' => (int) $previous->from_apparatus_id,
            'from_vehicle_number' => $previous->from_vehicle_number,
            'to_vehicle_number' => $previous->to_vehicle_number,
            'effective_at' => $previous->effective_at?->utc()->toIso8601ZuluString(),
            'moved' => $items->where('custody_status', ApparatusChangeoutItem::CUSTODY_MOVED)->count(),
            'left_behind' => $items->where('custody_status', ApparatusChangeoutItem::CUSTODY_LEFT_BEHIND)
                ->map(static fn (ApparatusChangeoutItem $item): array => ['name' => $item->item_name, 'note' => $item->note])->values()->all(),
            'open' => $items->filter(static fn (ApparatusChangeoutItem $item): bool => $item->isOpen())
                ->map(static fn (ApparatusChangeoutItem $item): array => ['name' => $item->item_name, 'status' => $item->custody_status])->values()->all(),
            'submitted_by_name' => $previous->submitted_by_name,
        ];
    }
}
