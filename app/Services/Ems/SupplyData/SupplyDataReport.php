<?php

declare(strict_types=1);

namespace App\Services\Ems\SupplyData;

use App\Models\Ems\SupplyItem;
use App\Support\HubTimezone;

/**
 * The one place every Supply Data figure is calculated (docs/ems/supply-data-contract.md). Controllers, the Admin
 * page, exports and the Captain 5 tab only present what this returns. It reads and never writes: no stock lock, no
 * workflow service, no table other than a SELECT.
 */
final class SupplyDataReport
{
    public const LABEL = 'Distributed from the supply room — not patient use';

    public const DEFINITIONS = [
        'requested' => 'Quantity asked for on counted requests. A request is counted unless it was cancelled, sent back, or replaced by its correction, so a corrected request is never counted twice. Yes/No answers and controlled items are not quantities.',
        'recorded_issued' => 'Quantity recorded as issued on the counted requests, in each request\'s own units, after corrections. It is what the request forms say, not what the stock ledger says left the room, and it adds different units together.',
        'distributed' => 'Net quantity that left the supply room for a unit: issued stock movements linked to a request, minus the correction movements that reverse them. An issuance that was fully reversed counts as zero. Shown in the item\'s count unit. This is not what crews used.',
        'distribution_events' => 'The number of distinct request-and-item pairs whose net distributed quantity is above zero. Used to rank items, because quantities of different items are not comparable.',
        'received' => 'Stock received from orders. Not part of distributed.',
        'adjusted' => 'Manual stock adjustments with no request. Corrections made on a request are netted into distributed instead.',
        'on_hand' => 'The ledger\'s current quantity for the item and how it was reached: counted, calculated since a count, estimate, or none (never counted).',
        'verified' => 'A number from a physical count by a person. A best-guess estimate is never verified.',
        'estimated' => 'The newest number for the item is a best-guess estimate loaded on 2026-11-01, not a physical count. It reads as an estimate until the item is counted.',
        'variance' => 'Counted quantity minus what the books expected at the moment of the count (the previous number plus every stock movement up to the count\'s cut-off). Shown only when the previous number was itself a physical count; otherwise left empty with the reason.',
        'days_of_supply' => 'On-hand quantity divided by the average distributed per day in the period. Shown only when the balance is not an estimate, the average is above zero and there were at least 3 distribution events. It estimates the pace of distribution, not use.',
        'not_counted_recently' => 'No physical count in the last '.StockPositions::STALE_COUNT_DAYS.' days, or never counted. Estimates do not count as counts.',
        'needs_reorder' => 'The reorder advisor recommends an order: the item\'s quantity is below its order level. A recommendation is not an order.',
        'minimum' => 'The item\'s order level: order when the quantity is fewer than this. The supply room tracks one level per item, so order_level is the same number.',
        'previous_period' => 'The same number of days immediately before the selected period, used for the comparison.',
        'time' => 'Dates and weeks are Eastern time. Weeks start on Sunday; months on the first.',
    ];

    public function __construct(
        private readonly DistributionQuery $distribution,
        private readonly RequestCohortQuery $requests,
        private readonly CountVarianceQuery $counts,
        private readonly StockPositions $stock,
        private readonly OverviewSections $sections,
        private readonly ItemReport $itemReport,
        private readonly UnitReport $unitReport,
    ) {}

    /** @return array<string, mixed> */
    public function overview(SupplyDataFilters $filters): array
    {
        $buckets = $filters->buckets();
        $current = $this->distribution->byItem($filters->fromUtc(), $filters->toUtc(), $filters);
        $previous = $this->distribution->byItem($filters->previousFromUtc(), $filters->previousToUtc(), $filters);
        $byUnit = $this->distribution->byUnit($filters->fromUtc(), $filters->toUtc(), $filters);
        $distributionBuckets = $this->distribution->byBucket($filters->fromUtc(), $filters->toUtc(), $filters, $buckets);
        $requestBuckets = $this->requests->byBucket($filters, $buckets);
        $unitRequests = $this->requests->byUnit($filters);
        $unitQuantities = $this->requests->quantitiesByUnit($filters);
        $hours = $this->requests->hoursToReconcile($filters);
        $integrity = $this->distribution->integrity($filters->fromUtc(), $filters->toUtc());
        // Stock rows are room-wide, so their pace must be the department's even when a unit is chosen.
        $room = $filters->unit === null ? $current : $this->distribution->byItem($filters->fromUtc(), $filters->toUtc(), $filters->withoutUnit());
        $stock = $this->stock->build($filters, $room->keyBy('item_id')->all());
        $summary = $this->counts->summary($filters->fromUtc(), $filters->toUtc(), $filters->item, $filters->category);
        $largest = $this->counts->largest($filters->fromUtc(), $filters->toUtc(), $filters->item, $filters->category, 25);

        $events = (int) $current->sum('events');
        $previousEvents = (int) $previous->sum('events');
        $units = $this->sections->units($unitRequests, $byUnit);
        $fulfillment = $this->sections->fulfillment($unitRequests, $unitQuantities, $hours);

        return [
            'label' => self::LABEL,
            'generated_at' => $filters->now->toIso8601String(),
            'timezone' => HubTimezone::NAME,
            'period' => $filters->periodSummary(),
            'filters' => $filters->filterSummary(),
            'scope_notes' => $this->scopeNotes($filters),
            'attention' => $stock['attention'],
            'headline' => [
                'distribution_events' => $events,
                'previous_distribution_events' => $previousEvents,
                'change_pct' => Support::changePct($events, $previousEvents),
                'items_distributed' => $current->count(),
                'units_supplied' => $byUnit->count(),
                'requests_submitted' => $fulfillment['submitted'],
                'requests_reconciled' => $fulfillment['reconciled'],
            ],
            'trend' => Support::fillBuckets($filters, $buckets, $this->sections->trendValues($distributionBuckets, $requestBuckets), ['distribution_events', 'requests']),
            'top_items' => $this->sections->topItems($current, $previous, $filters->limit),
            'units' => $units,
            'stock' => $stock['rows'],
            'fulfillment' => $fulfillment,
            'count_accuracy' => $this->sections->countAccuracy($summary, $largest, count($stock['rows'])),
            'data_quality' => $this->sections->dataQuality($stock, $integrity, $unitRequests, $events, $previousEvents),
            'definitions' => self::DEFINITIONS,
            'filter_options' => ['categories' => $stock['categories']],
        ];
    }

    /** @return array<string, mixed> */
    public function item(SupplyItem $item, SupplyDataFilters $filters): array
    {
        return $this->itemReport->build($item, $filters);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException when the unit key matches no request in the data
     */
    public function unit(string $unitKey, SupplyDataFilters $filters): array
    {
        return $this->unitReport->build($unitKey, $filters);
    }

    /** @return list<string> */
    private function scopeNotes(SupplyDataFilters $filters): array
    {
        $notes = [];
        if ($filters->item !== null || $filters->category !== null) {
            $notes[] = 'The item and category filters narrow supplies, stock and counts. Request totals and fulfillment follow the period and unit only.';
        }
        if ($filters->unit !== null) {
            $notes[] = 'The unit filter narrows requests, distribution and the trend. Stock, days of supply, average per day and counts are for the whole supply room.';
        }

        return $notes;
    }
}
