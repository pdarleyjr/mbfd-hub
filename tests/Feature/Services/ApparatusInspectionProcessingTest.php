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

    public function test_rollback_holds_the_inspection_for_review_and_applies_only_the_undisputed_meter(): void
    {
        [$apparatus, $inspection, $token] = $this->fixture(101, 900);
        $this->process($apparatus, $inspection, $token);
        $this->assertSame('held_for_review', $inspection->fresh()->processing_status);
        $this->assertSame('pending_review', $inspection->fresh()->review_status);
        $this->assertSame(1000, $apparatus->fresh()->current_miles);
        $this->assertEquals(101, $apparatus->fresh()->current_engine_hours);
        $this->assertSame(900, $inspection->fresh()->miles);
        $this->assertSame($apparatus->id, $inspection->fresh()->processed_apparatus_id);
        $this->assertDatabaseHas('apparatus_inspection_exceptions', ['field' => 'miles', 'reason' => 'rollback', 'submitted_value' => 900, 'blocks_acceptance' => true]);
        $this->assertSame('held_for_review', $inspection->reviewEvents()->first()->status);
    }

    public function test_a_newer_fleet_reading_supersedes_the_submission_and_a_large_increase_is_accepted(): void
    {
        [$apparatus, $inspection, $token] = $this->fixture(1000, 1001);
        $apparatus->update(['current_miles' => 1002]);
        $this->process($apparatus, $inspection, $token);
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $this->assertSame('accepted', $inspection->fresh()->processing_status);
        $this->assertEquals(1000, $apparatus->fresh()->current_engine_hours, 'A +900 hour jump has no maximum-increase exception.');
        $this->assertSame(1002, $apparatus->fresh()->current_miles);
        $this->assertSame(['miles' => 'superseded'], $inspection->reviewEvents()->first()->metadata['meter_outcomes']);
    }

    public function test_tampered_or_other_apparatus_baseline_cannot_turn_a_normal_increase_into_an_exception(): void
    {
        [$apparatus, $inspection] = $this->fixture(101, 1001);
        $this->process($apparatus, $inspection, 'tampered');
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $this->assertSame(1001, $apparatus->fresh()->current_miles);
    }

    public function test_tampered_baseline_cannot_excuse_a_rollback(): void
    {
        [$apparatus, $inspection] = $this->fixture(99, 900);
        $this->process($apparatus, $inspection, 'tampered');
        $this->assertSame(2, ApparatusInspectionException::query()->where('reason', 'rollback')->where('blocks_acceptance', true)->count());
        $this->assertNull(ApparatusInspectionException::query()->where('field', 'miles')->first()->baseline_value);
        $this->assertSame(1000, $apparatus->fresh()->current_miles);
        $this->assertSame('held_for_review', $inspection->fresh()->processing_status);
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

    public function test_uncompleted_scheduled_duty_is_preserved_without_manufacturing_an_exception_or_changing_readiness(): void
    {
        [$apparatus, $initial, $token] = $this->fixture(null, null);
        $inspection = $initial->replicate(['inspection_reference']);
        $inspection->pending_effects = ['checklist_v2' => ['scheduled_tasks' => [[
            'id' => 'bottom', 'name' => 'BOTTOM', 'status' => 'Damaged', 'notes' => 'Sea chest grate requires repair',
        ]]]];
        $inspection->save();

        $this->process($apparatus, $inspection, $token);

        $this->assertSame('accepted', $inspection->fresh()->processing_status);
        $this->assertDatabaseHas('apparatus_defects', ['apparatus_id' => $apparatus->id, 'compartment' => 'Scheduled duties', 'item' => 'BOTTOM']);
        $this->assertDatabaseHas('apparatus_defect_observations', ['apparatus_inspection_id' => $inspection->id, 'reported_status' => 'Damaged', 'notes' => 'Sea chest grate requires repair']);
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
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

    public function test_open_defects_are_loaded_and_locked_once_for_a_large_checklist(): void
    {
        [$apparatus, $initial, $token] = $this->fixture(null, null);
        $inspection = $initial->replicate(['inspection_reference']);
        $items = [];
        for ($index = 1; $index <= 60; $index++) {
            $items[] = ['id' => "item-{$index}", 'name' => "Item {$index}", 'status' => in_array($index, [3, 40], true) ? 'Missing' : 'Present'];
        }
        $inspection->results = [['id' => 'cab', 'name' => 'Cab', 'items' => $items]];
        $inspection->pending_effects = ['defects' => [
            ['compartment' => 'Cab', 'item' => 'Item 3', 'status' => 'Missing'],
            ['compartment' => 'Cab', 'item' => 'Item 40', 'status' => 'Missing'],
        ]];
        $inspection->save();
        $corrected = ApparatusDefect::recordDefect($apparatus->id, 'Cab', 'Item 7', 'Damaged', null, null, null);
        $confirmed = ApparatusDefect::recordDefect($apparatus->id, 'Cab', 'Item 40', 'Damaged', null, null, null);
        $otherApparatus = Apparatus::create(['name' => 'Engine 3', 'unit_id' => 'E3', 'designation' => 'E3', 'type' => 'Engine', 'vehicle_number' => 'TEST-103', 'status' => 'In Service']);
        $unrelated = ApparatusDefect::recordDefect($otherApparatus->id, 'Cab', 'Item 9', 'Missing', null, null, null);

        $defectSelects = 0;
        DB::listen(function ($query) use (&$defectSelects): void {
            if (preg_match('/^\s*select\b.*\bfrom\s+"apparatus_defects"/is', $query->sql) === 1) {
                $defectSelects++;
            }
        });
        $this->process($apparatus, $inspection, $token);

        // One batched lookup for the 60 submitted items, plus recordDefect()'s
        // own lookup for the single newly reported finding (Item 3).
        $this->assertSame(2, $defectSelects);
        $observations = ApparatusDefectObservation::query()
            ->join('apparatus_defects', 'apparatus_defects.id', '=', 'apparatus_defect_observations.apparatus_defect_id')
            ->pluck('observation', 'apparatus_defects.item')->all();
        ksort($observations);
        $this->assertSame(['Item 3' => 'first_reported', 'Item 40' => 'confirmed_again', 'Item 7' => 'appears_corrected'], $observations);
        $this->assertSame('missing', $confirmed->fresh()->issue_type);
        $this->assertFalse($corrected->fresh()->resolved);
        $this->assertDatabaseMissing('apparatus_defect_observations', ['apparatus_defect_id' => $unrelated->id]);
    }

    public function test_resolved_defects_are_not_matched_and_a_new_finding_is_recorded(): void
    {
        [$apparatus, $inspection, $token] = $this->fixture(null, null, 'Missing');
        $resolved = ApparatusDefect::recordDefect($apparatus->id, 'Cab', 'Radio', 'Missing', null, null, null);
        $resolved->update(['status' => 'resolved']);

        $this->process($apparatus, $inspection, $token);

        $this->assertDatabaseCount('apparatus_defects', 2);
        $this->assertDatabaseMissing('apparatus_defect_observations', ['apparatus_defect_id' => $resolved->id]);
        $this->assertSame(['first_reported'], ApparatusDefectObservation::query()->pluck('observation')->all());
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
