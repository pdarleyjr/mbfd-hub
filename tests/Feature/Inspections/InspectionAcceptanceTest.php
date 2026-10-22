<?php

declare(strict_types=1);

namespace Tests\Feature\Inspections;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusInspectionReviewEvent;
use App\Services\ApparatusInspectionProcessingService;
use App\Services\Defects\FindingsResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Inspections\Concerns\BuildsInspectionReviewFixtures;
use Tests\TestCase;

/**
 * Daily Checkout acceptance (docs/daily-inspection-continuity.md sections 2.1 and 2.2):
 * routine submissions are accepted automatically; only a meter rollback, a member
 * review flag or new significant damage holds an inspection for a reviewer.
 */
final class InspectionAcceptanceTest extends TestCase
{
    use BuildsInspectionReviewFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSwapFixtures();
    }

    public function test_a_routine_inspection_is_accepted_automatically(): void
    {
        $this->submit($this->meterPayload(1020, 401.2))->assertCreated()
            ->assertJsonPath('review_status', 'approved')->assertJsonPath('processing_status', 'accepted');

        $inspection = ApparatusInspection::sole();
        $this->assertSame($this->unit->id, $inspection->processed_apparatus_id);
        $this->assertSame('Accepted', $inspection->displayStatus());
        $this->assertSame('401.2', $this->unit->fresh()->current_engine_hours);
        $this->assertSame(1020, $this->unit->fresh()->current_miles);
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $event = ApparatusInspectionReviewEvent::sole();
        $this->assertNull($event->changed_by_user_id);
        $this->assertSame([], $event->metadata['meter_outcomes']);
    }

    public function test_missing_equipment_is_recorded_and_the_inspection_is_still_accepted(): void
    {
        $this->submit($this->meterPayload(1020, 401.2, withFinding: true))->assertCreated()
            ->assertJsonPath('processing_status', 'accepted');

        $this->assertSame(1, ApparatusDefect::query()->where('apparatus_id', $this->unit->id)->count());
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $this->assertSame('In Service', $this->unit->fresh()->status);
    }

    public function test_equal_and_increasing_readings_are_accepted_with_no_maximum_increase(): void
    {
        $this->submit($this->meterPayload(1000, 400.0))->assertCreated()->assertJsonPath('processing_status', 'accepted');
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);

        // A jump far beyond the former 500 mile / 36 hour limits is no longer an exception.
        $this->submit($this->meterPayload(9000, 480.0))->assertCreated()->assertJsonPath('processing_status', 'accepted');
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $this->assertSame(9000, $this->unit->fresh()->current_miles);
        $this->assertSame('480.0', $this->unit->fresh()->current_engine_hours);
    }

    public function test_the_first_reading_for_a_unit_with_no_meter_is_accepted(): void
    {
        $this->unit->update(['current_miles' => null, 'current_engine_hours' => null]);

        $this->submit($this->meterPayload(250, 12.5))->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $this->assertSame(250, $this->unit->fresh()->current_miles);
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
    }

    public function test_a_mileage_decrease_is_held_for_review_and_the_meter_is_not_applied(): void
    {
        $this->submit($this->meterPayload(900, 401.2))->assertCreated()
            ->assertJsonPath('review_status', 'pending_review')->assertJsonPath('processing_status', 'held_for_review');

        $inspection = ApparatusInspection::sole();
        $this->assertSame('Needs approval', $inspection->displayStatus());
        $this->assertSame(1000, $this->unit->fresh()->current_miles, 'The held rollback is never applied.');
        $this->assertSame('401.2', $this->unit->fresh()->current_engine_hours, 'The other meter is accepted on its own merits.');
        $this->assertSame(900, $inspection->miles, 'The submitted reading is retained as evidence.');
        $hold = $this->holdFor($inspection, 'miles');
        $this->assertSame('rollback', $hold->reason);
        $this->assertTrue($hold->blocks_acceptance);
        $this->assertSame('open', $hold->status);
        $this->assertSame('900.0', $hold->submitted_value);
        $this->assertSame('1000.0', $hold->baseline_value);
        $this->assertSame('1000.0', $hold->authoritative_value);
        $this->assertSame($this->unit->id, $inspection->processed_apparatus_id);
        $this->assertSame(1, ApparatusInspectionException::query()->count());
    }

    public function test_an_engine_hours_decrease_is_held_while_the_mileage_is_applied(): void
    {
        $this->submit($this->meterPayload(1020, 399.0))->assertCreated()->assertJsonPath('processing_status', 'held_for_review');

        $inspection = ApparatusInspection::sole();
        $this->assertSame('rollback', $this->holdFor($inspection, 'engine_hours')->reason);
        $this->assertSame('400.0', $this->unit->fresh()->current_engine_hours);
        $this->assertSame(1020, $this->unit->fresh()->current_miles);
    }

    public function test_a_rollback_without_a_usable_baseline_token_is_held(): void
    {
        $payload = $this->meterPayload(900, 401.2);
        $payload['meter_baseline_token'] = 'tampered';

        $this->submit($payload)->assertCreated()->assertJsonPath('processing_status', 'held_for_review');
        $this->assertNull($this->holdFor(ApparatusInspection::sole(), 'miles')->baseline_value);

        $missing = $this->meterPayload(900, 402.0);
        unset($missing['meter_baseline_token']);
        $this->submit($missing)->assertCreated()->assertJsonPath('processing_status', 'held_for_review');
    }

    public function test_a_tampered_baseline_cannot_make_a_normal_increase_an_exception(): void
    {
        $payload = $this->meterPayload(1020, 401.2);
        $payload['meter_baseline_token'] = 'tampered';

        $this->submit($payload)->assertCreated()->assertJsonPath('processing_status', 'accepted');
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
    }

    public function test_a_reading_overtaken_while_the_form_was_open_is_superseded_not_held(): void
    {
        $payload = $this->meterPayload(1005, 400.5);
        // Fleet recorded a newer reading after the form (and its baseline token) loaded.
        $this->unit->update(['current_miles' => 1010, 'current_engine_hours' => 401.0]);

        $this->submit($payload)->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $this->assertSame(1010, $this->unit->fresh()->current_miles, 'The newer authoritative reading is unchanged.');
        $this->assertSame('401.0', $this->unit->fresh()->current_engine_hours);
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $event = ApparatusInspectionReviewEvent::sole();
        $this->assertSame(['engine_hours' => 'superseded', 'miles' => 'superseded'], $event->metadata['meter_outcomes']);
        $this->assertSame(1005, ApparatusInspection::sole()->miles);
    }

    public function test_an_offline_submission_after_a_later_accepted_inspection_is_superseded(): void
    {
        $offline = $this->meterPayload(1005, 400.5);
        $this->submit($this->meterPayload(1020, 401.2))->assertCreated();

        $this->submit($offline)->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $this->assertSame(1020, $this->unit->fresh()->current_miles);
        $this->assertSame('401.2', $this->unit->fresh()->current_engine_hours);
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $latest = ApparatusInspectionReviewEvent::query()->latest('id')->first();
        $this->assertSame('superseded', $latest->metadata['meter_outcomes']['miles']);
    }

    public function test_a_reading_below_the_form_baseline_is_still_a_rollback_after_a_newer_reading(): void
    {
        $payload = $this->meterPayload(900, 400.5);
        $this->unit->update(['current_miles' => 1010]);

        $this->submit($payload)->assertCreated()->assertJsonPath('processing_status', 'held_for_review');
        $this->assertSame('rollback', $this->holdFor(ApparatusInspection::sole(), 'miles')->reason);
    }

    public function test_the_hold_names_the_previous_accepted_reading(): void
    {
        $this->submit($this->meterPayload(1020, 401.2))->assertCreated();
        $previous = ApparatusInspection::sole();

        $this->submit($this->meterPayload(950, 402.0))->assertCreated()->assertJsonPath('processing_status', 'held_for_review');

        $hold = $this->holdFor(ApparatusInspection::query()->latest('id')->first(), 'miles');
        $this->assertSame($previous->id, $hold->metadata['previous_inspection_id']);
        $this->assertSame(1020, $hold->metadata['previous_value']);
        $this->assertNotNull($hold->metadata['previous_reading_at']);
    }

    public function test_a_member_review_flag_holds_the_inspection_and_snapshots_the_flagged_items(): void
    {
        $payload = $this->meterPayload(1020, 401.2);
        $items = $this->firstItems($payload);
        $payload['review_flag'] = ['explanation' => '  Strange smell near the pump panel.  ', 'items' => $items];

        $this->submit($payload)->assertCreated()
            ->assertJsonPath('review_status', 'pending_review')->assertJsonPath('processing_status', 'held_for_review');

        $inspection = ApparatusInspection::sole();
        $this->assertSame('Strange smell near the pump panel.', $inspection->review_flag_explanation);
        $this->assertSame($items[0]['item_id'], $inspection->review_flag_items[0]['item_id']);
        $this->assertSame($payload['compartments'][0]['name'], $inspection->review_flag_items[0]['compartment']);
        $this->assertSame($payload['compartments'][0]['items'][0]['name'], $inspection->review_flag_items[0]['item']);
        $hold = $this->holdFor($inspection, 'member_review_flag');
        $this->assertSame('member_flagged', $hold->reason);
        $this->assertTrue($hold->blocks_acceptance);
        $this->assertSame('Strange smell near the pump panel.', $hold->metadata['explanation']);
        $this->assertCount(2, $hold->metadata['items']);
        $this->assertSame('401.2', $this->unit->fresh()->current_engine_hours, 'A flag does not stop the readings from being applied.');
    }

    public function test_a_flag_needs_no_missing_or_damaged_items_and_no_item_selection(): void
    {
        $payload = $this->meterPayload(1020, 401.2);
        $payload['review_flag'] = ['explanation' => 'The seat belt retractor feels sticky.'];

        $this->submit($payload)->assertCreated()->assertJsonPath('processing_status', 'held_for_review');

        $inspection = ApparatusInspection::sole();
        $this->assertSame([], $inspection->review_flag_items);
        $this->assertSame(0, ApparatusDefect::query()->count());
    }

    public function test_findings_are_recorded_at_submission_even_when_the_inspection_is_held(): void
    {
        $payload = $this->meterPayload(900, 401.2, withFinding: true);
        $payload['review_flag'] = ['explanation' => 'Please look at the cab.'];

        $this->submit($payload)->assertCreated()->assertJsonPath('processing_status', 'held_for_review');

        $this->assertSame(1, ApparatusDefect::query()->where('apparatus_id', $this->unit->id)->count());
        $this->assertDatabaseHas('apparatus_defect_observations', ['apparatus_inspection_id' => ApparatusInspection::sole()->id]);
        $this->assertSame(2, ApparatusInspectionException::query()->count());
    }

    /** @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}> */
    public static function invalidFlags(): array
    {
        return [
            'blank explanation' => [fn (array $p): array => [...$p, 'review_flag' => ['explanation' => '   ']], 'review_flag.explanation'],
            'missing explanation' => [fn (array $p): array => [...$p, 'review_flag' => ['items' => [['compartment_id' => $p['compartments'][0]['id'], 'item_id' => $p['compartments'][0]['items'][0]['id']]]]], 'review_flag.explanation'],
            'explanation too long' => [fn (array $p): array => [...$p, 'review_flag' => ['explanation' => str_repeat('x', 1001)]], 'review_flag.explanation'],
            'item outside the checklist' => [fn (array $p): array => [...$p, 'review_flag' => ['explanation' => 'Look.', 'items' => [['compartment_id' => 'nope', 'item_id' => 'nope-item-1']]]], 'review_flag.items.0'],
            'item with a foreign id' => [fn (array $p): array => [...$p, 'review_flag' => ['explanation' => 'Look.', 'items' => [['compartment_id' => $p['compartments'][0]['id'], 'item_id' => 'invented']]]], 'review_flag.items.0'],
            'too many items' => [fn (array $p): array => [...$p, 'review_flag' => ['explanation' => 'Look.', 'items' => array_fill(0, 51, ['compartment_id' => $p['compartments'][0]['id'], 'item_id' => $p['compartments'][0]['items'][0]['id']])]], 'review_flag.items'],
            'unknown key' => [fn (array $p): array => [...$p, 'review_flag' => ['explanation' => 'Look.', 'severity' => 'high']], 'review_flag'],
        ];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    #[DataProvider('invalidFlags')]
    public function test_an_invalid_review_flag_is_rejected_before_anything_is_saved(callable $mutate, string $error): void
    {
        $this->submit($mutate($this->meterPayload(1020, 401.2)))->assertUnprocessable()->assertJsonValidationErrors($error);

        $this->assertDatabaseCount('apparatus_inspections', 0);
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $this->assertSame(1000, $this->unit->fresh()->current_miles);
    }

    public function test_an_empty_flag_object_is_not_a_flag(): void
    {
        $payload = $this->meterPayload(1020, 401.2);
        $payload['review_flag'] = [];

        $this->submit($payload)->assertCreated()->assertJsonPath('processing_status', 'accepted');
    }

    public function test_significance_is_stored_on_the_item_and_must_match_its_defect(): void
    {
        $payload = $this->meterPayload(1020, 401.2, withFinding: true);
        $payload['compartments'][0]['items'][0]['status'] = 'Damaged';
        $payload['defects'][0]['status'] = 'Damaged';
        $payload['compartments'][0]['items'][0]['significant'] = true;

        $this->submit($payload)->assertUnprocessable()->assertJsonValidationErrors('defects.0.significant');
        $this->assertDatabaseCount('apparatus_inspections', 0);

        $payload['defects'][0]['significant'] = true;
        $this->submit($payload)->assertCreated();
        $this->assertTrue(ApparatusInspection::sole()->results[0]['items'][0]['significant']);
    }

    public function test_a_non_boolean_significance_is_rejected(): void
    {
        $payload = $this->meterPayload(1020, 401.2);
        $payload['compartments'][0]['items'][0]['significant'] = 'maybe';

        $this->submit($payload)->assertUnprocessable()->assertJsonValidationErrors('compartments.0.items.0.significant');
    }

    /**
     * Depends on the findings service (Agent B) reporting new significant damage.
     * Until it does, this scenario cannot hold and is marked incomplete rather than failed.
     */
    public function test_new_significant_damage_holds_the_inspection(): void
    {
        $payload = $this->meterPayload(1020, 401.2, withFinding: true);
        $payload['compartments'][0]['items'][0]['status'] = 'Damaged';
        $payload['compartments'][0]['items'][0]['significant'] = true;
        $payload['defects'][0]['status'] = 'Damaged';
        $payload['defects'][0]['significant'] = true;

        $this->submit($payload)->assertCreated();

        $inspection = ApparatusInspection::sole();
        if ($inspection->processing_status === 'accepted') {
            $this->markTestIncomplete('Awaiting DefectContinuityService to report significant damage (Agent B).');
        }
        $this->assertSame('held_for_review', $inspection->processing_status);
        $hold = $this->holdFor($inspection, 'significant_damage');
        $this->assertSame('new_significant_damage', $hold->reason);
        $this->assertSame([ApparatusDefect::sole()->id], $hold->metadata['defect_ids']);
    }

    public function test_significant_damage_from_the_findings_service_creates_one_blocking_hold(): void
    {
        $this->submit($this->meterPayload(1020, 401.2, withFinding: true))->assertCreated();
        $inspection = ApparatusInspection::sole();
        $defect = ApparatusDefect::sole();

        app(ApparatusInspectionProcessingService::class)->recordNonMeterHolds(
            $inspection,
            Apparatus::findOrFail($this->unit->id),
            new FindingsResult(significantNewOrEscalatedDefectIds: [$defect->id]),
        );

        $hold = $this->holdFor($inspection, 'significant_damage');
        $this->assertSame('new_significant_damage', $hold->reason);
        $this->assertTrue($hold->blocks_acceptance);
        $this->assertSame([$defect->id], $hold->metadata['defect_ids']);
    }

    public function test_routine_findings_never_create_a_hold(): void
    {
        app(ApparatusInspectionProcessingService::class)->recordNonMeterHolds(
            $this->heldFixtureInspection(),
            $this->unit->fresh(),
            new FindingsResult(newDefectIds: [1], confirmedDefectIds: [2], appearsCorrectedDefectIds: [3]),
        );

        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
    }

    public function test_a_replay_of_a_flagged_submission_is_idempotent_and_a_changed_flag_conflicts(): void
    {
        $payload = $this->meterPayload(1020, 401.2);
        $payload['review_flag'] = ['explanation' => 'Check the pump panel.', 'items' => $this->firstItems($payload, 1)];
        $payload['compartments'][0]['items'][1]['significant'] = false;

        $first = $this->submit($payload)->assertCreated();
        $this->submit($payload)->assertOk()->assertJsonPath('id', $first->json('id'));

        $padded = $payload;
        $padded['review_flag']['explanation'] = '  Check the pump panel.  ';
        $this->submit($padded)->assertOk()->assertJsonPath('id', $first->json('id'));

        $changed = $payload;
        $changed['review_flag']['explanation'] = 'Something else entirely.';
        $this->submit($changed)->assertConflict()->assertJsonPath('code', 'DAILY_CHECKOUT_SUBMISSION_REPLAY_CONFLICT');

        $this->assertDatabaseCount('apparatus_inspections', 1);
        $this->assertSame(1, ApparatusInspectionException::query()->count());
        $this->assertSame(1, ApparatusInspectionReviewEvent::query()->count());
    }

    public function test_display_status_labels_for_held_and_rejected_inspections(): void
    {
        $this->assertSame('Needs approval', (new ApparatusInspection(['processing_status' => 'held_for_review', 'review_status' => 'pending_review']))->displayStatus());
        $this->assertSame('Rejected', (new ApparatusInspection(['processing_status' => 'rejected', 'review_status' => 'rejected']))->displayStatus());
        $this->assertSame('Held: vehicle swap', (new ApparatusInspection(['processing_status' => 'pending_vehicle_swap', 'review_status' => 'pending_review']))->displayStatus());
    }

    private function heldFixtureInspection(): ApparatusInspection
    {
        return ApparatusInspection::query()->create([
            'apparatus_id' => $this->unit->id, 'operator_name' => 'Fixture', 'rank' => 'Firefighter', 'review_status' => 'pending_review',
            'results' => [], 'completed_at' => now(),
        ]);
    }
}
