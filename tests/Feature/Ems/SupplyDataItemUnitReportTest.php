<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\CatalogItem;
use App\Models\Ems\InventoryRequest;
use App\Models\Ems\StockMovement;
use App\Services\Ems\SupplyData\CountVarianceQuery;
use App\Services\Ems\SupplyData\SupplyDataFilters;
use App\Services\Ems\SupplyData\SupplyDataReport;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ems\Concerns\BuildsSupplyDataWorld;
use Tests\TestCase;

/** The item page and the unit page. Today is Monday, November 2, 2026. */
final class SupplyDataItemUnitReportTest extends TestCase
{
    use BuildsSupplyDataWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSupplyDataClock();
    }

    /** @param array<string, mixed> $input */
    private function filters(array $input = []): SupplyDataFilters
    {
        return SupplyDataFilters::fromArray($input);
    }

    public function test_the_item_page_tells_the_story_of_one_supply(): void
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $e1 = $this->sdApparatus('Engine 1', 'E1');
        $gloves = $this->sdItem('Gloves', ['threshold_qty_base' => 30]);
        $this->sdCount('2026-10-01 08:00:00', [[$gloves, 100]], 0);
        $one = $this->sdRequest($r2, InventoryRequest::RECONCILED, '2026-10-06 08:00:00', [['Gloves', 5, 5]]);
        $two = $this->sdRequest($e1, InventoryRequest::RECONCILED, '2026-10-13 08:00:00', [['Gloves', 3, 3]]);
        $three = $this->sdRequest(null, InventoryRequest::RECONCILED, '2026-10-14 08:00:00', [['Gloves', 2, 2]]);
        $four = $this->sdRequest($r2, InventoryRequest::REVIEWED, '2026-10-20 08:00:00', [['Gloves', 4, 0]]);
        $this->sdIssue($gloves, 5, $one, '2026-10-06 09:00:00');
        $this->sdIssue($gloves, 3, $two, '2026-10-13 09:00:00');
        $this->sdIssue($gloves, 2, $three, '2026-10-14 09:00:00');
        $reversed = $this->sdIssue($gloves, 4, $four, '2026-10-20 09:00:00');
        $this->sdReverse($reversed, $four, '2026-10-21 09:00:00');
        $this->sdMovement($gloves, StockMovement::RECEIVED, 20, null, '2026-10-25 09:00:00', ['reason' => 'Order received']);
        $this->sdMovement($gloves, StockMovement::ADJUSTED, -1, null, '2026-10-26 09:00:00', ['reason' => 'Dropped one']);

        $page = app(SupplyDataReport::class)->item($gloves, $this->filters());

        $this->assertSame('Gloves', $page['item']['name']);
        $this->assertEquals(['on_hand' => 109, 'basis' => 'calculated', 'status' => 'ok', 'minimum' => 30], array_intersect_key($page['balance'], array_flip(['on_hand', 'basis', 'status', 'minimum'])));
        $this->assertNotNull($page['balance']['last_counted_at']);
        $this->assertSame(10, $page['balance']['deductions_since_count'], '5 + 3 + 2 taken for requests; the reversed 4 nets out');
        $this->assertSame(10, $page['distribution']['distributed']);
        $this->assertSame(3, $page['distribution']['distribution_events']);
        $this->assertSame(2.3, $page['distribution']['avg_weekly']);
        $this->assertSame(2.5, $page['distribution']['median_weekly'], 'whole weeks only: 5, 5, 0, 0 (the clipped week of Nov 1 is left out)');
        $this->assertSame([5, 5, 0, 0, 0], array_column($page['trend'], 'distributed'));
        $this->assertSame([1, 2, 0, 0, 0], array_column($page['trend'], 'distribution_events'));
        $this->assertSame(['2026-10-04', '2026-10-11', '2026-10-18', '2026-10-25', '2026-11-01'], array_column($page['trend'], 'week_start'));

        $units = collect($page['units'])->keyBy('label');
        $this->assertSame([50.0, 30.0, 20.0], [$units['Rescue 2']['share_pct'], $units['Engine 1']['share_pct'], $units['Detail / Special Event']['share_pct']]);
        $this->assertSame(['received', 'adjusted'], array_column(array_reverse($page['replenishment']), 'type'), 'receipts and manual adjustments only; a correction on a request is not replenishment');
        $this->assertSame(20, $page['replenishment'][1]['quantity']);
        $this->assertCount(1, $page['counts']);
        $this->assertSame(CountVarianceQuery::NOTE_FIRST, $page['counts'][0]['basis_note']);
        $this->assertFalse($page['movements_truncated']);

        $movements = collect($page['movements']);
        $this->assertSame(7, $movements->count());
        $this->assertTrue($movements->firstWhere('id', $reversed->id)['reversed']);
        $this->assertFalse($movements->firstWhere('request_number', $one->number)['reversed']);
        $this->assertSame('Dropped one', $movements->first()['reason'], 'newest first');
        $this->assertSame($one->number, $movements->firstWhere('id', 1)['request_number']);
        $this->assertArrayNotHasKey('actor_name', $movements->first(), 'no member or staff names on this page');
    }

    public function test_the_item_page_for_an_estimate_labels_it_and_has_no_pace(): void
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $guess = $this->sdItem('Guessed', ['threshold_qty_base' => 5]);
        $this->sdEstimate('2026-11-01 12:00:00', [[$guess, 50]]);
        foreach ([10, 11, 12] as $day) {
            $request = $this->sdRequest($r2, InventoryRequest::RECONCILED, "2026-10-{$day} 08:00:00", [['x', 1, 1]]);
            $this->sdIssue($guess, 1, $request, "2026-10-{$day} 09:00:00");
        }

        $page = app(SupplyDataReport::class)->item($guess, $this->filters());

        $this->assertSame(['estimate', true, null, 47], [$page['balance']['basis'], $page['balance']['estimated'], $page['balance']['days_of_supply'], $page['balance']['on_hand']]);
        $this->assertNull($page['balance']['last_counted_at']);
        $this->assertSame(50, $page['counts'][0]['estimated']);
        $this->assertNull($page['counts'][0]['verified']);
        $this->assertSame(CountVarianceQuery::NOTE_ESTIMATE_ROW, $page['counts'][0]['basis_note']);
        $this->assertSame(3, $page['distribution']['distribution_events']);
    }

    public function test_an_inactive_item_keeps_its_history_but_has_no_balance(): void
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $old = $this->sdItem('Retired item', ['active' => false]);
        $request = $this->sdRequest($r2, InventoryRequest::RECONCILED, '2026-10-10 08:00:00', [['x', 1, 1]]);
        $this->sdIssue($old, 2, $request, '2026-10-10 09:00:00');

        $page = app(SupplyDataReport::class)->item($old, $this->filters());

        $this->assertFalse($page['item']['active']);
        $this->assertSame(2, $page['distribution']['distributed']);
        $this->assertSame(['none', null, 'unknown'], [$page['balance']['basis'], $page['balance']['on_hand'], $page['balance']['status']]);
    }

    public function test_the_item_page_is_in_count_units_and_can_be_narrowed_to_one_unit(): void
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $e1 = $this->sdApparatus('Engine 1', 'E1');
        $box = $this->sdItem('Boxed gauze', ['count_unit' => 'box', 'package_unit' => 'box', 'units_per_package' => 100]);
        $forR2 = $this->sdRequest($r2, InventoryRequest::RECONCILED, '2026-10-10 08:00:00', [['x', 1, 1]]);
        $forE1 = $this->sdRequest($e1, InventoryRequest::RECONCILED, '2026-10-11 08:00:00', [['x', 1, 1]]);
        $this->sdIssue($box, 250, $forR2, '2026-10-10 09:00:00');
        $this->sdIssue($box, 100, $forE1, '2026-10-11 09:00:00');

        $all = app(SupplyDataReport::class)->item($box, $this->filters());
        $narrow = app(SupplyDataReport::class)->item($box, $this->filters(['unit' => 'unit:'.$e1->id]));

        $this->assertSame([3.5, 'box'], [$all['distribution']['distributed'], $all['item']['count_unit']]);
        $this->assertSame(-2.5, $all['movements'][1]['quantity']);
        $this->assertSame(1, $narrow['distribution']['distributed']);
        $this->assertCount(1, $narrow['units']);
        $this->assertCount(1, $narrow['movements']);
    }

    public function test_the_item_page_says_when_its_movements_were_cut(): void
    {
        $item = $this->sdItem('Busy item');
        for ($i = 0; $i < 55; $i++) {
            $this->sdMovement($item, StockMovement::RECEIVED, 1, null, '2026-10-10 09:00:00');
        }

        $page = app(SupplyDataReport::class)->item($item, $this->filters());

        $this->assertCount(50, $page['movements']);
        $this->assertTrue($page['movements_truncated']);
        $this->assertSame(0, $page['distribution']['distribution_events']);
        $this->assertSame(0, $page['distribution']['distributed'], 'a real zero');
        $this->assertNull($page['distribution']['last_distributed_at']);
    }

    public function test_a_year_long_item_page_is_monthly_and_has_no_weekly_median(): void
    {
        $item = $this->sdItem('Yearly');

        $page = app(SupplyDataReport::class)->item($item, $this->filters(['period' => '12m']));

        $this->assertCount(13, $page['trend']);
        $this->assertSame('month_start', array_key_first($page['trend'][0]));
        $this->assertNull($page['distribution']['median_weekly']);
    }

    public function test_the_unit_page_lists_what_a_unit_asked_for_and_received(): void
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $gloves = $this->sdItem('Gloves');
        $gauze = $this->sdItem('Gauze');
        $catalogA = CatalogItem::query()->create(['key' => 'gauze-a', 'name' => 'Gauze 4x4']);
        $catalogB = CatalogItem::query()->create(['key' => 'gauze-b', 'name' => 'Gauze 4x4']);
        $first = $this->sdRequest($r2, InventoryRequest::RECONCILED, '2026-10-06 08:00:00', [
            ['Gauze 4x4', 4, 4, ['catalog_item_id' => $catalogA->id, 'bag_key' => 'airway', 'item_key' => 'g']],
            ['Gauze 4x4', 6, 6, ['catalog_item_id' => $catalogB->id, 'bag_key' => 'als', 'item_key' => 'g']],
            ['Loose item', 2, 2, ['bag_key' => 'als', 'item_key' => 'loose-1']],
            ['Loose item', 1, 1, ['bag_key' => 'airway', 'item_key' => 'loose-2']],
            ['Narcotics box', 1, 1, ['response_type' => 'yes_no', 'requested_qty' => null, 'requested_flag' => true]],
        ], ['reconciled_at' => $this->sdUtc('2026-10-07 08:00:00')]);
        $second = $this->sdRequest($r2, InventoryRequest::REVIEWED, '2026-10-20 08:00:00', [['Gauze 4x4', 3, 0, ['catalog_item_id' => $catalogA->id, 'bag_key' => 'airway', 'item_key' => 'g2']]]);
        $this->sdRequest($r2, InventoryRequest::CANCELLED, '2026-10-21 08:00:00', [['Gauze 4x4', 99, 0, ['catalog_item_id' => $catalogA->id, 'bag_key' => 'airway', 'item_key' => 'g3']]]);
        $this->sdIssue($gloves, 3, $first, '2026-10-07 08:00:00');
        $this->sdIssue($gauze, 5, $first, '2026-10-07 08:00:00');
        $this->sdIssue($gloves, 1, $second, '2026-10-21 08:00:00');

        $page = app(SupplyDataReport::class)->unit('unit:'.$r2->id, $this->filters());

        $this->assertSame(['unit_key' => 'unit:'.$r2->id, 'apparatus_id' => $r2->id, 'label' => 'Rescue 2', 'labels' => ['Rescue 2'], 'kind' => 'unit'], $page['unit']);
        $this->assertSame(2, $page['summary']['requests'], 'the cancelled request is not counted');
        $this->assertSame(1, $page['summary']['requests_reconciled']);
        $this->assertSame(16, $page['summary']['qty_requested'], '4 + 6 + 2 + 1 + 3; the Yes answer and the cancelled request add nothing');
        $this->assertSame(3, $page['summary']['distributed_events']);
        $this->assertSame(2, $page['summary']['items_distributed']);

        $requested = collect($page['top_requested']);
        $this->assertCount(4, $requested, 'the same label on two catalog items, or two form items, stays separate');
        $a = $requested->firstWhere('item_key', 'catalog:'.$catalogA->id.'|each');
        $this->assertSame([7, 2], [$a['qty_requested'], $a['requests']], 'one catalog identity across two requests');
        $this->assertSame(6, $requested->firstWhere('item_key', 'catalog:'.$catalogB->id.'|each')['qty_requested']);
        $this->assertSame(2, $requested->firstWhere('item_key', 'line:als|loose-1|each')['qty_requested']);

        $this->assertSame(['Gloves', 'Gauze'], array_column($page['top_distributed'], 'name'));
        $this->assertSame(2, array_sum(array_column($page['trend'], 'requests')));
        $this->assertSame(3, array_sum(array_column($page['trend'], 'distribution_events')));
        $this->assertSame([$second->number, $first->number], array_column($page['requests'], 'number'), 'newest first');
        $this->assertSame([1, 3], [$page['requests'][0]['lines'], $page['requests'][0]['qty_requested']]);
        $this->assertSame($first->uuid, $page['requests'][1]['uuid']);
        $this->assertNotNull($page['requests'][1]['reconciled_at']);
        $this->assertNotNull($page['summary']['last_replenished_at']);
    }

    public function test_a_unit_with_no_activity_is_a_zero_page_with_its_truck_name(): void
    {
        $quiet = $this->sdApparatus('Quiet Engine', 'QE');

        $page = app(SupplyDataReport::class)->unit('unit:'.$quiet->id, $this->filters());

        $this->assertSame('Quiet Engine', $page['unit']['label']);
        $this->assertSame([0, 0, 0, null], [$page['summary']['requests'], $page['summary']['qty_requested'], $page['summary']['distributed_events'], $page['summary']['last_replenished_at']]);
        $this->assertSame([], $page['requests']);
        $this->assertSame([], $page['top_requested']);
        $this->assertCount(5, $page['trend']);
    }

    public function test_detail_and_unassigned_units_and_unknown_trucks(): void
    {
        $this->sdRequest(null, InventoryRequest::SUBMITTED, '2026-10-10 08:00:00', [['x', 2, 0]], ['event_assignment' => 'Parade']);

        $detail = app(SupplyDataReport::class)->unit('detail', $this->filters());

        $this->assertSame(['detail', null, 'Detail / Special Event', 'detail'], [$detail['unit']['unit_key'], $detail['unit']['apparatus_id'], $detail['unit']['label'], $detail['unit']['kind']]);
        $this->assertSame(1, $detail['summary']['requests']);
        $this->assertSame('Unit no longer on file', app(SupplyDataReport::class)->unit('unassigned', $this->filters())['unit']['label']);

        $this->expectException(ModelNotFoundException::class);
        app(SupplyDataReport::class)->unit('unit:999999', $this->filters());
    }

    public function test_a_unit_page_follows_the_period_filter(): void
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $this->sdRequest($r2, InventoryRequest::SUBMITTED, '2026-09-10 08:00:00', [['x', 2, 0]]);
        $this->sdRequest($r2, InventoryRequest::SUBMITTED, '2026-10-10 08:00:00', [['x', 5, 0]]);

        $thirty = app(SupplyDataReport::class)->unit('unit:'.$r2->id, $this->filters());
        $ninety = app(SupplyDataReport::class)->unit('unit:'.$r2->id, $this->filters(['period' => '90d']));

        $this->assertSame([1, 5], [$thirty['summary']['requests'], $thirty['summary']['qty_requested']]);
        $this->assertSame([2, 7], [$ninety['summary']['requests'], $ninety['summary']['qty_requested']]);
    }
}
