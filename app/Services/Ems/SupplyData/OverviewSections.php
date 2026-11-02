<?php

declare(strict_types=1);

namespace App\Services\Ems\SupplyData;

use Illuminate\Support\Collection;
use stdClass;

/** Turns query rows into the overview's sections. Pure: no queries here. */
final class OverviewSections
{
    /**
     * @param  Collection<int, stdClass>  $current  this period, per item
     * @param  Collection<int, stdClass>  $previous  the previous period, per item
     * @return list<array<string, mixed>>
     */
    public function topItems(Collection $current, Collection $previous, int $limit): array
    {
        $before = $previous->keyBy('item_id');

        return $current
            ->sortBy([['events', 'desc'], ['net_base', 'desc'], ['last_at', 'desc'], ['name', 'asc'], ['item_id', 'asc']])
            ->take($limit)
            ->map(function (stdClass $row) use ($before): array {
                $units = Support::units($row->count_unit, $row->package_unit, $row->units_per_package);
                $previousEvents = (int) ($before->get($row->item_id)->events ?? 0);

                return [
                    'item_id' => (int) $row->item_id,
                    'name' => (string) $row->name,
                    'count_unit' => $units['unit'],
                    'category' => $row->category,
                    'distribution_events' => (int) $row->events,
                    'distributed' => Support::amount($row->net_base, $units['per']),
                    'units_supplied' => (int) $row->units,
                    'last_distributed_at' => Support::at($row->last_at),
                    'previous_distribution_events' => $previousEvents,
                    'direction' => Support::direction((int) $row->events, $previousEvents),
                ];
            })->values()->all();
    }

    /**
     * Request counts per unit joined with distribution per unit.
     *
     * @param  Collection<int, stdClass>  $requestRows  per unit and label
     * @param  Collection<int, stdClass>  $distributionRows  per unit
     * @return list<array<string, mixed>>
     */
    public function units(Collection $requestRows, Collection $distributionRows): array
    {
        $units = [];
        foreach ($requestRows as $row) {
            $key = UnitKey::of($row->apparatus_id === null ? null : (int) $row->apparatus_id, $row->target_kind);
            $units[$key] ??= $this->blank($key, $row->apparatus_id);
            $units[$key]['requests'] += (int) $row->counted;
            $units[$key]['_labels'][(string) $row->target_label] = max($units[$key]['_labels'][(string) $row->target_label] ?? '', (string) ($row->last_submitted_at ?? ''));
        }
        foreach ($distributionRows as $row) {
            $key = UnitKey::of($row->apparatus_id === null ? null : (int) $row->apparatus_id, $row->target_kind);
            $units[$key] ??= $this->blank($key, $row->apparatus_id);
            $units[$key]['distribution_events'] = (int) $row->events;
            $units[$key]['items_distributed'] = (int) $row->items;
            $units[$key]['last_replenished_at'] = Support::at($row->last_at);
            $units[$key]['_labels'] += $units[$key]['_labels'] === [] ? [(string) $row->label => ''] : [];
        }

        $rows = array_map(function (array $unit): array {
            arsort($unit['_labels']);
            $labels = array_values(array_filter(array_keys($unit['_labels']), fn (string $label): bool => trim($label) !== ''));
            $unit['label'] = Support::unitLabel($unit['unit_key'], $labels[0] ?? null);
            $unit['labels'] = $labels;
            unset($unit['_labels']);

            return $unit;
        }, array_values($units));
        usort($rows, fn (array $a, array $b): int => [$b['distribution_events'], $b['requests'], mb_strtolower($a['label'])] <=> [$a['distribution_events'], $a['requests'], mb_strtolower($b['label'])]);

        return $rows;
    }

    /**
     * @param  Collection<int, stdClass>  $requestRows
     * @param  Collection<int, stdClass>  $quantityRows
     * @param  list<float>  $hours
     * @return array<string, mixed>
     */
    public function fulfillment(Collection $requestRows, Collection $quantityRows, array $hours): array
    {
        $counted = (int) $requestRows->sum('counted');
        $reconciled = (int) $requestRows->sum('reconciled');
        $open = (int) $requestRows->sum('open_count');
        $median = Support::median($hours);

        return [
            'submitted' => $counted,
            'acknowledged' => (int) $requestRows->sum('acknowledged'),
            'rejected' => (int) $requestRows->sum('rejected'),
            'corrected' => (int) $requestRows->sum('corrected'),
            'reconciled' => $reconciled,
            'open' => $open,
            'completed_other_workflow' => max(0, $counted - $reconciled - $open),
            'all_submissions' => (int) $requestRows->sum('submitted'),
            'median_hours_to_reconcile' => $median === null ? null : round($median, 1),
            'qty_requested' => (int) $quantityRows->sum('qty_requested'),
            'qty_distributed' => (int) $quantityRows->sum('qty_distributed'),
        ];
    }

    /**
     * @param  array<int, array{events: int, net_base: int}>  $distribution
     * @param  array<int, int>  $requests
     * @return array<int, array<string, int>>
     */
    public function trendValues(array $distribution, array $requests): array
    {
        $values = [];
        foreach ($distribution as $index => $row) {
            $values[$index]['distribution_events'] = $row['events'];
        }
        foreach ($requests as $index => $count) {
            $values[$index]['requests'] = $count;
        }

        return $values;
    }

