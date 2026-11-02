<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\InventoryRequest;
use App\Models\Ems\StockMovement;
use App\Services\Ems\SupplyData\CountVarianceQuery;
use App\Services\Ems\SupplyData\SupplyDataFilters;
use App\Services\Ems\SupplyData\SupplyDataReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ems\Concerns\BuildsSupplyDataWorld;
use Tests\TestCase;

/** The overview's numbers, built from hand-written ledger history. Today is Monday, November 2, 2026. */
final class SupplyDataReportTest extends TestCase
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

    /** @param array<string, mixed> $overview */
    private function row(array $overview, string $section, string $name): array
    {
        return collect($overview[$section])->firstWhere('name', $name) ?? $this->fail("No {$name} in {$section}");
    }

    public function test_requested_is_not_distributed(): void
    {
        $rescue = $this->sdApparatus('Rescue 2', 'R2');
        $gloves = $this->sdItem('Gloves, nitrile (L)');
        $request = $this->sdRequest($rescue, InventoryRequest::RECONCILED, '2026-10-30 09:00:00', [['Gloves', 10, 8]], ['reviewed_at' => $this->sdUtc('2026-10-30 10:00:00'), 'reconciled_at' => $this->sdUtc('2026-10-31 09:00:00')]);
        $this->sdIssue($gloves, 8, $request, '2026-10-31 09:00:00');

        $o = $this->overview();

        $this->assertSame(10, $o['fulfillment']['qty_requested']);
        $this->assertSame(8, $o['fulfillment']['qty_distributed']);
        $this->assertSame(8, $this->row($o, 'top_items', 'Gloves, nitrile (L)')['distributed'], 'distributed comes from the ledger: 8 left the room although 10 were asked for');
        $this->assertSame(1, $o['headline']['distribution_events']);
        $this->assertSame(1, $o['headline']['requests_reconciled']);
        $this->assertSame(24.0, $o['fulfillment']['median_hours_to_reconcile']);
    }

    public function test_a_reversed_issuance_nets_to_zero_like_mir_000001_on_production(): void
    {
        // Seven issuances made before any count, then compensated: seven `adjusted` rows that point at them.
        $rescue = $this->sdApparatus('Rescue 2', 'R2');
        $request = $this->sdRequest($rescue, InventoryRequest::REVIEWED, '2026-10-30 08:00:00', [['A', 5, 0]], ['reviewed_at' => $this->sdUtc('2026-10-30 09:00:00')]);
        $issued = [];
        foreach ([6, 6, 6, 6, 6, 5, 3] as $index => $quantity) {
            $item = $this->sdItem('Item '.$index);
            $issued[] = $this->sdIssue($item, $quantity, $request, '2026-10-30 11:00:00');
        }
        foreach ($issued as $movement) {
            $this->sdReverse($movement, $request, '2026-11-01 15:00:00');
        }
        $this->assertSame(-38, (int) StockMovement::query()->where('movement_type', 'issued')->sum('quantity_base'));
        $this->assertSame(38, (int) StockMovement::query()->where('movement_type', 'adjusted')->sum('quantity_base'));

        $o = $this->overview();

        $this->assertSame(0, $o['headline']['distribution_events'], 'a fully reversed issuance is not a distribution');
        $this->assertSame([], $o['top_items']);
        $this->assertSame(0, $o['headline']['items_distributed']);
        $this->assertSame(0, $o['headline']['units_supplied']);
        $this->assertSame(1, $o['headline']['requests_submitted'], 'the request itself still counts as submitted');
        $this->assertSame(0, array_sum(array_column($o['trend'], 'distribution_events')));
        $this->assertSame('distribution', $o['data_quality']['insufficient_data'][0]['section']);
        $this->assertSame(0, $o['data_quality']['unattributed_movements']);
    }

    public function test_a_partly_corrected_issuance_nets_to_the_corrected_quantity(): void
    {
        $rescue = $this->sdApparatus('Rescue 2', 'R2');
        $item = $this->sdItem('Gauze');
        $request = $this->sdRequest($rescue, InventoryRequest::RECONCILED, '2026-10-20 08:00:00', [['Gauze', 10, 7]]);
        $issued = $this->sdIssue($item, 10, $request, '2026-10-20 09:00:00');
        $this->sdReverse($issued, $request, '2026-10-22 09:00:00', 3);

        $this->assertSame(7, $this->row($this->overview(), 'top_items', 'Gauze')['distributed']);
    }

    public function test_a_correction_in_a_later_period_does_not_change_the_period_of_the_issuance(): void
    {
        $rescue = $this->sdApparatus('Rescue 2', 'R2');
        $item = $this->sdItem('Gauze');
        $request = $this->sdRequest($rescue, InventoryRequest::REVIEWED, '2026-09-20 08:00:00', [['Gauze', 10, 0]]);
        $issued = $this->sdIssue($item, 10, $request, '2026-09-20 09:00:00');
        $this->sdReverse($issued, $request, '2026-10-20 09:00:00');

        $o = $this->overview();

        $this->assertSame(0, $o['headline']['distribution_events']);
        $this->assertSame(0, $o['headline']['previous_distribution_events'], 'the reversal is dated by the issuance it corrects, so the previous period nets to zero as well');
    }

    public function test_corrected_returned_cancelled_and_superseded_requests_are_never_counted_twice(): void
    {
        $rescue = $this->sdApparatus('Rescue 2', 'R2');
        $lines = [['Gloves', 10, 0]];
        $first = $this->sdRequest($rescue, InventoryRequest::SUPERSEDED, '2026-10-20 08:00:00', $lines, ['returned_at' => $this->sdUtc('2026-10-21 08:00:00')]);
        $this->sdRequest($rescue, InventoryRequest::RECONCILED, '2026-10-22 08:00:00', $lines, ['revision_of_request_id' => $first->getKey(), 'revision_number' => 2, 'reconciled_at' => $this->sdUtc('2026-10-23 08:00:00'), 'reviewed_at' => $this->sdUtc('2026-10-22 09:00:00')]);
        $this->sdRequest($rescue, InventoryRequest::CANCELLED, '2026-10-24 08:00:00', $lines);
        $this->sdRequest($rescue, InventoryRequest::RETURNED, '2026-10-25 08:00:00', $lines, ['returned_at' => $this->sdUtc('2026-10-26 08:00:00')]);

        $o = $this->overview();

        $this->assertSame(1, $o['headline']['requests_submitted'], 'only the live revision counts');
        $this->assertSame(10, $o['fulfillment']['qty_requested'], 'the same supplies are not asked for twice');
        $this->assertSame(4, $o['fulfillment']['all_submissions']);
        $this->assertSame(2, $o['fulfillment']['rejected'], 'the superseded original and the one still waiting were both sent back');
        $this->assertSame(1, $o['fulfillment']['corrected']);
        $this->assertSame(1, $o['fulfillment']['reconciled']);
        $this->assertSame(1, $o['units'][0]['requests']);
        $this->assertSame(1, array_sum(array_column($o['trend'], 'requests')));
    }

    public function test_the_fulfillment_states_add_up(): void
    {
        $rescue = $this->sdApparatus('Rescue 2', 'R2');
        $reviewed = $this->sdUtc('2026-10-20 09:00:00');
        $this->sdRequest($rescue, InventoryRequest::SUBMITTED, '2026-10-20 08:00:00');
        $this->sdRequest($rescue, InventoryRequest::REVIEWED, '2026-10-20 08:00:00', [], ['reviewed_at' => $reviewed]);
        $this->sdRequest($rescue, InventoryRequest::RECONCILED, '2026-10-20 08:00:00', [], ['reviewed_at' => $reviewed, 'reconciled_at' => $this->sdUtc('2026-10-21 08:00:00')]);
        $this->sdRequest($rescue, InventoryRequest::CLOSED, '2026-10-20 08:00:00');

        $f = $this->overview()['fulfillment'];

        $this->assertSame(4, $f['submitted']);
        $this->assertSame(2, $f['acknowledged']);
        $this->assertSame(2, $f['open']);
        $this->assertSame(1, $f['reconciled']);
        $this->assertSame(1, $f['completed_other_workflow']);
        $this->assertSame($f['submitted'], $f['open'] + $f['reconciled'] + $f['completed_other_workflow']);
    }

    public function test_distinct_items_are_never_merged_by_name(): void
    {
        $rescue = $this->sdApparatus('Rescue 2', 'R2');
        $request = $this->sdRequest($rescue, InventoryRequest::RECONCILED, '2026-10-20 08:00:00', [['x', 1, 1]]);
        $needle18 = $this->sdItem('Syringe Needle 18 ga.');
        $needle19 = $this->sdItem('Syringe Needle 19 ga.');
        $twinA = $this->sdItem('Gauze Pads');
        $twinB = $this->sdItem('Gauze Pads');
        foreach ([[$needle18, 2], [$needle19, 3], [$twinA, 4], [$twinB, 5]] as [$item, $quantity]) {
            $this->sdIssue($item, $quantity, $request, '2026-10-20 09:00:00');
        }

        $o = $this->overview();

        $this->assertCount(4, $o['top_items']);
        $byId = collect($o['top_items'])->keyBy('item_id');
        $this->assertSame([2, 3, 4, 5], [$byId[$needle18->id]['distributed'], $byId[$needle19->id]['distributed'], $byId[$twinA->id]['distributed'], $byId[$twinB->id]['distributed']]);
        $this->assertCount(4, $o['stock'], 'two items with the same name stay two rows');
    }

    public function test_estimated_balances_are_labelled_and_never_feed_variance_or_days_of_supply(): void
    {
        $rescue = $this->sdApparatus('Rescue 2', 'R2');
        $guess = $this->sdItem('Estimated item', ['threshold_qty_base' => 5]);
        $counted = $this->sdItem('Counted item', ['threshold_qty_base' => 5]);
        $this->sdEstimate('2026-11-01 12:00:00', [[$guess, 40]]);
        $this->sdCount('2026-10-01 08:00:00', [[$counted, 40]], 0);
        foreach ([1, 2, 3, 4] as $day) {
            $request = $this->sdRequest($rescue, InventoryRequest::RECONCILED, "2026-10-1{$day} 08:00:00", [['x', 2, 2]]);
            $this->sdIssue($guess, 2, $request, "2026-10-1{$day} 09:00:00");
            $this->sdIssue($counted, 2, $request, "2026-10-1{$day} 09:00:00");
        }

        $o = $this->overview();
        $estimate = $this->row($o, 'stock', 'Estimated item');
        $verified = $this->row($o, 'stock', 'Counted item');

        $this->assertSame('estimate', $estimate['basis']);
        $this->assertTrue($estimate['estimated']);
        $this->assertSame(32, $estimate['on_hand']);
        $this->assertNull($estimate['days_of_supply'], 'an estimate is never used for days of supply');
        $this->assertNull($estimate['last_counted_at'], 'an estimate is not a count');
        $this->assertSame('calculated', $verified['basis']);
        $this->assertSame(120.0, $verified['days_of_supply'], '32 on hand at 8 distributed over 30 days is 120 days');
        $this->assertSame(1, $o['attention']['estimated']);
        $this->assertSame(1, $o['data_quality']['estimated_items']);
        $this->assertSame(0, $o['count_accuracy']['comparable_counts'], 'nothing is compared against an estimate');
    }

    public function test_variance_uses_the_movement_id_cutoff_of_the_count_not_the_clock(): void
    {
        $rescue = $this->sdApparatus('Rescue 2', 'R2');
        $typedAgainst = $this->sdItem('Cutoff on the line');
        $snapshotCutoff = $this->sdItem('Cutoff on the snapshot');
        $request = $this->sdRequest($rescue, InventoryRequest::RECONCILED, '2026-10-05 08:00:00', [['x', 1, 1]]);

        $this->sdCount('2026-10-01 08:00:00', [[$typedAgainst, 100], [$snapshotCutoff, 100]], 0);
        $before = [$this->sdIssue($typedAgainst, 10, $request, '2026-10-05 09:00:00'), $this->sdIssue($snapshotCutoff, 10, $request, '2026-10-05 09:00:00')];
        $through = $this->sdMaxMovementId();
        // Issued after the shelf was counted but before the count was finalized: it belongs after the count.
        $this->sdIssue($typedAgainst, 3, $request, '2026-10-20 08:30:00');
        $this->sdIssue($snapshotCutoff, 3, $request, '2026-10-20 08:30:00');
        $finalThrough = $this->sdMaxMovementId();
        $this->sdCount('2026-10-20 09:00:00', [[$typedAgainst, 85, 'counted', $through]], $finalThrough);
        $this->sdCount('2026-10-21 09:00:00', [[$snapshotCutoff, 85]], $finalThrough);

        $o = $this->overview();
        $this->assertCount(2, $before);

        $line = collect($o['count_accuracy']['items'])->firstWhere('name', 'Cutoff on the line');
        $snapshot = collect($o['count_accuracy']['items'])->firstWhere('name', 'Cutoff on the snapshot');
        $this->assertSame(90, $line['expected'], '100 less the 10 issued before the typed-against movement; the later 3 is excluded');
        $this->assertSame(-5, $line['variance']);
        $this->assertSame(-5.6, $line['variance_pct']);
        $this->assertSame(87, $snapshot['expected'], 'a line with no cut-off of its own uses the snapshot cut-off, which includes the 3');
        $this->assertSame(-2, $snapshot['variance']);
        $this->assertSame(2, $o['count_accuracy']['comparable_counts']);
        $this->assertNull($o['count_accuracy']['note']);
        $this->assertSame(100.0, $o['count_accuracy']['coverage_pct']);
    }

    public function test_variance_needs_a_verified_baseline_and_says_why_when_it_has_none(): void
    {
        $afterEstimate = $this->sdItem('After estimate');
        $first = $this->sdItem('First ever number');
        $this->sdEstimate('2026-10-02 08:00:00', [[$afterEstimate, 500]]);
        $this->sdCount('2026-10-10 08:00:00', [[$afterEstimate, 12], [$first, 7]], 0);

        $o = $this->overview();
        $history = app(CountVarianceQuery::class)->history((int) $afterEstimate->getKey(), 10);

        $this->assertSame(0, $o['count_accuracy']['comparable_counts']);
        $this->assertSame(2, $o['count_accuracy']['counted_lines']);
        $this->assertSame('No physical count has a verified baseline to compare against yet.', $o['count_accuracy']['note']);
        $this->assertNull($o['count_accuracy']['coverage_pct']);
        $this->assertSame(CountVarianceQuery::NOTE_ESTIMATE, $history[0]['basis_note']);
        $this->assertNull($history[0]['variance']);
        $this->assertSame(CountVarianceQuery::NOTE_ESTIMATE_ROW, $history[1]['basis_note']);
        $this->assertSame(CountVarianceQuery::NOTE_FIRST, app(CountVarianceQuery::class)->history((int) $first->getKey(), 10)[0]['basis_note']);
    }

    public function test_an_empty_period_is_zeros_and_nulls_with_notes_not_an_error(): void
    {
        $this->sdItem('Never counted', ['threshold_qty_base' => 10]);

        $o = $this->overview();

        $this->assertSame(0, $o['headline']['distribution_events']);
        $this->assertNull($o['headline']['change_pct'], 'null, not 0: there is nothing to compare');
        $this->assertSame([], $o['top_items']);
        $this->assertSame([], $o['units']);
        $this->assertSame(0, $o['fulfillment']['submitted']);
        $this->assertNull($o['fulfillment']['median_hours_to_reconcile']);
        $this->assertNull($o['count_accuracy']['coverage_pct']);
        $this->assertSame('No physical count was finished in this period.', $o['count_accuracy']['note']);
        $this->assertCount(5, $o['trend'], 'zero-activity weeks are still listed');
        $this->assertSame([0], array_unique(array_column($o['trend'], 'distribution_events')));
        $this->assertSame('week_start', array_key_first($o['trend'][0]));
        $stock = $o['stock'][0];
        $this->assertNull($stock['on_hand'], 'null: never counted');
        $this->assertSame('none', $stock['basis']);
        $this->assertSame('unknown', $stock['status']);
        $this->assertSame(0.0, $stock['avg_daily_distributed'], 'a real zero: nothing was distributed');
        $this->assertNull($stock['days_of_supply']);
        $this->assertNull($stock['deductions_since_count']);
        $this->assertSame(1, $o['data_quality']['uncounted_items']);
        $this->assertSame('distribution', $o['data_quality']['insufficient_data'][0]['section']);
        $this->assertSame(1, $o['attention']['not_counted_recently']);
    }

    public function test_the_daylight_saving_change_puts_events_in_the_right_eastern_week(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00', 'America/New_York'));
        $rescue = $this->sdApparatus('Rescue 2', 'R2');
        $item = $this->sdItem('Gauze');
        // Instants chosen on both sides of each Sunday midnight (04:00Z before the change, 05:00Z after).
        foreach (['2026-11-01 03:59:00' => 'Oct 31, 11:59 pm', '2026-11-01 04:00:00' => 'Nov 1, 12:00 am', '2026-11-08 04:59:00' => 'Nov 7, 11:59 pm', '2026-11-08 05:00:00' => 'Nov 8, 12:00 am'] as $utc => $label) {
            $request = $this->sdRequest($rescue, InventoryRequest::RECONCILED, '2026-10-30 08:00:00', [['x', 1, 1]]);
            $this->sdMovement($item, StockMovement::ISSUED, -1, $request, '2026-11-01 00:00:00', ['occurred_at' => CarbonImmutable::parse($utc, 'UTC'), 'reason' => $label]);
        }

        $o = $this->overview(['period' => 'custom', 'from' => '2026-10-26', 'to' => '2026-11-09']);

        $this->assertSame(['2026-10-25', '2026-11-01', '2026-11-08'], array_column($o['trend'], 'week_start'));
        $this->assertSame([1, 2, 1], array_column($o['trend'], 'distribution_events'), '11:59 pm Saturday is the old week; midnight Sunday starts the new one, whichever clock offset is in force');
    }

    public function test_department_totals_equal_the_sum_of_the_unit_rows(): void
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $e1 = $this->sdApparatus('Engine 1', 'E1');
        $gloves = $this->sdItem('Gloves');
        $gauze = $this->sdItem('Gauze');
        foreach ([[$r2, 'Gloves', $gloves], [$r2, 'Gauze', $gauze], [$e1, 'Gloves', $gloves], [null, 'Gauze', $gauze]] as $index => [$unit, $label, $item]) {
            $request = $this->sdRequest($unit, InventoryRequest::RECONCILED, '2026-10-2'.$index.' 08:00:00', [[$label, 4, 4]]);
            $this->sdIssue($item, 4, $request, '2026-10-2'.$index.' 09:00:00');
        }

        $o = $this->overview();

        $this->assertSame(4, $o['headline']['distribution_events']);
        $this->assertSame(4, array_sum(array_column($o['units'], 'distribution_events')));
        $this->assertSame(4, array_sum(array_column($o['top_items'], 'distribution_events')));
        $this->assertSame(4, array_sum(array_column($o['trend'], 'distribution_events')));
        $this->assertSame(4, array_sum(array_column($o['units'], 'requests')));
        $this->assertSame($o['headline']['requests_submitted'], array_sum(array_column($o['units'], 'requests')));
        $this->assertSame(3, $o['headline']['units_supplied']);
        $this->assertSame(['detail'], array_values(array_column(array_filter($o['units'], fn (array $u): bool => $u['kind'] === 'detail'), 'unit_key')));
    }

    public function test_previous_period_comparison_and_direction(): void
    {
        $rescue = $this->sdApparatus('Rescue 2', 'R2');
        $up = $this->sdItem('Going up');
        $down = $this->sdItem('Going down');
        $fresh = $this->sdItem('Brand new');
        $spec = [[$up, '2026-09-10', 1], [$up, '2026-10-10', 2], [$up, '2026-10-11', 2], [$down, '2026-09-10', 2], [$down, '2026-09-11', 2], [$down, '2026-10-10', 1], [$fresh, '2026-10-12', 1]];
        foreach ($spec as $index => [$item, $day, $quantity]) {
            $request = $this->sdRequest($rescue, InventoryRequest::RECONCILED, "{$day} 08:00:00", [['x', 1, 1]]);
            $this->sdIssue($item, $quantity, $request, "{$day} 09:00:00");
        }

        $o = $this->overview();
        $byName = collect($o['top_items'])->keyBy('name');

        $this->assertSame(['up', 1, 2], [$byName['Going up']['direction'], $byName['Going up']['previous_distribution_events'], $byName['Going up']['distribution_events']]);
        $this->assertSame('down', $byName['Going down']['direction']);
        $this->assertSame('new', $byName['Brand new']['direction']);
        $this->assertSame(4, $o['headline']['distribution_events']);
        $this->assertSame(3, $o['headline']['previous_distribution_events']);
        $this->assertSame(33.3, $o['headline']['change_pct']);
        $this->assertSame('Going up', $o['top_items'][0]['name'], 'the most events first');
    }

    public function test_attribution_follows_the_truck_named_on_the_request_through_a_changeout(): void
    {
        // A changeout moves the unit's identity between truck rows, so a row's name today is not what the request named.
        $truckA = $this->sdApparatus('Rescue 2', 'R2');
        $truckB = $this->sdApparatus('Reserve 9', 'R9');
        $item = $this->sdItem('Gauze');
        $before = $this->sdRequest($truckA, InventoryRequest::RECONCILED, '2026-10-10 08:00:00', [['Gauze', 2, 2]], ['target_label' => 'Rescue 2']);
        $this->sdIssue($item, 2, $before, '2026-10-10 09:00:00');
        $truckA->update(['name' => 'Reserve 9']);
        $truckB->update(['name' => 'Rescue 2']);
        $after = $this->sdRequest($truckB, InventoryRequest::RECONCILED, '2026-10-20 08:00:00', [['Gauze', 3, 3]], ['target_label' => 'Rescue 2']);
        $this->sdIssue($item, 3, $after, '2026-10-20 09:00:00');

        $units = collect($this->overview()['units'])->keyBy('unit_key');

        $this->assertSame(['Rescue 2', 'Rescue 2'], [$units['unit:'.$truckA->id]['label'], $units['unit:'.$truckB->id]['label']], 'the label is the one on the request, not the truck row today');
        $this->assertSame([1, 1], [$units['unit:'.$truckA->id]['distribution_events'], $units['unit:'.$truckB->id]['distribution_events']]);
    }

    public function test_a_truck_that_carried_two_unit_names_is_flagged_and_listed(): void
    {
        $truck = $this->sdApparatus('Rescue 2', 'R2');
        $this->sdRequest($truck, InventoryRequest::SUBMITTED, '2026-10-10 08:00:00', [], ['target_label' => 'Rescue 2']);
        $this->sdRequest($truck, InventoryRequest::SUBMITTED, '2026-10-20 08:00:00', [], ['target_label' => 'Reserve 9']);

        $o = $this->overview();

        $this->assertSame('Reserve 9', $o['units'][0]['label'], 'the newest label leads');
        $this->assertSame(['Reserve 9', 'Rescue 2'], $o['units'][0]['labels']);
        $this->assertSame(1, $o['data_quality']['trucks_with_more_than_one_unit_name']);
    }

    public function test_a_request_whose_truck_row_was_deleted_is_unassigned_not_detail(): void
    {
        $this->sdRequest(null, InventoryRequest::SUBMITTED, '2026-10-10 08:00:00', [], ['target_kind' => InventoryRequest::TARGET_RESCUE, 'target_label' => 'Old Rescue']);

        $o = $this->overview();

        $this->assertSame('unassigned', $o['units'][0]['unit_key']);
        $this->assertSame('Old Rescue', $o['units'][0]['label']);
        $this->assertSame(1, $o['data_quality']['requests_without_unit']);
    }

    public function test_controlled_items_and_unlinked_issuances_are_handled_honestly(): void
    {
        $rescue = $this->sdApparatus('Rescue 2', 'R2');
        $narcotic = $this->sdItem('Controlled thing', ['controlled_substance' => true]);
        $plain = $this->sdItem('Plain thing');
        $request = $this->sdRequest($rescue, InventoryRequest::RECONCILED, '2026-10-20 08:00:00', [['x', 1, 1]]);
        $this->sdIssue($narcotic, 1, $request, '2026-10-20 09:00:00'); // can only exist through bad data
        $this->sdIssue($plain, 1, $request, '2026-10-20 09:00:00');
        $this->sdMovement($plain, StockMovement::ISSUED, -4, null, '2026-10-21 09:00:00');

        $o = $this->overview();

        $this->assertSame(['Plain thing'], array_column($o['top_items'], 'name'));
        $this->assertSame(1, $o['headline']['distribution_events'], 'the controlled row and the unlinked row are not distribution events');
        $this->assertSame(1, $o['data_quality']['unattributed_movements']);
        $this->assertSame(['Plain thing'], array_column($o['stock'], 'name'));
        $this->assertGreaterThan(0, $o['stock'][0]['avg_daily_distributed']);
    }

    public function test_movements_on_uncounted_requests_are_flagged_but_still_distributed(): void
    {
        $rescue = $this->sdApparatus('Rescue 2', 'R2');
        $item = $this->sdItem('Gauze');
        $request = $this->sdRequest($rescue, InventoryRequest::CANCELLED, '2026-10-20 08:00:00', [['x', 1, 0]]);
        $this->sdIssue($item, 2, $request, '2026-10-20 09:00:00');

        $o = $this->overview();

        $this->assertSame(1, $o['data_quality']['movements_on_uncounted_requests']);
        $this->assertSame(1, $o['headline']['distribution_events'], 'the stock did leave the room, so the ledger wins');
        $this->assertSame(0, $o['headline']['requests_submitted']);
    }

    public function test_stock_status_minimum_and_reorder_come_from_the_ledger_and_the_advisor(): void
    {
        $zero = $this->sdItem('Zero stock', ['threshold_qty_base' => 5]);
        $low = $this->sdItem('Low stock', ['threshold_qty_base' => 5, 'order_qty_packages' => 3, 'package_unit' => 'case', 'units_per_package' => 10]);
        $fine = $this->sdItem('Fine stock', ['threshold_qty_base' => 5]);
        $none = $this->sdItem('No minimum');
        $this->sdCount('2026-10-30 08:00:00', [[$zero, 0], [$low, 4], [$fine, 9], [$none, 3]], 0);

        $o = $this->overview();
        $stock = collect($o['stock'])->keyBy('name');

        $this->assertSame(['zero', 'below_minimum', 'ok', 'ok'], [$stock['Zero stock']['status'], $stock['Low stock']['status'], $stock['Fine stock']['status'], $stock['No minimum']['status']]);
        $this->assertSame(['Zero stock', 'Low stock'], array_slice(array_column($o['stock'], 'name'), 0, 2), 'attention first');
        $this->assertTrue($stock['Low stock']['needs_reorder']);
        $this->assertSame(3, $stock['Low stock']['recommended_order'], 'the advisor\'s order amount, not a number made up here');
        $this->assertSame('case', $stock['Low stock']['recommended_order_unit']);
        $this->assertFalse($stock['Fine stock']['needs_reorder']);
        $this->assertNull($stock['No minimum']['minimum']);
        $this->assertSame(1, $o['attention']['below_minimum']);
        $this->assertSame(1, $o['attention']['zero_stock']);
        $this->assertSame(2, $o['attention']['needs_reorder']);
        $this->assertSame(1, $o['data_quality']['items_without_minimum']);
        $this->assertSame('counted', $stock['Fine stock']['basis']);
        $this->assertSame(0, $o['attention']['not_counted_recently']);
    }

    public function test_quantities_are_in_the_count_unit_and_an_unsized_box_stays_in_single_units(): void
    {
        $rescue = $this->sdApparatus('Rescue 2', 'R2');
        $box = $this->sdItem('Boxed gauze', ['count_unit' => 'box', 'package_unit' => 'box', 'units_per_package' => 100, 'threshold_qty_base' => 1000]);
        $unsized = $this->sdItem('Unsized box', ['count_unit' => 'box']);
        $request = $this->sdRequest($rescue, InventoryRequest::RECONCILED, '2026-10-20 08:00:00', [['x', 1, 1]]);
        $this->sdIssue($box, 250, $request, '2026-10-20 09:00:00');
        $this->sdIssue($unsized, 7, $request, '2026-10-20 09:00:00');
        $this->sdCount('2026-10-01 08:00:00', [[$box, 3000], [$unsized, 20]], 0);

        $o = $this->overview();
        $top = collect($o['top_items'])->keyBy('name');
        $stock = collect($o['stock'])->keyBy('name');

        $this->assertSame([2.5, 'box'], [$top['Boxed gauze']['distributed'], $top['Boxed gauze']['count_unit']]);
        $this->assertSame([7, 'each'], [$top['Unsized box']['distributed'], $top['Unsized box']['count_unit']]);
        $this->assertSame(27.5, $stock['Boxed gauze']['on_hand']);
        $this->assertSame(10, $stock['Boxed gauze']['minimum']);
        $this->assertSame(13, $stock['Unsized box']['on_hand']);
        $this->assertFalse($stock['Unsized box']['units_resolved']);
        $this->assertSame(1, $o['data_quality']['unresolved_conversions']);
    }

    public function test_estimates_far_above_their_order_level_are_listed(): void
    {
        $razors = $this->sdItem('Disposable Razors', ['count_unit' => 'case', 'package_unit' => 'case', 'units_per_package' => 720, 'threshold_qty_base' => 720]);
        $sane = $this->sdItem('Sane estimate', ['threshold_qty_base' => 10]);
        $this->sdEstimate('2026-11-01 12:00:00', [[$razors, 341 * 720], [$sane, 12]]);

        $quality = $this->overview()['data_quality'];

        $this->assertCount(1, $quality['estimates_far_above_order_level']);
        $this->assertSame(['Disposable Razors', 341, 341], [$quality['estimates_far_above_order_level'][0]['name'], $quality['estimates_far_above_order_level'][0]['on_hand'], $quality['estimates_far_above_order_level'][0]['times_order_level']]);
        $this->assertStringContainsString('2 balances are best-guess estimates', $quality['notes'][0]);
    }

    public function test_unit_and_item_filters_narrow_the_overview(): void
    {
        $r2 = $this->sdApparatus('Rescue 2', 'R2');
        $e1 = $this->sdApparatus('Engine 1', 'E1');
        $gloves = $this->sdItem('Gloves');
        $gauze = $this->sdItem('Gauze');
        foreach ([[$r2, $gloves], [$r2, $gauze], [$e1, $gloves]] as $index => [$unit, $item]) {
            $request = $this->sdRequest($unit, InventoryRequest::RECONCILED, '2026-10-2'.$index.' 08:00:00', [['x', 1, 1]]);
            $this->sdIssue($item, 1, $request, '2026-10-2'.$index.' 09:00:00');
        }

        $byUnit = $this->overview(['unit' => 'unit:'.$r2->id]);
        $byItem = $this->overview(['item' => (string) $gloves->id]);

        $this->assertSame(2, $byUnit['headline']['distribution_events']);
        $this->assertSame(1, $byUnit['headline']['units_supplied']);
        $this->assertSame(2, $byUnit['headline']['requests_submitted']);
        $this->assertSame(2, $byItem['headline']['distribution_events']);
        $this->assertSame(['Gloves'], array_column($byItem['stock'], 'name'));
        $this->assertSame(3, $this->overview()['headline']['requests_submitted'], 'the item filter does not narrow request totals');
        $this->assertNotEmpty($byItem['scope_notes']);
    }

    public function test_the_overview_has_the_contract_keys(): void
    {
        $o = $this->overview();

        $this->assertSame(['label', 'generated_at', 'timezone', 'period', 'filters', 'scope_notes', 'attention', 'headline', 'trend', 'top_items', 'units', 'stock', 'fulfillment', 'count_accuracy', 'data_quality', 'definitions', 'filter_options'], array_keys($o));
        $this->assertSame(SupplyDataReport::LABEL, $o['label']);
        $this->assertSame('America/New_York', $o['timezone']);
        $this->assertSame('2026-11-02T12:00:00-05:00', $o['generated_at']);
        $this->assertSame(['below_minimum', 'zero_stock', 'estimated', 'not_counted_recently', 'needs_reorder'], array_keys($o['attention']));
        $this->assertArrayHasKey('distributed', $o['definitions']);
        $this->assertSame(array_keys(SupplyDataReport::DEFINITIONS), array_keys($o['definitions']));
    }
}
