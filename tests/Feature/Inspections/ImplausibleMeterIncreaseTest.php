<?php

declare(strict_types=1);

namespace Tests\Feature\Inspections;

use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Services\Inspections\InspectionHold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Inspections\Concerns\BuildsInspectionReviewFixtures;
use Tests\TestCase;

/**
 * Physically impossible meter increases (docs/daily-inspection-continuity.md section 2.2).
 *
 * On 2026-10-30 a Rescue 22 checkout carried Rescue 2's readings: +44,228 miles and +8,155 engine
 * hours three days after the previous accepted reading. It became the truck's meter and every
 * correct reading after it was held as a rollback. Engine hours cannot rise faster than the clock,
 * and no truck drives more than 1,500 miles a day, so only such a reading is held now, and it never
 * becomes the meter until a reviewer accepts it.
 */
final class ImplausibleMeterIncreaseTest extends TestCase
{
    use BuildsInspectionReviewFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-27 08:00:00');
        $this->setUpSwapFixtures();
    }

    public function test_the_rescue_22_sequence_holds_the_wrong_reading_and_keeps_the_meters(): void
    {
        // Accepted reading on 10-27. The unit had no accepted reading before, so nothing is held.
        $this->submit($this->meterPayload(58951, 8363.0))->assertCreated()->assertJsonPath('processing_status', 'accepted');
        $this->assertMeters(58951, '8363.0');

        // 10-30: Rescue 2's readings entered on Rescue 22.
        $this->later(3 * 24);
        $this->submit($this->meterPayload(103179, 16518.0))->assertCreated()
            ->assertJsonPath('review_status', 'pending_review')->assertJsonPath('processing_status', 'held_for_review');
        $wrong = ApparatusInspection::query()->latest('id')->firstOrFail();
        foreach (['miles', 'engine_hours'] as $field) {
            $hold = $this->holdFor($wrong, $field);
            $this->assertSame(InspectionHold::REASON_IMPLAUSIBLE_INCREASE, $hold->reason);
            $this->assertTrue($hold->blocks_acceptance);
            $this->assertSame('open', $hold->status);
        }
        $this->assertSame('103179.0', $this->holdFor($wrong, 'miles')->submitted_value);
        $this->assertSame('58951.0', $this->holdFor($wrong, 'miles')->authoritative_value);
        $this->assertEqualsWithDelta(72.0, $this->holdFor($wrong, 'engine_hours')->metadata['elapsed_hours'], 0.01);
        $this->assertEqualsWithDelta(96.0, $this->holdFor($wrong, 'engine_hours')->metadata['allowed_increase'], 0.01);
        $this->assertMeters(58951, '8363.0');

        // The correct mileage that followed is no longer a "rollback": the truck kept its real meter.
        $this->later(3 * 24);
        $this->submit($this->meterPayload(59050, 9206.0))->assertCreated();
        $later = ApparatusInspection::query()->latest('id')->firstOrFail();
        $this->assertSame(0, ApparatusInspectionException::query()
            ->where('apparatus_inspection_id', $later->id)->where('reason', InspectionHold::REASON_ROLLBACK)->count());
        $this->assertSame(59050, $this->unit->fresh()->current_miles);
        // 9,206 h is 843 h above the accepted 8,363 h after 144 h: still more than the clock allows,
        // so that one reading waits for a reviewer instead of becoming the meter.
        $this->assertSame(InspectionHold::REASON_IMPLAUSIBLE_INCREASE, $this->holdFor($later, 'engine_hours')->reason);
        $this->assertSame('8363.0', $this->unit->fresh()->current_engine_hours);

        // A later checkout whose hours are consistent with the clock is accepted with no hold at all.
        $this->later(1 * 24);
        $this->submit($this->meterPayload(59120, 8380.5))->assertCreated()->assertJsonPath('processing_status', 'accepted');
        $this->assertMeters(59120, '8380.5');
    }

    public function test_normal_daily_increases_are_never_held(): void
    {
        $miles = 1000;
        $hours = 400.0;
        $this->submit($this->meterPayload($miles, $hours))->assertCreated()->assertJsonPath('processing_status', 'accepted');
        foreach ([[1, 120, 6.5], [1, 340, 14.0], [2, 610, 20.0], [1, 15, 0.4], [3, 900, 31.0]] as [$days, $addMiles, $addHours]) {
            $this->later($days * 24);
            $miles += $addMiles;
            $hours += $addHours;
            $this->submit($this->meterPayload($miles, $hours))->assertCreated()->assertJsonPath('processing_status', 'accepted');
        }
        // Several checkouts in one shift with the same or slightly higher readings.
        $this->later(2);
        $this->submit($this->meterPayload($miles + 3, $hours + 1.5))->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $this->assertSame($miles + 3, $this->unit->fresh()->current_miles);
    }

    public function test_a_truck_with_no_accepted_reading_is_never_held(): void
    {
        // The unit carries a fleet meter (1,000 mi / 400 h) but no accepted inspection.
        $this->submit($this->meterPayload(250000, 30000.0))->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $this->assertMeters(250000, '30000.0');
    }

    public function test_the_former_500_mile_and_36_hour_cases_are_not_held(): void
    {
        $this->submit($this->meterPayload(1000, 400.0))->assertCreated();
        // Each of these fired the removed 500 mi / 36 h rule.
        $this->later(1 * 24);
        $this->submit($this->meterPayload(1600, 440.0))->assertCreated()->assertJsonPath('processing_status', 'accepted');
        $this->later(2 * 24);
        $this->submit($this->meterPayload(2900, 500.0))->assertCreated()->assertJsonPath('processing_status', 'accepted');
        $this->later(12);
        $this->submit($this->meterPayload(3400, 536.0))->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $this->assertMeters(3400, '536.0');
    }

    public function test_the_limits_are_elapsed_hours_plus_24_and_1500_miles_per_day(): void
    {
        $this->submit($this->meterPayload(1000, 400.0))->assertCreated();
        $this->later(1 * 24);

        // Exactly at the limit: 24 h elapsed + 24 h, and 1,500 mi for one day.
        $this->submit($this->meterPayload(2500, 448.0))->assertCreated()->assertJsonPath('processing_status', 'accepted');
        $this->assertMeters(2500, '448.0');

        // Within the same day, the previous accepted reading is now 2,500 mi / 448 h.
        $this->later(1);
        $this->submit($this->meterPayload(4001, 473.5))->assertCreated()->assertJsonPath('processing_status', 'held_for_review');
        $held = ApparatusInspection::query()->latest('id')->firstOrFail();
        $this->assertSame(InspectionHold::REASON_IMPLAUSIBLE_INCREASE, $this->holdFor($held, 'miles')->reason);
        $this->assertSame(InspectionHold::REASON_IMPLAUSIBLE_INCREASE, $this->holdFor($held, 'engine_hours')->reason);
        $this->assertMeters(2500, '448.0');
    }

    public function test_accepting_the_hold_applies_the_reported_reading_without_a_reset_confirmation(): void
    {
        $held = $this->heldJump();
        $reviewer = $this->reviewer();

        $this->decideMeter($this->holdFor($held, 'miles'), $reviewer, ['action' => 'accept_submitted']);
        $this->decideMeter($this->holdFor($held, 'engine_hours'), $reviewer, ['action' => 'accept_submitted']);

        $this->assertMeters(9000, '600.0');
        $this->assertSame('resolved', $this->holdFor($held, 'miles')->fresh()->status);
        $this->assertSame('approved', $held->fresh()->review_status);
    }

    public function test_rejecting_the_hold_keeps_the_truck_meter(): void
    {
        $held = $this->heldJump();
        $reviewer = $this->reviewer();

        $this->decideMeter($this->holdFor($held, 'miles'), $reviewer, ['action' => 'dismiss']);
        $this->decideMeter($this->holdFor($held, 'engine_hours'), $reviewer, ['action' => 'dismiss']);

        $this->assertMeters(1100, '405.0');
        $this->assertSame('dismissed', $this->holdFor($held, 'engine_hours')->fresh()->status);
        // The reported values stay on the inspection as evidence.
        $this->assertSame(9000, $held->fresh()->miles);
    }

    public function test_the_hold_has_a_plain_label(): void
    {
        $this->assertSame('Engine hours rose more than time allows since the last reading', InspectionHold::meterReasonLabel('engine_hours', 'implausible_increase'));
        $this->assertSame('Mileage rose more than a truck can drive since the last reading', InspectionHold::meterReasonLabel('miles', 'implausible_increase'));
        $this->assertSame('Mileage lower than last reading', InspectionHold::meterReasonLabel('miles', 'rollback'));
    }

    /** 1,100 mi / 405 h accepted, then 9,000 mi / 600 h one day later (held). */
    private function heldJump(): ApparatusInspection
    {
        $this->submit($this->meterPayload(1100, 405.0))->assertCreated()->assertJsonPath('processing_status', 'accepted');
        $this->later(1 * 24);
        $this->submit($this->meterPayload(9000, 600.0))->assertCreated()->assertJsonPath('processing_status', 'held_for_review');
        $this->assertMeters(1100, '405.0');

        return ApparatusInspection::query()->latest('id')->firstOrFail();
    }

    /** Time passes; the member signs in again (a session does not last days). */
    private function later(int $hours): void
    {
        $this->travel($hours)->hours();
        $this->actingAsCanonicalUser($this->member);
    }

    private function assertMeters(int $miles, string $hours): void
    {
        $unit = $this->unit->fresh();
        $this->assertSame($miles, $unit->current_miles);
        $this->assertSame($hours, $unit->current_engine_hours);
    }
}
