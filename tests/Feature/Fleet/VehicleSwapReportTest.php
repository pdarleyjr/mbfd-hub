<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\ApparatusVehicleSwapRequest;
use App\Notifications\NewSubmissionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Fleet\Concerns\BuildsVehicleSwapFixtures;
use Tests\TestCase;

final class VehicleSwapReportTest extends TestCase
{
    use BuildsVehicleSwapFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSwapFixtures();
    }

    public function test_confirming_the_assigned_vehicle_keeps_automatic_processing_exactly_as_before(): void
    {
        $payload = $this->payload(['vehicle_number' => 'V-202'], withFinding: true);

        $this->postJson($this->submissionUrl(), $payload)->assertCreated()
            ->assertJsonPath('processing_status', 'accepted')
            ->assertJsonPath('review_status', 'approved')
            ->assertJsonPath('vehicle_swap_pending', false);

        $this->assertDatabaseCount('apparatus_vehicle_swap_requests', 0);
        $this->assertSame('401.2', $this->unit->fresh()->current_engine_hours);
        $this->assertSame(1020, $this->unit->fresh()->current_miles);
        $this->assertDatabaseCount('apparatus_defects', 1);
        $this->assertSame('V-202', $this->unit->fresh()->vehicle_number);
        Notification::assertNothingSent();
    }

    public function test_the_confirmed_id_matches_the_assignment_ignoring_case_and_spacing(): void
    {
        $payload = $this->payload(['vehicle_number' => '  v-202 ']);
        // The client writes the assigned ID, exactly, to the paper field when nothing changed.
        $this->setField($payload, 'vehicle_num', 'V-202');

        $this->postJson($this->submissionUrl(), $payload)->assertCreated()->assertJsonPath('processing_status', 'accepted');
        $this->assertDatabaseCount('apparatus_vehicle_swap_requests', 0);
    }

    public function test_a_checkout_without_the_confirmation_is_processed_as_it_always_was(): void
    {
        $this->postJson($this->submissionUrl(), $this->payload())->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $this->assertDatabaseCount('apparatus_vehicle_swap_requests', 0);
        $this->assertSame(1020, $this->unit->fresh()->current_miles);
    }

    public function test_a_different_vehicle_is_saved_under_the_unit_but_nothing_is_applied(): void
    {
        $payload = $this->payload([
            'vehicle_number' => 'V-303', 'swap_answer' => 'swap_completed', 'note' => 'E3 is at the shop.',
        ], withFinding: true);

        $this->postJson($this->submissionUrl(), $payload)->assertCreated()
            ->assertJsonPath('processing_status', 'pending_vehicle_swap')
            ->assertJsonPath('review_status', 'pending_review')
            ->assertJsonPath('vehicle_swap_pending', true);

        $inspection = ApparatusInspection::query()->sole();
        $this->assertSame($this->unit->id, $inspection->apparatus_id);
        $this->assertSame('E2', $inspection->designation_at_time);
        $this->assertSame('V-202', $inspection->vehicle_number, 'The stored snapshot is the assigned vehicle; the report is separate.');
        $this->assertNotNull($inspection->pending_effects);

        $swap = ApparatusVehicleSwapRequest::query()->sole();
        $this->assertSame($inspection->id, $swap->apparatus_inspection_id);
        $this->assertSame('pending', $swap->status);
        $this->assertSame('V-202', $swap->assigned_vehicle_number);
        $this->assertSame('V-303', $swap->reported_vehicle_number);
        $this->assertTrue($swap->reported_vehicle_listed);
        $this->assertSame($this->frontline->id, $swap->reported_apparatus_id);
        $this->assertSame('swap_completed', $swap->answer);
        $this->assertSame('E3 is at the shop.', $swap->note);
        $this->assertSame('Swap Reporter', $swap->reporter_name);
        $this->assertSame($this->member->id, $swap->reporter_user_id);

        // No assignment changed, and no finding or reading reached either vehicle.
        $this->assertSame('V-202', $this->unit->fresh()->vehicle_number);
        $this->assertSame('V-303', $this->frontline->fresh()->vehicle_number);
        $this->assertSame('400.0', $this->unit->fresh()->current_engine_hours);
        $this->assertSame(1000, $this->unit->fresh()->current_miles);
        $this->assertSame('900.0', $this->frontline->fresh()->current_engine_hours);
        $this->assertDatabaseCount('apparatus_defects', 0);
        $this->assertDatabaseCount('apparatus_defect_observations', 0);
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 0);
    }

    public function test_a_vehicle_the_fleet_does_not_list_is_recorded_as_typed(): void
    {
        $this->postJson($this->submissionUrl(), $this->payload([
            'vehicle_number' => 'x-77', 'swap_answer' => 'unknown',
        ]))->assertCreated()->assertJsonPath('processing_status', 'pending_vehicle_swap');

        $swap = ApparatusVehicleSwapRequest::query()->sole();
        $this->assertSame('x-77', $swap->reported_vehicle_number);
        $this->assertFalse($swap->reported_vehicle_listed);
        $this->assertNull($swap->reported_apparatus_id);
    }

    public function test_the_client_cannot_assert_a_swap_it_does_not_report(): void
    {
        // The server compares against the locked apparatus; extra keys are refused.
        $payload = $this->payload(['vehicle_number' => 'V-303', 'swap_answer' => 'unknown', 'approved' => true]);
        $this->postJson($this->submissionUrl(), $payload)->assertUnprocessable()->assertJsonValidationErrors('physical_vehicle');
        $this->assertDatabaseCount('apparatus_inspections', 0);

        $payload = $this->payload(['vehicle_number' => 'V-303', 'swap_answer' => 'approved_by_me']);
        $this->postJson($this->submissionUrl(), $payload)->assertUnprocessable()->assertJsonValidationErrors('physical_vehicle.swap_answer');

        $payload = $this->payload(['vehicle_number' => '<script>alert(1)</script>', 'swap_answer' => 'unknown']);
        $this->postJson($this->submissionUrl(), $payload)->assertUnprocessable()->assertJsonValidationErrors('physical_vehicle.vehicle_number');
        $this->assertDatabaseCount('apparatus_inspections', 0);
        $this->assertDatabaseCount('apparatus_vehicle_swap_requests', 0);
    }

    public function test_the_paper_vehicle_field_must_agree_with_the_reported_vehicle(): void
    {
        $payload = $this->payload(['vehicle_number' => 'V-303', 'swap_answer' => 'swap_completed']);
        $this->setField($payload, 'vehicle_num', 'V-202');

        $this->postJson($this->submissionUrl(), $payload)->assertUnprocessable()->assertJsonValidationErrors('field_values');
        $this->assertDatabaseCount('apparatus_inspections', 0);
        $this->assertDatabaseCount('apparatus_vehicle_swap_requests', 0);
    }

    public function test_the_confirmation_needs_the_automatic_processing_contract(): void
    {
        $payload = $this->payload(['vehicle_number' => 'V-303', 'swap_answer' => 'unknown']);
        unset($payload['processing_version']);

        $this->postJson($this->submissionUrl(), $payload)->assertUnprocessable()->assertJsonValidationErrors('physical_vehicle');
    }

    public function test_replaying_a_held_checkout_is_idempotent_and_notifies_once(): void
    {
        $manager = $this->fleetOperationsManager('manager@example.test');
        $payload = $this->payload(['vehicle_number' => 'V-303', 'swap_answer' => 'swap_completed'], withFinding: true);

        $first = $this->postJson($this->submissionUrl(), $payload)->assertCreated();
        $second = $this->postJson($this->submissionUrl(), $payload)->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame('pending_vehicle_swap', $second->json('processing_status'));
        $this->assertDatabaseCount('apparatus_inspections', 1);
        $this->assertDatabaseCount('apparatus_vehicle_swap_requests', 1);
        $this->assertDatabaseCount('apparatus_defects', 0);
        Notification::assertSentToTimes($manager, NewSubmissionNotification::class, 1);

        // The same submission id with a different report is a conflict, not a second report.
        $changed = $payload;
        $changed['physical_vehicle']['vehicle_number'] = 'V-909';
        $this->setField($changed, 'vehicle_num', 'V-909');
        $this->postJson($this->submissionUrl(), $changed)->assertConflict()->assertJsonPath('code', 'DAILY_CHECKOUT_SUBMISSION_REPLAY_CONFLICT');
        $this->assertDatabaseCount('apparatus_vehicle_swap_requests', 1);
    }

    public function test_a_checkout_confirmed_offline_before_the_unit_was_reassigned_is_held_not_dropped(): void
    {
        // The queued submission confirmed V-202, which was assigned when it was captured.
        $payload = $this->payload(['vehicle_number' => 'V-202'], withFinding: true);
        $this->unit->update(['vehicle_number' => 'V-404']);

        $this->postJson($this->submissionUrl(), $payload)->assertCreated()->assertJsonPath('processing_status', 'pending_vehicle_swap');

        $swap = ApparatusVehicleSwapRequest::query()->sole();
        $this->assertSame('V-404', $swap->assigned_vehicle_number);
        $this->assertSame('V-202', $swap->reported_vehicle_number);
        $this->assertSame('unknown', $swap->answer);
        $this->assertStringContainsString('reassigned', (string) $swap->note);
        $this->assertDatabaseCount('apparatus_defects', 0);
    }

    public function test_the_ordinary_review_path_cannot_release_a_held_checkout(): void
    {
        $swap = $this->heldRequest();
        $inspection = ApparatusInspection::query()->findOrFail($swap->apparatus_inspection_id);
        $reviewer = $this->superAdmin();
        $this->actingAs($reviewer);

        $this->postJson("/api/apparatus-inspections/{$inspection->id}/approve", [])->assertConflict()
            ->assertJsonPath('code', 'DAILY_CHECKOUT_HELD_FOR_VEHICLE_SWAP');
        $this->postJson("/api/apparatus-inspections/{$inspection->id}/reject", ['review_notes' => 'No.'])->assertConflict();

        $this->assertSame('pending_review', $inspection->fresh()->review_status);
        $this->assertSame('pending_vehicle_swap', $inspection->fresh()->processing_status);
        $this->assertDatabaseCount('apparatus_defects', 0);
        $this->assertSame(0, ApparatusInspectionReviewEvent::query()->count());
    }

    public function test_the_fleet_vehicle_list_offers_every_vehicle_and_nothing_else(): void
    {
        $response = $this->getJson('/api/public/fleet-vehicles')->assertOk();

        $this->assertSame(['V-202', 'V-303', 'V-909'], array_column($response->json('vehicles'), 'vehicle_number'));
        $roles = array_column($response->json('vehicles'), 'role', 'vehicle_number');
        $this->assertSame(['V-202' => 'frontline', 'V-303' => 'frontline', 'V-909' => 'reserve'], $roles);
        $this->assertSame(['designation', 'role', 'vehicle_number'], collect($response->json('vehicles.0'))->keys()->sort()->values()->all());
    }

    public function test_history_before_this_feature_is_untouched(): void
    {
        $this->postJson($this->submissionUrl(), $this->payload())->assertCreated();
        $inspection = ApparatusInspection::query()->sole();

        $this->assertNull($inspection->vehicleSwapRequest);
        $this->assertSame('V-202', $inspection->vehicle_number);
        $this->assertSame('Accepted', $inspection->displayStatus());
        $this->assertSame(0, ApparatusDefect::query()->count());
    }
}
