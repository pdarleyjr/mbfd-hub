<?php

declare(strict_types=1);

namespace Tests\Feature\Ems\Admin\Fakes;

use App\Models\Ems\SupplyItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A stand-in for App\Services\Ems\SupplyData\SupplyDataReport that returns the shapes in
 * docs/ems/supply-data-contract.md, so the Medical Supply Data page can be tested without any stock history.
 * It records every call so a test can prove what the page asked for, and what it never asked for.
 */
final class FakeSupplyDataReport
{
    /** @var list<array{method: string, filters: object, args: array<int, mixed>}> */
    public array $calls = [];

    /** @param array<string, mixed> $overview */
    public function __construct(private array $overview = [])
    {
        $this->overview = $overview === [] ? self::overviewFixture() : $overview;
    }

    /** @return array<string, mixed> */
    public function overview(object $filters): array
    {
        $this->calls[] = ['method' => 'overview', 'filters' => $filters, 'args' => []];

        return $this->overview;
    }

    /** @return array<string, mixed> */
    public function item(SupplyItem $item, object $filters): array
    {
        $this->calls[] = ['method' => 'item', 'filters' => $filters, 'args' => [$item->getKey()]];

        return self::itemFixture((int) $item->getKey(), (string) $item->name);
    }

    /** @return array<string, mixed> */
    public function unit(string $unitKey, object $filters): array
    {
        $this->calls[] = ['method' => 'unit', 'filters' => $filters, 'args' => [$unitKey]];
        if ($unitKey === 'unit:999') {
            throw new ModelNotFoundException;
        }

        return self::unitFixture($unitKey);
    }