    /**
     * @param  array{lines: int, comparable: int, exact: int, items: int}  $summary
     * @param  list<array<string, mixed>>  $largest
     * @return array<string, mixed>
     */
    public function countAccuracy(array $summary, array $largest, int $trackedItems): array
    {
        $note = match (true) {
            $summary['lines'] === 0 => 'No physical count was finished in this period.',
            $summary['comparable'] === 0 => 'No physical count has a verified baseline to compare against yet.',
            default => null,
        };

        return [
            'counted_lines' => $summary['lines'],
            'comparable_counts' => $summary['comparable'],
            'exact_matches' => $summary['comparable'] === 0 ? null : $summary['exact'],
            'items_compared' => $summary['items'],
            'coverage_pct' => $summary['comparable'] === 0 || $trackedItems === 0 ? null : round($summary['items'] / $trackedItems * 100, 1),
            'items' => array_map(fn (array $row): array => [
                'item_id' => $row['item_id'], 'name' => $row['name'], 'counted_at' => $row['counted_at']->toIso8601String(),
                'counted' => $row['counted'], 'expected' => $row['expected'], 'variance' => $row['variance'], 'variance_pct' => $row['variance_pct'],
            ], $largest),
            'note' => $note,
        ];
    }

    /**
     * @param  array{rows: list<array<string, mixed>>, quality: array<string, mixed>}  $stock
     * @param  Collection<int, stdClass>  $requestRows
     * @return array<string, mixed>
     */
    public function dataQuality(array $stock, stdClass $integrity, Collection $requestRows, int $events, int $previousEvents): array
    {
        $estimated = count(array_filter($stock['rows'], fn (array $row): bool => $row['estimated']));
        $unattributed = (int) ($integrity->unattributed ?? 0);
        $onUncounted = (int) ($integrity->on_uncounted_requests ?? 0);
        $unassignedRequests = (int) $requestRows->filter(fn (stdClass $row): bool => $row->apparatus_id === null && $row->target_kind !== 'detail_event')->sum('counted');
        $identityChanges = $requestRows->filter(fn (stdClass $row): bool => $row->apparatus_id !== null)->groupBy('apparatus_id')->filter(fn (Collection $group): bool => $group->pluck('target_label')->unique()->count() > 1)->count();
        $quality = $stock['quality'];

        $notes = [];
        $insufficient = [];
        if ($estimated > 0) {
            $notes[] = "{$estimated} balances are best-guess estimates, not physical counts. Some were entered by reading a count-sheet number as cases or boxes and can read far too high until the item is counted.";
        }
        if ($quality['uncounted_items'] > 0) {
            $notes[] = "{$quality['uncounted_items']} items have never been counted, so their quantity is unknown.";
        }
        if ($quality['unresolved_conversions'] > 0) {
            $notes[] = "{$quality['unresolved_conversions']} items are counted by the box or case with no stated size; their quantities are shown in single units.";
        }
        if ($unattributed > 0) {
            $notes[] = "{$unattributed} stock issuances in this period are not linked to a request and are not attributed to a unit.";
        }
        if ($onUncounted > 0) {
            $notes[] = "{$onUncounted} stock issuances are linked to a request that was cancelled, sent back or replaced. They are included in distributed because the stock did leave the room.";
        }
        if ($identityChanges > 0) {
            $notes[] = "{$identityChanges} trucks carry more than one unit name in this period (a vehicle changeout moved the unit). Requests stay with the truck they were submitted for.";
        }
        if ($events === 0) {
            $insufficient[] = ['section' => 'distribution', 'message' => 'Nothing was distributed from the supply room in this period. This is a real zero, not missing data.'];
        } elseif ($events < 3) {
            $insufficient[] = ['section' => 'trend', 'message' => 'Fewer than 3 distribution events in this period, too few to call a trend.'];
        }
        if ($previousEvents === 0 && $events > 0) {
            $insufficient[] = ['section' => 'comparison', 'message' => 'Nothing was distributed in the previous period, so there is no change to report.'];
        }

        return [
            'unattributed_movements' => $unattributed,
            'movements_on_uncounted_requests' => $onUncounted,
            'estimated_items' => $estimated,
            'uncounted_items' => $quality['uncounted_items'],
            'unresolved_conversions' => $quality['unresolved_conversions'],
            'negative_balances' => $quality['negative_balances'],
            'items_without_minimum' => $quality['items_without_minimum'],
            'requests_without_unit' => $unassignedRequests,
            'trucks_with_more_than_one_unit_name' => $identityChanges,
            'estimates_far_above_order_level' => $quality['estimates_far_above_order_level'],
            'insufficient_data' => $insufficient,
            'notes' => $notes,
        ];
    }

    /** @return array<string, mixed> */
    private function blank(string $key, mixed $apparatusId): array
    {
        return [
            'unit_key' => $key, 'apparatus_id' => $apparatusId === null ? null : (int) $apparatusId, 'kind' => UnitKey::kind($key),
            'requests' => 0, 'distribution_events' => 0, 'items_distributed' => 0, 'last_replenished_at' => null, '_labels' => [],
        ];
    }
}
