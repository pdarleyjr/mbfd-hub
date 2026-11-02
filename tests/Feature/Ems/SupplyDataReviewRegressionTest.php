<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\InventoryRequest;
use App\Models\Ems\StockMovement;
use App\Models\Ems\SupplyItem;
use App\Services\Ems\SupplyData\SupplyDataFilters;
use App\Services\Ems\SupplyData\SupplyDataReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Ems\Concerns\BuildsSupplyDataWorld;
use Tests\TestCase;

/**
 * Regressions from the independent review of Supply Data, plus the reviewer's adversarial cases that were worth keeping
 * (consistency across views, daylight-saving instants). Today is Monday, November 2, 2026 unless a test moves it.
 */
final class SupplyDataReviewRegressionTest extends TestCase
{
    use BuildsSupplyDataWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSupplyDataClock();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function overview(array $input = []): array
    {
        return app(SupplyDataReport::class)->overview(SupplyDataFilters::fromArray($input + ['limit' => 50]));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function item(SupplyItem $item, array $input = []): array
    {
        return app(SupplyDataReport::class)->item($item, SupplyDataFilters::fromArray($input));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function unit(string $key, array $input = []): array
    {
        return app(SupplyDataReport::class)->unit($key, SupplyDataFilters::fromArray($input));
    }

    // ---------------------------------------------------------------------- F1

    public function test_f1_rescue_and_other_unit_requests_that_lost_their_truck_share_one_unassigned_row(): void
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $x = $this->sdItem('X');
        $y = $this->sdItem('Y');
        $z = $this->sdItem('Z');
        $a = $this->sdRequest($r2, InventoryRequest::RECONCILED, '2026-10-20 08:00:00', [['x', 1, 1]], ['target_kind' => 'rescue_unit', 'apparatus_id' => null, 'target_label' => 'Rescue 9']);
        $b = $this->sdRequest($r2, InventoryRequest::RECONCILED, '2026-10-21 08:00:00', [['x', 1, 1]], ['target_kind' => 'other_unit', 'apparatus_id' => null, 'target_label' => 'Bike 1']);
        $c = $this->sdRequest($r2, InventoryRequest::RECONCILED, '2026-10-22 08:00:00', [['x', 1, 1]]);
        $d = $this->sdRequest(null, InventoryRequest::RECONCILED, '2026-10-23 08:00:00', [['x', 1, 1]]);
        $this->sdIssue($x, 4, $a, '2026-10-20 09:00:00');
        $this->sdIssue($x, 5, $b, '2026-10-21 09:00:00');
        $this->sdIssue($y, 1, $c, '2026-10-22 09:00:00');
        $this->sdIssue($z, 2, $d, '2026-10-23 09:00:00');

        $o = $this->overview();
        $unassigned = collect($o['units'])->firstWhere('unit_key', 'unassigned');

        $this->assertCount(3, $o['units'], 'one row each for the truck, Detail and unassigned');
        $this->assertSame(4, $o['headline']['distribution_events']);
        $this->assertSame(4, array_sum(array_column($o['units'], 'distribution_events')), 'unit rows add up to the headline');
        $this->assertSame(4, array_sum(array_column($o['units'], 'requests')));
        $this->assertSame(3, $o['headline']['units_supplied'], 'units supplied counts distinct unit keys');
        $this->assertSame([2, 1], [$unassigned['distribution_events'], $unassigned['items_distributed']], 'both requests gave X: two events, one distinct item');
        $this->assertSame(2, $unassigned['requests']);
        $page = $this->unit('unassigned');
        $this->assertSame([2, 1, 2], [$page['summary']['distributed_events'], $page['summary']['items_distributed'], $page['summary']['requests']]);
    }

    // ---------------------------------------------------------------------- F2

    public function test_f2_a_unit_filter_leaves_stock_pace_and_days_of_supply_room_wide(): void
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $r3 = $this->sdApparatus('Rescue 3', 'R3');
        $glove = $this->sdItem('Glove', ['threshold_qty_base' => 5]);
        $this->sdCount('2026-10-01 08:00:00', [[$glove, 300]], 0);
        foreach ([[$r2, 10], [$r2, 11], [$r2, 12], [$r3, 13], [$r3, 14], [$r3, 15]] as [$unit, $day]) {
            $request = $this->sdRequest($unit, InventoryRequest::RECONCILED, "2026-10-{$day} 08:00:00", [['x', 1, 1]]);
            $this->sdIssue($glove, 10, $request, "2026-10-{$day} 09:00:00");
        }

        $room = collect($this->overview()['stock'])->firstWhere('name', 'Glove');
        $narrow = $this->overview(['unit' => 'unit:'.$r2->id]);
        $row = collect($narrow['stock'])->firstWhere('name', 'Glove');

        $this->assertSame([240, 120.0, 2.0], [$room['on_hand'], $room['days_of_supply'], $room['avg_daily_distributed']]);
        $this->assertSame([240, 120.0, 2.0], [$row['on_hand'], $row['days_of_supply'], $row['avg_daily_distributed']], 'stock is room-wide, so is its pace');
        $this->assertSame(3, $narrow['headline']['distribution_events'], 'the headline is the unit\'s');
        $this->assertSame(30, $narrow['top_items'][0]['distributed']);
        $this->assertStringContainsString('whole supply room', implode(' ', $narrow['scope_notes']));
        $this->assertStringContainsString('days of supply', implode(' ', $narrow['scope_notes']));
    }

