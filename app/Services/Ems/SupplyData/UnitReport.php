<?php

declare(strict_types=1);

namespace App\Services\Ems\SupplyData;

use App\Support\HubTimezone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use stdClass;

/** One unit (a truck, or Detail / Special Event): what it asked for, what it received and how it trends. */
final class UnitReport
{
    public const REQUEST_LIMIT = 50;

    public function __construct(
        private readonly DistributionQuery $distribution,
        private readonly RequestCohortQuery $requests,
        private readonly OverviewSections $sections,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ModelNotFoundException when the unit key names a truck that is not on file
     */
    public function build(string $unitKey, SupplyDataFilters $filters): array
    {
        $fallbackLabel = $this->assertKnown($unitKey);
        $f = $filters->forUnit($unitKey);
        $buckets = $f->buckets();

        $requestRows = $this->requests->byUnit($f);
        $quantities = $this->requests->quantitiesByUnit($f);
        $received = $this->distribution->byItem($f->fromUtc(), $f->toUtc(), $f);
        $distributionBuckets = $this->distribution->byBucket($f->fromUtc(), $f->toUtc(), $f, $buckets);
        $requestBuckets = $this->requests->byBucket($f, $buckets);
        $recent = $this->requests->recent($f, self::REQUEST_LIMIT);
        $topRequested = $this->requests->topRequested($f, $f->limit);

        $labels = $requestRows->sortByDesc('last_submitted_at')->pluck('target_label')->filter()->unique()->values()->all();
        $label = Support::unitLabel($unitKey, $labels[0] ?? $fallbackLabel);

        return [
            'label' => SupplyDataReport::LABEL,
            'generated_at' => $f->now->toIso8601String(),
            'timezone' => HubTimezone::NAME,
            'unit' => ['unit_key' => $unitKey, 'apparatus_id' => UnitKey::apparatusId($unitKey), 'label' => $label, 'labels' => $labels, 'kind' => UnitKey::kind($unitKey)],
            'period' => $f->periodSummary(),
            'filters' => $f->filterSummary(),
            'summary' => [
                'requests' => (int) $requestRows->sum('counted'),
                'requests_reconciled' => (int) $requestRows->sum('reconciled'),
                'qty_requested' => (int) $quantities->sum('qty_requested'),
                'distributed_events' => (int) $received->sum('events'),
                'items_distributed' => $received->count(),
                'last_replenished_at' => Support::at($received->max('last_at')),
            ],
            'top_requested' => $topRequested->map(fn (stdClass $row): array => [
                'item_key' => $row->catalog_item_id !== null ? 'catalog:'.$row->catalog_item_id.'|'.($row->unit ?? '') : 'line:'.$row->bag_key.'|'.$row->item_key.'|'.($row->unit ?? ''),
                'label' => (string) $row->label, 'unit' => $row->unit, 'qty_requested' => (int) $row->qty_requested, 'requests' => (int) $row->requests,
            ])->values()->all(),
            'top_distributed' => $received->sortBy([['events', 'desc'], ['net_base', 'desc'], ['name', 'asc'], ['item_id', 'asc']])->take($f->limit)->map(function (stdClass $row): array {
                $units = Support::units($row->count_unit, $row->package_unit, $row->units_per_package);

                return [
                    'item_id' => (int) $row->item_id, 'name' => (string) $row->name, 'count_unit' => $units['unit'],
                    'distributed' => Support::amount($row->net_base, $units['per']) ?? 0, 'distribution_events' => (int) $row->events,
                ];
            })->values()->all(),
            'trend' => Support::fillBuckets($f, $buckets, $this->sections->trendValues($distributionBuckets, $requestBuckets), ['requests', 'distribution_events']),
            'requests' => $recent->map(fn (stdClass $row): array => [
                'number' => $row->number, 'uuid' => $row->uuid, 'submitted_at' => Support::at($row->submitted_at), 'status' => $row->status,
                'lines' => $row->lines, 'qty_requested' => $row->qty_requested, 'reconciled_at' => Support::at($row->reconciled_at),
            ])->values()->all(),
        ];
    }

    /**
     * Detail and "unassigned" always exist; a truck must be on file. Returns the truck's name as a last-resort label.
     */
    private function assertKnown(string $unitKey): ?string
    {
        $apparatusId = UnitKey::apparatusId($unitKey);
        if ($apparatusId === null) {
            if (! in_array($unitKey, [UnitKey::DETAIL, UnitKey::UNASSIGNED], true)) {
                throw new ModelNotFoundException;
            }

            return null;
        }
        $truck = DB::table('apparatuses')->where('id', $apparatusId)->first(['name', 'designation', 'unit_id']);
        if ($truck === null) {
            throw new ModelNotFoundException;
        }

        return $truck->name ?: ($truck->designation ?: $truck->unit_id);
    }
}
