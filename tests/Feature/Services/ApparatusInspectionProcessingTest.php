<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Services\ApparatusInspectionProcessingService;
use App\Services\InspectionMeterBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ApparatusInspectionProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_inspection_processes_once_without_human_approval(): void
    {
        [$apparatus, $inspection, $token] = $this->fixture(101, 1001);
        $this->process($apparatus, $inspection, $token);
        $this->process($apparatus, $inspection, $token);
        $this->assertSame('accepted', $inspection->fresh()->processing_status);
        $this->assertSame('approved', $inspection->fresh()->review_status);
        $this->assertSame(1001, $apparatus->fresh()->current_miles);
        $this->assertEquals(101, $apparatus->fresh()->current_engine_hours);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 1);
        $this->assertNull($inspection->reviewEvents()->first()->changed_by_user_id);
    }

    public function test_rollback_quarantines_only_the_disputed_meter_and_retains_original_evidence(): void
    {
        [$apparatus, $inspection, $token] = $this->fixture(101, 900);
        $this->process($apparatus, $inspection, $token);
        $this->assertSame('accepted_with_exception', $inspection->fresh()->processing_status);
        $this->assertSame(1000, $apparatus->fresh()->current_miles);
        $this->assertEquals(101, $apparatus->fresh()->current_engine_hours);
        $this->assertSame(900, $inspection->fresh()->miles);
        $this->assertDatabaseHas('apparatus_inspection_exceptions', ['field' => 'miles', 'reason' => 'rollback', 'submitted_value' => 900]);
    }

    public function test_stale_baseline_and_implausible_hours_are_not_applied(): void
    {
        [$apparatus, $inspection, $token] = $this->fixture(1000, 1001);
        $apparatus->update(['current_miles' => 1002]);
        $this->process($apparatus, $inspection, $token);
        $this->assertDatabaseHas('apparatus_inspection_exceptions', ['field' => 'miles', 'reason' => 'stale_baseline']);
        $this->assertDatabaseHas('apparatus_inspection_exceptions', ['field' => 'engine_hours', 'reason' => 'implausible_increase']);
        $this->assertEquals(100, $apparatus->fresh()->current_engine_hours);
        $this->assertSame(1002, $apparatus->fresh()->current_miles);
    }

    public function test_tampered_or_other_apparatus_baseline_cannot_authorize_meters(): void
    {
        [$apparatus, $inspection] = $this->fixture(101, 1001);
        $this->process($apparatus, $inspection, 'tampered');
        $this->assertSame(2, ApparatusInspectionException::query()->where('reason', 'baseline_unverified')->count());
        $this->assertSame(1000, $apparatus->fresh()->current_miles);
    }

    public function test_repeated_finding_is_one_persistent_defect_and_present_does_not_resolve_it(): void
    {
        [$apparatus, $inspection, $token] = $this->fixture(null, null, 'Missing');
        $this->process($apparatus, $inspection, $token);
        $second = $inspection->replicate(['inspection_reference', 'processing_status']);
        $second->review_status = 'pending_review';
        $second->save();
        $this->process($apparatus, $second, $token);
        $third = $second->replicate(['inspection_reference', 'processing_status']);
        $third->review_status = 'pending_review';
        $third->results = [['id' => 'cab', 'name' => 'Cab', 'items' => [['id' => 'radio', 'name' => 'Radio', 'status' => 'Present']]]];
        $third->save();
        $this->process($apparatus, $third, $token);
        $this->assertDatabaseCount('apparatus_defects', 1);
        $this->assertFalse(ApparatusDefect::firstOrFail()->resolved);
        $this->assertSame('In Service', $apparatus->fresh()->status);
        $this->assertSame(['first_reported', 'confirmed_again', 'appears_corrected'], ApparatusDefectObservation::query()->orderBy('id')->pluck('observation')->all());
    }

    public function test_paper_relocation_preserves_the_original_defect_and_appends_an_observation(): void
    {
        [$apparatus, $inspection, $token] = $this->fixture(null, null, 'Damaged');
        $defect = ApparatusDefect::recordDefect($apparatus->id, 'Old combined compartment', 'Old radio label', 'Missing', null, null, null);
        $checklist = ['compartments' => [['id' => 'cab', 'name' => 'Cab', 'items' => [[
            'id' => 'radio', 'name' => 'Radio', 'legacyCompartmentNames' => ['Old combined compartment'], 'legacyItemNames' => ['Old radio label'],
        ]]]]];

        DB::transaction(fn () => app(ApparatusInspectionProcessingService::class)->process($inspection, $apparatus, $token, $checklist));

        $this->assertDatabaseCount('apparatus_defects', 1);
        $this->assertDatabaseHas('apparatus_defect_observations', ['apparatus_defect_id' => $defect->id, 'observation' => 'confirmed_again', 'reported_status' => 'Damaged']);
        $this->assertSame('Old combined compartment', $defect->fresh()->compartment);
        $this->assertSame('damaged', $defect->fresh()->issue_type);
    }

    public function test_uncompleted_scheduled_duty_is_preserved_and_escalated_without_placing_the_unit_out_of_service(): void
    {
        [$apparatus, $initial, $token] = $this->fixture(null, null);
        $inspection = $initial->replicate(['inspection_reference']);
        $inspection->pending_effects = ['checklist_v2' => ['scheduled_tasks' => [[
            'id' => 'bottom', 'name' => 'BOTTOM', 'status' => 'Damaged', 'notes' => 'Sea chest grate requires repair',
        ]]]];
        $inspection->save();

        $this->process($apparatus, $inspection, $token);

        $this->assertSame('accepted_with_exception', $inspection->fresh()->processing_status);
        $this->assertDatabaseHas('apparatus_defects', ['apparatus_id' => $apparatus->id, 'compartment' => 'Scheduled duties', 'item' => 'BOTTOM']);
        $this->assertDatabaseHas('apparatus_defect_observations', ['apparatus_inspection_id' => $inspection->id, 'reported_status' => 'Damaged', 'notes' => 'Sea chest grate requires repair']);
        $this->assertSame('In Service', $apparatus->fresh()->status);
    }

    public function test_ambiguous_old_combined_inventory_cannot_be_assigned_to_an_arbitrary_side(): void
    {
        [$apparatus, $inspection, $token] = $this->fixture(null, null, 'Missing');
        $defect = ApparatusDefect::recordDefect($apparatus->id, 'Old combined compartment', 'Old radio label', 'Missing', null, null, null);
        $item = ['id' => 'radio', 'name' => 'Radio', 'legacyCompartmentNames' => ['Old combined compartment'], 'legacyItemNames' => ['Old radio label']];
        $checklist = ['compartments' => [
            ['id' => 'cab', 'name' => 'Cab', 'items' => [$item]],
            ['id' => 'rear', 'name' => 'Rear cab', 'items' => [$item]],
        ]];

        DB::transaction(fn () => app(ApparatusInspectionProcessingService::class)->process($inspection, $apparatus, $token, $checklist));

        $this->assertDatabaseCount('apparatus_defects', 2);
        $this->assertDatabaseMissing('apparatus_defect_observations', ['apparatus_defect_id' => $defect->id]);
        $this->assertFalse($defect->fresh()->resolved);
        $this->assertDatabaseHas('apparatus_defects', ['apparatus_id' => $apparatus->id, 'compartment' => 'Cab', 'item' => 'Radio']);
    }

    /** @return array{Apparatus, ApparatusInspection, string} */
    private function fixture(?float $hours, ?int $miles, string $status = 'Present'): array
    {
        Queue::fake();
        $apparatus = Apparatus::create(['name' => 'Engine 2', 'unit_id' => 'E2', 'designation' => 'E2', 'type' => 'Engine', 'vehicle_number' => 'TEST-102', 'status' => 'In Service', 'current_engine_hours' => 100, 'current_miles' => 1000]);
        $inspection = ApparatusInspection::create([
            'apparatus_id' => $apparatus->id, 'operator_name' => 'Test Member', 'rank' => 'Captain',
            'review_status' => 'pending_review', 'engine_hours' => $hours, 'miles' => $miles,
            'results' => [['id' => 'cab', 'name' => 'Cab', 'items' => [['id' => 'radio', 'name' => 'Radio', 'status' => $status]]]],
            'pending_effects' => ['defects' => $status === 'Present' ? [] : [['compartment' => 'Cab', 'item' => 'Radio', 'status' => $status]]],
        ]);

        return [$apparatus, $inspection, app(InspectionMeterBaseline::class)->issue($apparatus)];
    }

    private function process(Apparatus $apparatus, ApparatusInspection $inspection, string $token): void
    {
        DB::transaction(fn () => app(ApparatusInspectionProcessingService::class)->process($inspection, $apparatus, $token));
    }
}