    public function test_f2_the_item_page_keeps_balance_pace_room_wide_when_narrowed_to_a_unit(): void
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $r3 = $this->sdApparatus('Rescue 3', 'R3');
        $glove = $this->sdItem('Glove', ['threshold_qty_base' => 5]);
        $this->sdCount('2026-10-01 08:00:00', [[$glove, 300]], 0);
        foreach ([[$r2, 10], [$r2, 11], [$r2, 12], [$r3, 13], [$r3, 14], [$r3, 15]] as [$unit, $day]) {
            $request = $this->sdRequest($unit, InventoryRequest::RECONCILED, "2026-10-{$day} 08:00:00", [['x', 1, 1]]);
            $this->sdIssue($glove, 10, $request, "2026-10-{$day} 09:00:00");
        }

        $page = $this->item($glove, ['unit' => 'unit:'.$r2->id]);

        $this->assertSame([120.0, 2.0], [$page['balance']['days_of_supply'], $page['balance']['avg_daily_distributed']]);
        $this->assertSame([30, 3], [$page['distribution']['distributed'], $page['distribution']['distribution_events']], 'the distribution is the unit\'s');
        $this->assertNotEmpty($page['scope_notes']);
        $this->assertSame([], $this->item($glove)['scope_notes']);
    }

    // ---------------------------------------------------------------------- F3

    public function test_f3_an_inactive_item_with_a_real_count_shows_its_balance_labelled_inactive(): void
    {
        $retired = $this->sdItem('Retired', ['active' => false, 'threshold_qty_base' => 5]);
        $this->sdItem('Current', ['threshold_qty_base' => 5]);
        $this->sdCount('2026-10-01 08:00:00', [[$retired, 12]], 0);

        $page = $this->item($retired);

        $this->assertFalse($page['item']['active']);
        $this->assertSame([12, 'counted', 'ok'], [$page['balance']['on_hand'], $page['balance']['basis'], $page['balance']['status']]);
        $this->assertNotNull($page['balance']['last_counted_at']);
        $this->assertSame(['Current'], array_column($this->overview()['stock'], 'name'), 'a retired item is not in the stock list');

        $asked = $this->overview(['item' => (string) $retired->id]);
        $this->assertSame([[12, false]], array_map(fn (array $r): array => [$r['on_hand'], $r['active']], $asked['stock']));
        $this->assertSame(0, $asked['attention']['not_counted_recently'] + $asked['attention']['below_minimum'] + $asked['attention']['zero_stock'], 'a retired item adds nothing to today\'s attention counts');
    }

    // ---------------------------------------------------------------------- F5

    public function test_f5_fulfillment_names_the_request_side_quantity_recorded_as_issued(): void
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $item = $this->sdItem('Gloves');
        $request = $this->sdRequest($r2, InventoryRequest::RECONCILED, '2026-10-20 08:00:00', [['Gloves', 10, 9]]);
        $this->sdIssue($item, 2, $request, '2026-10-20 09:00:00'); // the ledger says 2 left; the form says 9 were issued

        $o = $this->overview();

        $this->assertArrayNotHasKey('qty_distributed', $o['fulfillment']);
        $this->assertSame([10, 9], [$o['fulfillment']['qty_requested'], $o['fulfillment']['qty_recorded_issued']]);
        $this->assertSame(2, $o['top_items'][0]['distributed'], '"distributed" is only ever the ledger');
        $this->assertArrayHasKey('recorded_issued', $o['definitions']);
        $this->assertStringContainsString('not what the stock ledger says', $o['definitions']['recorded_issued']);
    }

    // ---------------------------------------------------------------------- F7

    public function test_f7_a_chain_of_corrections_is_one_corrected_request(): void
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $lines = [['g', 4, 0]];
        $first = $this->sdRequest($r2, InventoryRequest::SUPERSEDED, '2026-10-20 08:00:00', $lines, ['returned_at' => $this->sdUtc('2026-10-20 12:00:00')]);
        $second = $this->sdRequest($r2, InventoryRequest::SUPERSEDED, '2026-10-21 08:00:00', $lines, ['revision_of_request_id' => $first->getKey(), 'revision_number' => 2, 'returned_at' => $this->sdUtc('2026-10-21 12:00:00')]);
        $this->sdRequest($r2, InventoryRequest::SUBMITTED, '2026-10-22 08:00:00', $lines, ['revision_of_request_id' => $second->getKey(), 'revision_number' => 3]);

        $f = $this->overview()['fulfillment'];

        $this->assertSame(1, $f['corrected'], 'R1 to R2 to R3 is one corrected request, counted once at its live revision');
        $this->assertSame(2, $f['rejected'], 'two forms were sent back along the way');
        $this->assertSame([1, 4, 3], [$f['submitted'], $f['qty_requested'], $f['all_submissions']]);
    }

    public function test_f7_a_correction_that_was_then_cancelled_is_not_counted_as_corrected(): void
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $first = $this->sdRequest($r2, InventoryRequest::SUPERSEDED, '2026-10-20 08:00:00', [], ['returned_at' => $this->sdUtc('2026-10-20 12:00:00')]);
        $this->sdRequest($r2, InventoryRequest::CANCELLED, '2026-10-21 08:00:00', [], ['revision_of_request_id' => $first->getKey(), 'revision_number' => 2]);

        $this->assertSame(0, $this->overview()['fulfillment']['corrected']);
    }

    // ---------------------------------------------------------------------- F9

    public function test_f9_the_typical_week_uses_whole_weeks_only(): void
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $item = $this->sdItem('Gauze');
        // Period Wed Oct 7 to Mon Nov 2: whole weeks start Oct 11, 18 and 25; the weeks of Oct 4 and Nov 1 are clipped.
        foreach ([['2026-10-12', 1], ['2026-10-19', 5], ['2026-10-26', 9]] as [$day, $quantity]) {
            $request = $this->sdRequest($r2, InventoryRequest::RECONCILED, "{$day} 08:00:00", [['x', 1, 1]]);
            $this->sdIssue($item, $quantity, $request, "{$day} 09:00:00");
        }

        $filters = SupplyDataFilters::fromArray(['period' => 'custom', 'from' => '2026-10-07', 'to' => '2026-11-02']);
        $page = $this->item($item, ['period' => 'custom', 'from' => '2026-10-07', 'to' => '2026-11-02']);

        $this->assertSame([1, 2, 3], $filters->completeBucketIndexes());
        $this->assertSame(5.0, $page['distribution']['median_weekly'], 'the middle of 1, 5 and 9; the two clipped weeks do not drag it down');
        $this->assertSame(round(15 / (27 / 7), 1), $page['distribution']['avg_weekly'], 'the average is the total over the days, times seven');
    }

    public function test_f9_there_is_no_typical_week_without_a_whole_week(): void
    {
        $item = $this->sdItem('Gauze');

        $page = $this->item($item, ['period' => 'custom', 'from' => '2026-10-28', 'to' => '2026-11-02']);

        $this->assertNull($page['distribution']['median_weekly']);
        $this->assertSame(0.0, $page['distribution']['avg_weekly']);
    }

    // --------------------------------------------------------------------- F13

    public function test_f13_data_quality_counts_ignore_controlled_items(): void
    {
        $controlled = $this->sdItem('Controlled thing', ['controlled_substance' => true]);
        $plain = $this->sdItem('Plain thing');
        $this->sdMovement($controlled, StockMovement::ISSUED, -3, null, '2026-10-20 09:00:00');
        $this->sdMovement($plain, StockMovement::ISSUED, -1, null, '2026-10-20 09:00:00');

        $this->assertSame(1, $this->overview()['data_quality']['unattributed_movements']);
    }

    // ------------------------------------------------------- reviewer's cases

    public function test_daylight_saving_instants_land_in_the_right_week_and_month(): void
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $item = $this->sdItem('G');
        $issue = function (string $utc) use ($r2, $item): void {
            $request = $this->sdRequest($r2, InventoryRequest::RECONCILED, '2026-10-20 08:00:00', [['g', 1, 1]]);
            $this->sdMovement($item, StockMovement::ISSUED, -1, $request, '2026-01-01 00:00:00', ['occurred_at' => CarbonImmutable::parse($utc, 'UTC')]);
        };
        // Fall back on Sunday 2026-11-01: midnight is 04:00Z before the change and 05:00Z after it.
        foreach (['2026-11-01 03:59:59', '2026-11-01 04:00:00', '2026-11-02 04:59:59', '2026-11-02 05:00:00', '2026-10-25 03:59:59', '2026-10-25 04:00:00'] as $utc) {
            $issue($utc);
        }

        $weeks = collect($this->overview()['trend'])->keyBy('week_start');

        $this->assertSame([1, 2, 3], [$weeks['2026-10-18']['distribution_events'], $weeks['2026-10-25']['distribution_events'], $weeks['2026-11-01']['distribution_events']]);
        $this->assertTrue(collect($weeks)->keys()->every(fn (string $day): bool => CarbonImmutable::parse($day)->dayOfWeek === 0), 'every week starts on a Sunday');
        $this->assertSame(6, array_sum(array_column($weeks->all(), 'distribution_events')));
    }

    public function test_monthly_buckets_and_the_twelve_month_comparison_around_daylight_saving(): void
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $item = $this->sdItem('G');
        foreach (['2026-11-01 03:59:59', '2026-11-01 04:00:00', '2026-03-01 04:59:59', '2026-03-01 05:00:00', '2026-03-08 06:59:59', '2026-04-01 03:59:59', '2026-04-01 04:00:00'] as $utc) {
            $request = $this->sdRequest($r2, InventoryRequest::RECONCILED, '2026-10-20 08:00:00', [['g', 1, 1]]);
            $this->sdMovement($item, StockMovement::ISSUED, -1, $request, '2026-01-01 00:00:00', ['occurred_at' => CarbonImmutable::parse($utc, 'UTC')]);
        }

        $o = $this->overview(['period' => '12m']);
        $months = collect($o['trend'])->keyBy('month_start');

        $this->assertSame([1, 1, 1, 3, 1], [$months['2026-10-01']['distribution_events'], $months['2026-11-01']['distribution_events'], $months['2026-02-01']['distribution_events'], $months['2026-03-01']['distribution_events'], $months['2026-04-01']['distribution_events']]);
        $this->assertSame(365, $o['period']['days']);
        $previousDays = CarbonImmutable::parse($o['period']['previous']['from'], 'UTC')->diffInDays(CarbonImmutable::parse($o['period']['previous']['to'], 'UTC')) + 1;
        $this->assertSame(365, (int) $previousDays, 'the previous period is as long as this one');
        $this->assertSame($o['period']['from'], CarbonImmutable::parse($o['period']['previous']['to'])->addDay()->toDateString());
    }

    public function test_a_custom_range_across_the_spring_forward_day_splits_weeks_correctly(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-20 12:00:00', 'America/New_York'));
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $item = $this->sdItem('G');
        // Mar 7 to Mar 9 is 71 hours long (the clocks jump on Sunday Mar 8). Midnights: Mar 7 05:00Z, Mar 9 04:00Z, Mar 10 04:00Z.
        foreach (['2026-03-07 04:59:59' => 0, '2026-03-07 05:00:00' => 1, '2026-03-08 04:59:59' => 1, '2026-03-08 05:00:00' => 1, '2026-03-08 06:59:59' => 1, '2026-03-10 03:59:59' => 1, '2026-03-10 04:00:00' => 0] as $utc => $inside) {
            $request = $this->sdRequest($r2, InventoryRequest::RECONCILED, '2026-03-01 08:00:00', [['g', 1, 1]]);
            $this->sdMovement($item, StockMovement::ISSUED, -1, $request, '2026-01-01 00:00:00', ['occurred_at' => CarbonImmutable::parse($utc, 'UTC')]);
        }

        $o = $this->overview(['period' => 'custom', 'from' => '2026-03-07', 'to' => '2026-03-09']);
        $weeks = collect($o['trend'])->keyBy('week_start');

        $this->assertSame(5, $o['headline']['distribution_events']);
        $this->assertSame([2, 3], [$weeks['2026-03-01']['distribution_events'], $weeks['2026-03-08']['distribution_events']]);
        $this->assertSame(['from' => '2026-03-04', 'to' => '2026-03-06'], $o['period']['previous']);
    }

    public function test_every_view_adds_up_in_a_sixty_request_world(): void
    {
        mt_srand(7);
        $trucks = [$this->sdApparatus('Rescue 2', 'R2'), $this->sdApparatus('Rescue 3', 'R3'), $this->sdApparatus('Engine 1', 'E1')];
        $items = [];
        for ($i = 0; $i < 12; $i++) {
            $items[] = $this->sdItem('Item '.$i, ['threshold_qty_base' => 10]);
        }
        $this->sdCount('2026-09-01 08:00:00', array_map(fn (SupplyItem $it): array => [$it, 100], $items), 0);
        $statuses = [InventoryRequest::RECONCILED, InventoryRequest::SUBMITTED, InventoryRequest::CANCELLED, InventoryRequest::RECONCILED, InventoryRequest::CLOSED];
        for ($n = 0; $n < 60; $n++) {
            $unit = $n % 7 === 0 ? null : $trucks[$n % 3];
            $day = sprintf('2026-10-%02d', min(31, 5 + $n % 27));
            $request = $this->sdRequest($unit, $statuses[$n % 5], "{$day} 08:00:00", [['x', mt_rand(1, 5), mt_rand(0, 5)]]);
            foreach ((array) array_rand($items, mt_rand(1, 3)) as $k) {
                $movement = $this->sdIssue($items[(int) $k], mt_rand(1, 6), $request, "{$day} 10:00:00");
                if ($n % 9 === 0) {
                    $this->sdReverse($movement, $request, '2026-11-01 14:00:00', mt_rand(1, 2));
                }
            }
        }

        $o = $this->overview();
        $events = $o['headline']['distribution_events'];

        $this->assertGreaterThan(20, $events);
        $this->assertSame($events, array_sum(array_column($o['units'], 'distribution_events')));
        $this->assertSame($events, array_sum(array_column($o['top_items'], 'distribution_events')));
        $this->assertSame($events, array_sum(array_column($o['trend'], 'distribution_events')));
        $this->assertSame($o['headline']['requests_submitted'], array_sum(array_column($o['units'], 'requests')));
        $this->assertSame($o['headline']['requests_submitted'], array_sum(array_column($o['trend'], 'requests')));
        $this->assertSame($o['fulfillment']['submitted'], $o['fulfillment']['open'] + $o['fulfillment']['reconciled'] + $o['fulfillment']['completed_other_workflow']);

        $itemEvents = 0;
        foreach ($items as $it) {
            $page = $this->item($it);
            $row = collect($o['top_items'])->firstWhere('item_id', $it->id);
            $itemEvents += $page['distribution']['distribution_events'];
            $this->assertSame([$row['distributed'] ?? 0, $row['distribution_events'] ?? 0], [$page['distribution']['distributed'], $page['distribution']['distribution_events']], "item {$it->id}");
            $this->assertTrue($page['distribution']['distribution_events'] === 0 || abs(array_sum(array_column($page['units'], 'share_pct')) - 100) < 0.3, "shares of item {$it->id}");
        }
        $this->assertSame($events, $itemEvents);
        foreach ($o['units'] as $unit) {
            $summary = $this->unit($unit['unit_key'])['summary'];
            $this->assertSame([$unit['distribution_events'], $unit['requests'], $unit['items_distributed']], [$summary['distributed_events'], $summary['requests'], $summary['items_distributed']], $unit['unit_key']);
        }
    }

    public function test_the_tables_are_not_touched_by_any_unit_view(): void
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $item = $this->sdItem('Gloves');
        $request = $this->sdRequest(null, InventoryRequest::RECONCILED, '2026-10-20 08:00:00', [['x', 1, 1]], ['target_kind' => 'rescue_unit', 'target_label' => 'Gone']);
        $this->sdIssue($item, 2, $request, '2026-10-20 09:00:00');
        $snapshot = fn (): array => collect(DB::select("select name from sqlite_master where type = 'table'"))->pluck('name')
            ->mapWithKeys(fn (string $table): array => [$table => md5((string) json_encode(DB::table($table)->get()->all()))])->all();
        $before = $snapshot();
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower(ltrim($query->sql));
        });

        $this->unit('unassigned');
        $this->unit('detail');
        $this->unit('unit:'.$r2->id, ['period' => '90d']);
        $this->overview(['unit' => 'unassigned']);
        $this->item($item, ['unit' => 'detail']);

        $this->assertSame($before, $snapshot());
        $writes = array_filter($statements, fn (string $sql): bool => preg_match('/^(insert|update|delete|replace|create|alter|drop|truncate|savepoint|begin|commit)\b/', $sql) === 1);
        $this->assertSame([], array_values($writes));
    }
}
