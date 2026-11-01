<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Enums\DailyCheckoutRequirement;
use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusChangeoutItem;
use App\Models\ApparatusOperationalAlert;
use App\Models\Station;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Inventory\EquipmentIdentity;
use App\Support\Fleet\VehicleNumber;

/**
 * Everything a crew needs to change trucks, and the read-only rules the changeout service also applies: what a truck's
 * equipment lines are, and which alert goes with a move. Nothing here writes. See docs/apparatus-changeouts.md.
 */
final class ApparatusChangeoutPlanner
{
    public const MODE_START = 'start';

    public const MODE_HANDOFF_ONLY = 'handoff_only';

    /** How long the crew on a new truck is told what stayed on the old one. */
    private const HANDOFF_NOTICE_DAYS = 14;

    public function __construct(private readonly DailyCheckoutChecklistResolver $resolver) {}

    /** @return array<string, mixed> section 6 shape */
    public function context(Apparatus $unit, ?int $alertId = null): array
    {
        $fresh = $unit->refresh();
        $alert = $this->alertFor($fresh, $alertId);
        $holder = $this->resolveUnit($fresh, $alert);
        if ($alert !== null && $alert->unit_slug !== $holder->slug) {
            $alert = $this->openSwapAlert((string) $holder->slug);
        }
        $previous = $this->previousChangeout((string) $holder->slug);
        $handoffOnly = $alert !== null && $this->isHandoffOnly($alert, $holder);
        $choices = $handoffOnly ? ['candidates' => [], 'other_vehicles' => []] : $this->choices($holder, $previous);

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
                'changeout_id' => $alert->completed_by_changeout_id,
            ],
            'mode' => $handoffOnly ? self::MODE_HANDOFF_ONLY : self::MODE_START,
            ...$choices,
            'equipment' => $this->equipmentPayload($holder, $previous),
            'previous_changeout' => $previous === null ? null : $this->previousPayload($previous),
            'open_issues_on_truck' => $holder->openDefects()->count(),
        ];
    }

    /**
     * The alert a screen named, when it is still a live scheduled change, else the unit's own. A stale or wrong id is
     * never a reason to stop a crew.
     */
    public function alertFor(Apparatus $truck, ?int $alertId): ?ApparatusOperationalAlert
    {
        $named = $alertId === null ? null : ApparatusOperationalAlert::query()->find($alertId);

        return $named !== null && $named->isActive() && $named->isUpcomingSwap()
            ? $named
            : $this->openSwapAlert((string) $truck->slug);
    }

    /** The active scheduled change for this unit. A unit has one at a time. */
    public function openSwapAlert(string $unitSlug): ?ApparatusOperationalAlert
    {
        return ApparatusOperationalAlert::query()->active()->forUnit($unitSlug)
            ->where('category', ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP)
            ->orderBy('id')
            ->first();
    }

    /**
     * A page opened on the alert's old truck, after the unit moved away, still means the alert's unit: the row it
     * names now carries a different identity. Any other unit stays itself.
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

    /** The unit is already off the truck the alert was raised on: an admin moved it by hand. */
    public function isHandoffOnly(ApparatusOperationalAlert $alert, Apparatus $unit): bool
    {
        return $alert->isActive() && $alert->isUpcomingSwap() && $alert->unit_slug === $unit->slug
            && (int) $alert->apparatus_id !== (int) $unit->getKey();
    }

    /** A move carries out a scheduled change when it is this unit, leaving the truck the alert was raised on, to any truck. */
    public function alertCompletedBy(ApparatusOperationalAlert $alert, Apparatus $unit): bool
    {
        return $alert->isActive() && $alert->isUpcomingSwap() && $alert->unit_slug === $unit->slug
            && (int) $alert->apparatus_id === (int) $unit->getKey();
    }

    /** A truck that holds no frontline unit, so nobody else is displaced when a crew takes it. */
    public static function isReserveTruck(Apparatus $truck): bool
    {
        return ($truck->getAttributes()['daily_checkout_requirement'] ?? null) === DailyCheckoutRequirement::Reserve->value;
    }

    /** Going back to the truck this unit last left, from the truck that changeout put it on. */
    public function isReturn(?ApparatusChangeout $previous, Apparatus $unit, ?Apparatus $to): bool
    {
        return $previous !== null && $to !== null
            && (int) $previous->from_apparatus_id === (int) $to->getKey()
            && (int) $previous->to_apparatus_id === (int) $unit->getKey();
    }

    /**
     * The newest move off this truck, inside the window a queued checkout can still post to it (the same as a retired
     * checklist). A checkout queued on a phone still posts to the truck it was performed on.
     */
    public function changedOutFrom(Apparatus $truck): ?ApparatusChangeout
    {
        return ApparatusChangeout::query()
            ->where('from_apparatus_id', $truck->getKey())
            ->where('status', ApparatusChangeout::STATUS_COMPLETED)
            ->where('effective_at', '>=', now()->subHours((int) config('daily_checkout.inventory_revision_grace_hours', 168)))
            ->orderByDesc('id')
            ->first();
    }

    public function previousChangeout(string $unitSlug): ?ApparatusChangeout
    {
        return ApparatusChangeout::query()->with(['items'])
            ->where('unit_slug', $unitSlug)
            ->where('status', ApparatusChangeout::STATUS_COMPLETED)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Trucks of the unit's type. Any of them can be chosen, in any status: reserve trucks are offered first, and the rest
     * say which unit they hold.
     *
     * @return array{candidates: list<array<string, mixed>>, other_vehicles: list<array<string, mixed>>}
     */
    public function choices(Apparatus $unit, ?ApparatusChangeout $previous): array
    {
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
            if (self::isReserveTruck($truck)) {
                $candidates[] = [...$row, 'is_return' => $this->isReturn($previous, $unit, $truck)];
            } else {
                $others[] = [...$row, 'listed_as' => self::unitLabel($truck)];
            }
        }

        return ['candidates' => $candidates, 'other_vehicles' => $others];
    }

    /**
     * The effective checklist of a truck as equipment lines, with stable keys.
     *
     * @return array{checklist_version: string|null, usable: bool, lines: list<array<string, mixed>>}
     */
    public function equipment(Apparatus $truck): array
    {
        $resolution = $this->resolver->resolve($truck);
        if (! $resolution['usable'] || ! is_array($resolution['checklist'])) {
            return ['checklist_version' => null, 'usable' => false, 'lines' => []];
        }
        $lines = $seen = [];
        foreach ($resolution['checklist']['compartments'] ?? [] as $compartment) {
            $compartmentId = (string) $compartment['id'];
            foreach ($compartment['items'] ?? [] as $index => $item) {
                $key = (string) ($item['id'] ?? $compartmentId.'-item-'.($index + 1));
                // Older templates only promise unique ids inside a compartment.
                $key = isset($seen[$key]) ? $compartmentId.':'.$key : $key;
                $key = isset($seen[$key]) ? $key.':'.($index + 1) : $key;
                $seen[$key] = true;
                $lines[] = [
                    'item_key' => $key,
                    'equipment_key' => EquipmentIdentity::forItem($item),
                    'compartment_id' => $compartmentId,
                    'compartment_name' => (string) ($compartment['name'] ?? $compartment['title']),
                    'item_name' => (string) $item['name'],
                    'expected_quantity' => is_int($item['expectedQuantity'] ?? null) ? $item['expectedQuantity'] : null,
                ];
            }
        }

        return ['checklist_version' => $resolution['checklist_version'], 'usable' => true, 'lines' => $lines];
    }

    public static function unitLabel(Apparatus $truck): string
    {
        return (string) ($truck->designation ?: $truck->name ?: $truck->getAttribute('unit_id') ?: $truck->vehicle_number);
    }

    /**
     * Shown with the new truck's checklist: where the crew came from and what stayed there. The newest changeout onto
     * this row, while this row still holds that unit.
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

        return [
            'from_vehicle_number' => $changeout->from_vehicle_number,
            'effective_at' => $changeout->effective_at?->utc()->toIso8601ZuluString(),
            'left_behind' => $changeout->leftBehindItems()->get()->map(static fn (ApparatusChangeoutItem $item): array => [
                'name' => $item->item_name,
                'note' => $item->note,
            ])->values()->all(),
        ];
    }

    private function stationName(Apparatus $truck): ?string
    {
        $station = $truck->station;

        return $station instanceof Station ? (string) $station->name : null;
    }

    /** @return array<string, mixed> */
    private function equipmentPayload(Apparatus $truck, ?ApparatusChangeout $previous): array
    {
        $equipment = $this->equipment($truck);
        $lastLines = $previous !== null && (int) $previous->to_apparatus_id === (int) $truck->getKey()
            ? $previous->items->keyBy('item_key') : collect();

        $groups = [];
        foreach ($equipment['lines'] as $line) {
            /** @var ApparatusChangeoutItem|null $last */
            $last = $lastLines->get($line['item_key']);
            $groups[$line['compartment_id']] ??= ['id' => $line['compartment_id'], 'name' => $line['compartment_name'], 'items' => []];
            $groups[$line['compartment_id']]['items'][] = [
                'item_key' => $line['item_key'],
                'name' => $line['item_name'],
                'expected_quantity' => $line['expected_quantity'],
                // Shown beside the line on the way back. It never changes what the line starts as.
                'previous' => $last === null ? null : ['handling' => $last->handling, 'note' => $last->note],
            ];
        }

        return [
            'checklist_version' => $equipment['checklist_version'],
            'usable' => $equipment['usable'],
            'compartments' => array_values($groups),
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
            'submitted_by_name' => $previous->submitted_by_name,
        ];
    }
}
