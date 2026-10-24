<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/**
 * A small fixed dataset with hand-computed answers. Every metric is "requests, not consumption".
 *
 *  R1 Aug 10  Rescue 2   tape 4, gauze 10        issued: tape 4, gauze 6
 *  R2 Aug 24  Rescue 2   tape 2                  nothing issued
 *  R3 Sep  7  Engine 1   tape 6, stethoscope 1   issued: stethoscope 1
 *  R4 Sep  8  Detail "Ultra"   gauze 5           nothing issued
 *  R5 Sep  9  Detail "ultra "  tape 1            cancelled by the member
 *  R6 Sep 10  Rescue 2   narcs box Yes, tape 3   nothing issued
 */
final class EmsStatisticsTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        $this->staff = $this->emsCaptain();
        $a = $this->emsMember('Firefighter', 'Member A');
        $b = $this->emsMember('Firefighter', 'Member B');

        $r1 = $this->at('2026-08-10', fn () => $this->submitRequest($a, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 4], ['form' => 'als-med-bag', 'item_key' => 'als.trauma.gauze', 'quantity' => 10]]));
        $this->at('2026-08-24', fn () => $this->submitRequest($b, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 2]]));
        $r3 = $this->at('2026-09-07', fn () => $this->submitRequest($a, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 6], ['form' => 'airway-bag', 'item_key' => 'airway.top.stethoscope', 'quantity' => 1]], ['target' => ['kind' => 'other_unit', 'apparatus_id' => $this->engine1->id]]));
        $this->at('2026-09-08', fn () => $this->submitRequest($b, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.gauze', 'quantity' => 5]], ['target' => ['kind' => 'detail_event', 'event_assignment' => 'Ultra']]));
        $r5 = $this->at('2026-09-09', fn () => $this->submitRequest($a, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 1]], ['target' => ['kind' => 'detail_event', 'event_assignment' => 'ultra ']]));
        $this->at('2026-09-10', fn () => $this->submitRequest($b, [['form' => 'als-med-bag', 'item_key' => 'als.rescue.narcs-box', 'flag' => true], ['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 3]]));

        $this->issue($r1['uuid'], ['als.trauma.tape' => 4, 'als.trauma.gauze' => 6]);
        $this->issue($r3['uuid'], ['airway.top.stethoscope' => 1]);
        $this->emsPost($a, "/employee/forms/api/ems/requests/{$r5['uuid']}/cancel", [])->assertOk();
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    private function at(string $day, \Closure $work): mixed
    {
        $this->travelTo(CarbonImmutable::parse($day.' 12:00:00', 'America/New_York'));

        return $work();
    }

    /** @param array<string, int> $byItem */
    private function issue(string $uuid, array $byItem): void
    {
        $lines = collect($this->actingAs($this->staff)->getJson("/ems/api/requests/{$uuid}")->json('data.lines'))->keyBy('item_key');
        $this->emsPost($this->staff, "/ems/api/requests/{$uuid}/issue", ['issuances' => collect($byItem)->map(fn (int $q, string $key): array => ['line_id' => $lines[$key]['id'], 'quantity' => $q])->values()->all()])->assertOk();
    }

    private function report(string $query = 'date_from=2026-08-01&date_to=2026-09-30'): array
    {
        return $this->actingAs($this->staff)->getJson('/ems/api/statistics?'.$query)->assertOk()->json('data');
    }

    public function test_totals_follow_the_written_definitions(): void
    {
        $report = $this->report();
        $totals = $report['totals'];

        $this->assertSame('Requests, not consumption', $report['label']);
        $this->assertSame(6, $totals['requests_submitted']);
        $this->assertSame(1, $totals['requests_cancelled']);
        $this->assertSame(5, $totals['requests_counted']);
        $this->assertSame(8, $totals['lines_requested'], 'R1 2, R2 1, R3 2, R4 1, R6 2; the cancelled R5 is not counted.');
        $this->assertSame(31, $totals['qty_requested'], '4+10+2+6+1+5+3: the Yes answer is not a quantity.');
        $this->assertSame(1, $totals['yes_answers']);
        $this->assertSame(11, $totals['qty_issued'], 'tape 4 + gauze 6 + stethoscope 1.');
        $this->assertSame(21, $totals['qty_outstanding'], 'R1 gauze 4, R2 tape 2, R3 tape 6, R4 gauze 5, R6 tape 3 + the Yes answer 1.');
        $this->assertSame(8.71, $totals['weeks_in_range']);
        $this->assertSame(0.57, $totals['requests_per_week'], '5 counted requests / 8.71 weeks.');
        $this->assertArrayHasKey('requests_per_week', $report['definitions']);
        $this->assertSame(array_keys(\App\Services\Ems\EmsStatistics::DEFINITIONS), array_keys($report['definitions']));
    }

    public function test_top_items_monthly_trend_detail_and_unit_breakdowns(): void
    {
        $report = $this->report();

        $top = collect($report['top_requested_items']);
        $this->assertSame(['Tape 1"', 'Gauze 4x4', 'Narcs Box', 'Stethoscope'], $top->pluck('label')->all());
        $this->assertSame([15, 15, 1, 1], $top->pluck('qty_requested')->all());
        $this->assertSame([4, 2, 1, 1], $top->pluck('requests')->all());
        $this->assertSame([4, 6, 0, 1], $top->pluck('qty_issued')->all());

        $this->assertSame([
            ['month' => '2026-08', 'requests' => 2, 'qty_requested' => 16, 'qty_issued' => 10],
            ['month' => '2026-09', 'requests' => 3, 'qty_requested' => 15, 'qty_issued' => 1],
        ], $report['monthly_trend']);

        $this->assertSame(['requests' => 1, 'without_event_text' => 0, 'events' => [['event_assignment' => 'Ultra', 'requests' => 1]]], $report['detail_special_event']);

        $units = collect($report['by_unit'])->keyBy('label');
        $this->assertSame([3, 19, 10], [$units['Rescue 2']['requests'], $units['Rescue 2']['qty_requested'], $units['Rescue 2']['qty_issued']]);
        $this->assertSame([1, 7, 1], [$units['Engine 1']['requests'], $units['Engine 1']['qty_requested'], $units['Engine 1']['qty_issued']]);
        $this->assertSame([1, 5, 0], [$units['Detail / Special Event']['requests'], $units['Detail / Special Event']['qty_requested'], $units['Detail / Special Event']['qty_issued']]);
    }

    public function test_filters_by_unit_target_kind_and_date_range(): void
    {
        $this->assertSame(3, $this->report('apparatus_id='.$this->rescue2->id)['totals']['requests_counted']);
        $this->assertSame(1, $this->report('apparatus_id='.$this->engine1->id)['totals']['requests_counted']);
        $this->assertSame(2, $this->report('target_kind=detail_event')['totals']['requests_submitted']);
        $this->assertSame(1, $this->report('target_kind=detail_event')['totals']['requests_counted']);

        $august = $this->report('date_from=2026-08-01&date_to=2026-08-31');
        $this->assertSame(2, $august['totals']['requests_counted']);
        $this->assertSame(16, $august['totals']['qty_requested']);
        $this->assertSame(4.43, $august['totals']['weeks_in_range']);
        $this->assertSame([['month' => '2026-08', 'requests' => 2, 'qty_requested' => 16, 'qty_issued' => 10]], $august['monthly_trend']);

        $empty = $this->report('date_from=2025-01-01&date_to=2025-01-31');
        $this->assertSame(0, $empty['totals']['requests_submitted']);
        $this->assertSame([], $empty['top_requested_items']);
        $this->assertSame([], $empty['monthly_trend']);
    }

    public function test_bad_filters_get_a_friendly_message(): void
    {
        $this->actingAs($this->staff)->getJson('/ems/api/statistics?date_from=08/01/2026')->assertStatus(422)
            ->assertJsonPath('error.message', 'Enter the start date as year-month-day.');
        $this->actingAs($this->staff)->getJson('/ems/api/statistics?date_from=2026-09-01&date_to=2026-08-01')->assertStatus(422)
            ->assertJsonPath('error.message', 'The end date cannot be before the start date.');
    }
}
