<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusChangeoutItem;
use App\Models\ApparatusVehicleSwapRequest;
use App\Models\User;
use App\Services\Fleet\ApparatusChangeoutPlanner;
use App\Support\Fleet\FleetVehicleCatalog;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Truck Changes tab: which Rescue unit is on which vehicle number, and what changed lately. Read only.
 * A change is a completed changeout or a swap Fleet decided from a Daily Checkout report; either way the truck
 * it started from is a Rescue-type truck, so an Engine or Ladder change never appears here.
 */
final class Captain5RescueTrucks
{
    private const CHANGE_WINDOW_DAYS = 30;

    private const RECENT_HOURS = 24;

    private const MAX_CHANGES = 200;

    public function __construct(private readonly Captain5IssuePresenter $presenter) {}

    /** @return array{rescue_trucks: list<array<string, mixed>>, rescue_truck_changes: list<array<string, mixed>>} */
    public function payload(): array
    {
        $changes = $this->changes();

        return ['rescue_trucks' => $this->trucks($changes), 'rescue_truck_changes' => $changes];
    }

    /**
     * @param  list<array<string, mixed>>  $changes  newest first
     * @return list<array<string, mixed>>
     */
    private function trucks(array $changes): array
    {
        $latest = [];
        foreach ($changes as $change) {
            $latest[$this->unitKey((string) $change['unit'])] ??= $change;
        }

        // The checklist follows the unit between trucks, so it says what the unit is whatever the truck's own type.
        return Apparatus::query()->with('station')
            ->where(fn (Builder $query) => $query->where('daily_checkout_template', 'rescue')
                ->orWhere(fn (Builder $untemplated) => $untemplated->whereRaw("LOWER(TRIM(type)) = 'rescue'")
                    ->where(fn (Builder $pending) => $pending->whereNull('daily_checkout_template')->orWhere('daily_checkout_template', 'pending'))))
            ->get()
            ->reject(fn (Apparatus $truck): bool => $this->isPlainReserve($truck))
            ->map(function (Apparatus $truck) use ($latest): array {
                $unit = $this->presenter->unit($truck);
                $change = $latest[$this->unitKey($unit)] ?? null;

                return [
                    'apparatus_id' => (int) $truck->getKey(),
                    'unit' => $unit,
                    'vehicle_number' => $truck->vehicle_number,
                    'station' => $truck->station?->getAttribute('name'),
                    'status' => (string) ($truck->getAttribute('status') ?: 'Unknown'),
                    'changed_at' => $change['at'] ?? null,
                    'changed_recently' => ($change['recent'] ?? false) === true,
                ];
            })
            ->sortBy('unit', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /** @return list<array<string, mixed>> newest first */
    private function changes(): array
    {
        $since = now()->subDays(self::CHANGE_WINDOW_DAYS);
        $rows = [...$this->changeouts($since), ...$this->fleetDecisions($since)];
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));

        return array_slice($rows, 0, self::MAX_CHANGES);
    }

    /** @return list<array<string, mixed>> */
    private function changeouts(CarbonInterface $since): array
    {
        return ApparatusChangeout::query()
            ->with(['items' => static fn ($items) => $items->where('custody_status', ApparatusChangeoutItem::CUSTODY_LEFT_BEHIND)->orderBy('id')])
            ->where('status', ApparatusChangeout::STATUS_COMPLETED)
            ->where('effective_at', '>=', $since)
            ->where(fn (Builder $trucks) => $trucks->whereIn('from_apparatus_id', $this->rescueTrucks()->select('id'))
                ->orWhereIn('to_apparatus_id', $this->rescueTrucks()->select('id')))
            ->orderByDesc('effective_at')->orderByDesc('id')
            ->limit(self::MAX_CHANGES)->get()
            ->map(fn (ApparatusChangeout $changeout): array => $this->change(
                'changeout:'.$changeout->getKey(),
                'changeout',
                $changeout->unit_designation,
                $changeout->from_vehicle_number,
                $changeout->to_vehicle_number,
                $changeout->effective_at,
                $changeout->submitted_by_name,
                $changeout->note,
                $changeout->items->map(static fn (ApparatusChangeoutItem $item): array => [
                    'name' => $item->item_name,
                    'note' => $item->note,
                ])->values()->all(),
            ))->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function fleetDecisions(CarbonInterface $since): array
    {
        return ApparatusVehicleSwapRequest::query()
            ->with('decidedBy:id,name,display_name')
            ->whereIn('status', [ApparatusVehicleSwapRequest::STATUS_APPROVED_SWAP, ApparatusVehicleSwapRequest::STATUS_APPROVED_REPLACE])
            ->where('decided_at', '>=', $since)
            ->whereIn('apparatus_id', $this->rescueTrucks()->select('id'))
            ->orderByDesc('decided_at')->orderByDesc('id')
            ->limit(self::MAX_CHANGES)->get()
            ->map(function (ApparatusVehicleSwapRequest $swap): array {
                $decider = $swap->getRelationValue('decidedBy');
                $moment = $swap->getAttribute('decided_at');

                return $this->change(
                    'fleet_decision:'.$swap->getKey(),
                    'fleet_decision',
                    (string) ($swap->unit_designation ?: 'Rescue'),
                    $swap->assigned_vehicle_number,
                    $swap->reported_vehicle_number,
                    $moment instanceof CarbonInterface ? $moment : null,
                    $decider instanceof User ? $this->presenter->name($decider) : 'Fleet',
                    null,
                    [],
                );
            })->values()->all();
    }

    /**
     * @param  list<array{name: string, note: string|null}>  $leftBehind
     * @return array<string, mixed>
     */
    private function change(string $id, string $source, string $unit, ?string $from, ?string $to, ?CarbonInterface $at, string $by, ?string $note, array $leftBehind): array
    {
        return [
            'id' => $id,
            'source' => $source,
            'unit' => $unit,
            'from_vehicle_number' => $from,
            'to_vehicle_number' => $to,
            'at' => $this->presenter->iso($at),
            'by' => $by,
            'recent' => $at !== null && $at->greaterThanOrEqualTo(now()->subHours(self::RECENT_HOURS)),
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'left_behind' => $leftBehind,
        ];
    }

    /** @return Builder<Apparatus> */
    private function rescueTrucks(): Builder
    {
        return Apparatus::query()->whereRaw("LOWER(TRIM(type)) = 'rescue'");
    }

    /** A spare Rescue truck holds no unit, so it answers no "who is on what truck" question. */
    private function isPlainReserve(Apparatus $truck): bool
    {
        return ApparatusChangeoutPlanner::isReserveTruck($truck)
            || FleetVehicleCatalog::roleFor((string) $truck->getAttribute('status')) === FleetVehicleCatalog::ROLE_RESERVE
            || str_contains(strtolower((string) $truck->designation), 'reserve');
    }

    /** "R 44" and "R44" are the same unit on different records. */
    private function unitKey(string $unit): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $unit));
    }
}