    /** @return list<array{method: string, filters: object, args: array<int, mixed>}> */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method));
    }

    /** @return array<string, mixed> */
    public static function overviewFixture(): array
    {
        return [
            'label' => 'Distributed from the supply room — not patient use',
            'generated_at' => '2026-11-02T14:00:00-05:00',
            'timezone' => 'America/New_York',
            'period' => ['key' => '30d', 'from' => '2026-10-04', 'to' => '2026-11-02', 'days' => 30, 'previous' => ['from' => '2026-09-04', 'to' => '2026-10-03']],
            'filters' => ['unit' => null, 'item' => null, 'category' => null],
            'scope_notes' => [],
            'attention' => ['below_minimum' => 3, 'zero_stock' => 0, 'estimated' => 44, 'not_counted_recently' => 50, 'needs_reorder' => 4],
            'headline' => ['distribution_events' => 12, 'previous_distribution_events' => 9, 'change_pct' => 33.3, 'items_distributed' => 7, 'units_supplied' => 4, 'requests_submitted' => 6, 'requests_reconciled' => 5],
            'trend' => [
                ['week_start' => '2026-10-04', 'distribution_events' => 3, 'requests' => 2],
                ['week_start' => '2026-10-11', 'distribution_events' => 0, 'requests' => 1],
                ['week_start' => '2026-10-18', 'distribution_events' => 4, 'requests' => 2],
                ['week_start' => '2026-10-25', 'distribution_events' => 5, 'requests' => 1],
            ],
            'top_items' => [
                ['item_id' => 12, 'name' => 'Gloves, nitrile (L)', 'count_unit' => 'box', 'category' => 'PPE', 'distribution_events' => 5, 'distributed' => 9, 'units_supplied' => 3, 'last_distributed_at' => '2026-10-30T10:12:00-04:00', 'previous_distribution_events' => 2, 'direction' => 'up'],
                ['item_id' => 13, 'name' => 'Tape 1 inch', 'count_unit' => 'each', 'category' => 'Wound care', 'distribution_events' => 2, 'distributed' => 6, 'units_supplied' => 1, 'last_distributed_at' => '2026-10-20T09:00:00-04:00', 'previous_distribution_events' => 0, 'direction' => 'new'],
            ],
            'units' => [
                ['unit_key' => 'unit:45', 'apparatus_id' => 45, 'kind' => 'unit', 'label' => 'RESCUE R2', 'requests' => 2, 'distribution_events' => 6, 'items_distributed' => 5, 'last_replenished_at' => '2026-10-30T10:12:00-04:00'],
                ['unit_key' => 'detail', 'apparatus_id' => null, 'kind' => 'detail', 'label' => 'Detail / Special Event', 'requests' => 1, 'distribution_events' => 0, 'items_distributed' => 0, 'last_replenished_at' => null],
            ],
            'stock' => [
                self::stockRow(12, 'Gloves, nitrile (L)', 'counted', 14, 'ok'),
                self::stockRow(13, 'Tape 1 inch', 'estimate', 80, 'near_minimum', daysOfSupply: null),
                self::stockRow(14, 'Gauze 4x4', 'calculated', 3, 'below_minimum', daysOfSupply: 6.5, reorder: true),
                self::stockRow(15, 'Stethoscope', 'none', null, 'unknown', daysOfSupply: null),
            ],
            'fulfillment' => ['submitted' => 6, 'acknowledged' => 5, 'rejected' => 1, 'corrected' => 0, 'reconciled' => 5, 'open' => 1, 'completed_other_workflow' => 0, 'all_submissions' => 7, 'median_hours_to_reconcile' => 70.5, 'qty_requested' => 40, 'qty_distributed' => 36],
            'count_accuracy' => ['counted_lines' => 12, 'comparable_counts' => 0, 'exact_matches' => null, 'items_compared' => 0, 'coverage_pct' => null, 'items' => [], 'note' => 'No physical count has a verified baseline to compare against yet.'],
            'data_quality' => [
                'unattributed_movements' => 0, 'movements_on_uncounted_requests' => 0, 'estimated_items' => 44, 'uncounted_items' => 2, 'unresolved_conversions' => 0, 'negative_balances' => 0,
                'items_without_minimum' => 1, 'requests_without_unit' => 0, 'trucks_with_more_than_one_unit_name' => 0, 'estimates_far_above_order_level' => [],
                'insufficient_data' => [], 'notes' => ['44 balances are best-guess estimates, not physical counts.'],
            ],
            'definitions' => ['distributed' => 'Net quantity that left the supply room for a unit.', 'days_of_supply' => 'Pace of distribution, not use.'],
            'filter_options' => ['categories' => ['PPE', 'Wound care']],
        ];
    }

    /** @return array<string, mixed> */
    public static function stockRow(int $id, string $name, string $basis, int|float|null $onHand, string $status, ?float $daysOfSupply = 46.7, bool $reorder = false): array
    {
        return [
            'item_id' => $id, 'name' => $name, 'category' => 'PPE', 'location' => 'A2', 'count_unit' => 'box', 'on_hand' => $onHand, 'basis' => $basis,
            'baseline_origin' => $basis === 'estimate' ? 'estimate' : null, 'last_counted_at' => $basis === 'counted' ? '2026-10-27T09:00:00-04:00' : null,
            'deductions_since_count' => $basis === 'counted' ? 3 : null, 'minimum' => 10, 'order_level' => 10, 'status' => $status, 'needs_reorder' => $reorder,
            'recommended_order' => $reorder ? 6 : null, 'recommended_order_unit' => $reorder ? 'case' : null, 'recommended_order_basis' => null,
            'avg_daily_distributed' => 0.3, 'days_of_supply' => $daysOfSupply, 'estimated' => $basis === 'estimate', 'units_resolved' => true,
        ];
    }

    /** @return array<string, mixed> */
    public static function itemFixture(int $id, string $name): array
    {
        return [
            'label' => 'Distributed from the supply room — not patient use',
            'item' => ['item_id' => $id, 'name' => $name, 'category' => 'PPE', 'location' => 'A2', 'count_unit' => 'box', 'package_unit' => 'case', 'package_size' => 10, 'controlled' => false, 'active' => true, 'units_resolved' => true],
            'balance' => ['on_hand' => 14, 'basis' => 'estimate', 'estimated' => true, 'last_counted_at' => null, 'deductions_since_count' => null, 'minimum' => 10, 'order_level' => 10, 'status' => 'ok', 'needs_reorder' => false, 'recommended_order' => null, 'recommended_order_unit' => null, 'days_of_supply' => null],
            'period' => ['key' => '30d', 'from' => '2026-10-04', 'to' => '2026-11-02', 'days' => 30, 'previous' => ['from' => '2026-09-04', 'to' => '2026-10-03']],
            'distribution' => ['distributed' => 9, 'distribution_events' => 5, 'previous_distribution_events' => 2, 'change_pct' => 150.0, 'avg_weekly' => 2.1, 'median_weekly' => 2, 'last_distributed_at' => '2026-10-30T10:12:00-04:00'],
            'trend' => [['week_start' => '2026-10-25', 'distributed' => 3, 'distribution_events' => 2]],
            'units' => [['unit_key' => 'unit:45', 'label' => 'RESCUE R2', 'distribution_events' => 3, 'distributed' => 5, 'share_pct' => 55.6]],
            'replenishment' => [['occurred_at' => '2026-10-15T08:00:00-04:00', 'type' => 'received', 'quantity' => 10, 'reason' => 'Order MIR-ORD-7']],
            'counts' => [['counted_at' => '2026-10-27T09:00:00-04:00', 'verified' => null, 'estimated' => 14, 'expected' => null, 'variance' => null, 'variance_pct' => null, 'basis_note' => null]],
            'movements' => [['id' => 99, 'occurred_at' => '2026-10-30T10:12:00-04:00', 'type' => 'issued', 'quantity' => -2, 'unit_label' => 'RESCUE R2', 'request_number' => 'MIR-000004', 'reason' => null, 'corrects_movement_id' => null, 'reversed' => false]],
            'movements_truncated' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function unitFixture(string $unitKey): array
    {
        return [
            'label' => 'Distributed from the supply room — not patient use',
            'unit' => ['unit_key' => $unitKey, 'apparatus_id' => 45, 'label' => 'RESCUE R2', 'labels' => ['RESCUE R2'], 'kind' => 'unit'],
            'period' => ['key' => '30d', 'from' => '2026-10-04', 'to' => '2026-11-02', 'days' => 30, 'previous' => ['from' => '2026-09-04', 'to' => '2026-10-03']],
            'summary' => ['requests' => 2, 'requests_reconciled' => 2, 'qty_requested' => 12, 'distributed_events' => 6, 'items_distributed' => 5, 'last_replenished_at' => '2026-10-30T10:12:00-04:00'],
            'top_requested' => [['item_key' => 'catalog:1|box', 'label' => 'Gauze 4x4', 'unit' => 'box', 'qty_requested' => 4, 'requests' => 2]],
            'top_distributed' => [['item_id' => 12, 'name' => 'Gloves, nitrile (L)', 'count_unit' => 'box', 'distributed' => 5, 'distribution_events' => 3]],
            'trend' => [['week_start' => '2026-10-25', 'requests' => 1, 'distribution_events' => 3]],
            'requests' => [['number' => 'MIR-000004', 'uuid' => '5f0c1f0e-6d0e-4c33-8f43-1d1a5c3b9a10', 'submitted_at' => '2026-10-28T08:00:00-04:00', 'status' => 'reconciled', 'lines' => 6, 'qty_requested' => 12, 'reconciled_at' => '2026-10-30T10:12:00-04:00']],
        ];
    }
}
