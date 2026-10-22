<?php

declare(strict_types=1);

namespace Tests\Feature\Defects;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusDefectParticipant;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Services\Defects\DefectContinuityService;
use App\Services\Defects\FindingsResult;
use App\Services\InspectionPaperFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class DefectContinuityServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_repeat_report_links_to_the_existing_defect_and_never_resets_its_status_or_origin(): void
    {
        $apparatus = $this->apparatus();
        $reporter = User::factory()->create();
        $first = $this->inspection($apparatus, [$this->compartment('front_cab', 'Front Cab', [$this->item('front_cab-item-1', 'Radio', 'Missing', 'No radio in cab')])], $reporter);
        $firstResult = $this->record($apparatus, $first);
        $defect = ApparatusDefect::query()->firstOrFail();
        $this->assertSame([$defect->id], $firstResult->newDefectIds);

        $defect->update(['action_status' => 'replacement_available']);
        $second = $this->inspection($apparatus, [$this->compartment('front_cab', 'Front Cab', [$this->item('front_cab-item-1', 'Radio', 'Missing', 'Still gone')])], User::factory()->create());
        $secondResult = $this->record($apparatus, $second);

        $this->assertDatabaseCount('apparatus_defects', 1);
        $this->assertSame([$defect->id], $secondResult->confirmedDefectIds);
        $this->assertSame([], $secondResult->newDefectIds);
        $fresh = $defect->fresh();
        $this->assertSame('replacement_available', $fresh->action_status);
        $this->assertSame('in_progress', $fresh->status);
        $this->assertSame($first->id, $fresh->apparatus_inspection_id);
        $this->assertSame($first->id, $fresh->first_reported_inspection_id);
        $this->assertSame($reporter->id, $fresh->first_reported_by_user_id);
        $this->assertSame($second->id, $fresh->last_observed_inspection_id);
        $this->assertSame('No radio in cab', $fresh->notes);
        $this->assertSame(['first_reported', 'confirmed_again'], ApparatusDefectObservation::query()->orderBy('id')->pluck('observation')->all());
        $this->assertSame(['reported', 'observed_again'], ApparatusDefectEvent::query()->orderBy('id')->pluck('event_type')->all());
    }

    public function test_stable_keys_survive_a_label_rename_without_aliases(): void
    {
        $apparatus = $this->apparatus();
        $this->record($apparatus, $this->inspection($apparatus, [$this->compartment('front_cab', 'Front Cab', [$this->item('front_cab-item-1', 'Radio', 'Damaged')])]));
        $renamed = $this->inspection($apparatus, [$this->compartment('front_cab', 'Crew Cab', [$this->item('front_cab-item-1', 'Portable radio', 'Damaged')])]);

        $this->record($apparatus, $renamed);

        $this->assertDatabaseCount('apparatus_defects', 1);
        $this->assertDatabaseCount('apparatus_defect_observations', 2);
        $defect = ApparatusDefect::query()->firstOrFail();
        $this->assertSame('Front Cab', $defect->compartment);
        $this->assertSame('front_cab', $defect->compartment_key);
        $this->assertSame('front_cab-item-1', $defect->item_key);
    }

    public function test_a_name_match_backfills_missing_keys_on_a_legacy_defect(): void
    {
        $apparatus = $this->apparatus();
        $legacy = ApparatusDefect::query()->create(['apparatus_id' => $apparatus->id, 'compartment' => 'Front Cab', 'item' => 'Radio', 'status' => 'open', 'issue_type' => 'missing']);
        $inspection = $this->inspection($apparatus, [$this->compartment('front_cab', 'Front Cab', [$this->item('front_cab-item-1', 'Radio', 'Missing')])]);

        $this->record($apparatus, $inspection);

        $this->assertDatabaseCount('apparatus_defects', 1);
        $this->assertSame('front_cab', $legacy->fresh()->compartment_key);
        $this->assertSame('front_cab-item-1', $legacy->fresh()->item_key);
    }

    public function test_identical_item_names_in_two_compartments_stay_distinct(): void
    {
        $apparatus = $this->apparatus();
        $checklist = ['compartments' => [
            ['id' => 'cab_left', 'name' => 'Cab left', 'items' => [['id' => 'cab_left-item-1', 'name' => 'Flashlight']]],
            ['id' => 'cab_right', 'name' => 'Cab right', 'items' => [['id' => 'cab_right-item-1', 'name' => 'Flashlight']]],
        ]];
        $first = $this->inspection($apparatus, [
            $this->compartment('cab_left', 'Cab left', [$this->item('cab_left-item-1', 'Flashlight', 'Missing')]),
            $this->compartment('cab_right', 'Cab right', [$this->item('cab_right-item-1', 'Flashlight', 'Missing')]),
        ]);
        $this->record($apparatus, $first, $checklist);
        $this->assertDatabaseCount('apparatus_defects', 2);

        $second = $this->inspection($apparatus, [
            $this->compartment('cab_left', 'Cab left', [$this->item('cab_left-item-1', 'Flashlight', 'Present')]),
            $this->compartment('cab_right', 'Cab right', [$this->item('cab_right-item-1', 'Flashlight', 'Missing')]),
        ]);
        $result = $this->record($apparatus, $second, $checklist);

        $left = ApparatusDefect::query()->where('compartment', 'Cab left')->firstOrFail();
        $right = ApparatusDefect::query()->where('compartment', 'Cab right')->firstOrFail();
        $this->assertSame([$left->id], $result->appearsCorrectedDefectIds);
        $this->assertSame([$right->id], $result->confirmedDefectIds);
        $this->assertSame('appears_corrected', ApparatusDefectObservation::query()->where('apparatus_defect_id', $left->id)->latest('id')->value('observation'));
    }

    public function test_one_defect_never_receives_two_observations_in_one_inspection(): void
    {
        $apparatus = $this->apparatus();
        $inspection = $this->inspection($apparatus, [$this->compartment('cab', 'Cab', [
            $this->item('cab-item-1', 'Gloves', 'Missing'),
            $this->item('cab-item-2', 'Gloves', 'Damaged'),
        ])]);

        $this->record($apparatus, $inspection);

        $this->assertDatabaseCount('apparatus_defects', 1);
        $this->assertDatabaseCount('apparatus_defect_observations', 1);
    }

    public function test_present_on_an_open_defect_is_recorded_as_appears_corrected_but_never_resolves_it(): void
    {
        $apparatus = $this->apparatus();
        $reporter = User::factory()->create();
        $this->record($apparatus, $this->inspection($apparatus, [$this->compartment('cab', 'Cab', [$this->item('cab-item-1', 'Radio', 'Missing')])], $reporter));
        $defect = ApparatusDefect::query()->firstOrFail();
        $member = User::factory()->create();

        $result = $this->record($apparatus, $this->inspection($apparatus, [$this->compartment('cab', 'Cab', [$this->item('cab-item-1', 'Radio', 'Present')])], $member));

        $this->assertSame([$defect->id], $result->appearsCorrectedDefectIds);
        $fresh = $defect->fresh();
        $this->assertFalse($fresh->resolved);
        $this->assertSame('reported', $fresh->action_status);
        $this->assertSame('open', $fresh->status);
        $this->assertDatabaseHas('apparatus_defect_events', ['apparatus_defect_id' => $defect->id, 'event_type' => 'appears_corrected']);
        $this->assertDatabaseHas('apparatus_defect_participants', ['apparatus_defect_id' => $defect->id, 'user_id' => $member->id, 'enrollment_source' => 'verified_corrected']);
    }

    public function test_present_without_an_open_defect_records_nothing(): void
    {
        $apparatus = $this->apparatus();
        $result = $this->record($apparatus, $this->inspection($apparatus, [$this->compartment('cab', 'Cab', [$this->item('cab-item-1', 'Radio', 'Present')])]));

        $this->assertEquals(new FindingsResult, $result);
        $this->assertDatabaseCount('apparatus_defects', 0);
        $this->assertDatabaseCount('apparatus_defect_observations', 0);
    }

    public function test_recurrence_after_resolution_creates_a_linked_new_defect_and_repeated_resolution_is_allowed(): void
    {
        $apparatus = $this->apparatus();
        $ids = [];
        for ($episode = 1; $episode <= 3; $episode++) {
            $this->record($apparatus, $this->inspection($apparatus, [$this->compartment('cab', 'Cab', [$this->item('cab-item-1', 'Radio', 'Missing')])]));
            $defect = ApparatusDefect::query()->where('resolved', false)->firstOrFail();
            $ids[] = $defect->id;
            $defect->update(['action_status' => 'resolved', 'resolution_type' => 'replacement_installed']);
            $this->assertTrue($defect->fresh()->resolved);
        }

        $this->assertDatabaseCount('apparatus_defects', 3);
        $this->assertNull(ApparatusDefect::find($ids[0])->recurrence_of_defect_id);
        $this->assertSame($ids[0], ApparatusDefect::find($ids[1])->recurrence_of_defect_id);
        $this->assertSame($ids[1], ApparatusDefect::find($ids[2])->recurrence_of_defect_id);
        $this->assertSame(3, ApparatusDefect::query()->where('resolved', true)->count());
    }

    public function test_the_partial_unique_index_still_blocks_a_second_unresolved_duplicate(): void
    {
        $apparatus = $this->apparatus();
        $attributes = ['apparatus_id' => $apparatus->id, 'compartment' => 'Cab', 'item' => 'Radio', 'status' => 'open', 'issue_type' => 'missing'];
        ApparatusDefect::query()->create($attributes);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        ApparatusDefect::query()->create($attributes);
    }

    public function test_a_competing_unresolved_row_is_adopted_instead_of_failing_the_submission(): void
    {
        $apparatus = $this->apparatus();
        $winner = ApparatusDefect::query()->create(['apparatus_id' => $apparatus->id, 'compartment' => 'Cab', 'item' => 'Radio', 'status' => 'open', 'issue_type' => 'missing']);
        $entry = ['compartment' => 'Cab', 'item' => 'Radio', 'status' => 'Missing', 'notes' => null, 'photo_path' => null, 'significant' => false, 'compartment_id' => null, 'item_id' => null];
        $service = app(DefectContinuityService::class);
        $method = new \ReflectionMethod($service, 'createOrFind');

        DB::transaction(function () use ($method, $service, $apparatus, $entry, $winner): void {
            [$defect, $created] = $method->invoke($service, $apparatus, null, $entry, null);
            $this->assertFalse($created);
            $this->assertTrue($defect->is($winner));
            // The enclosing transaction is still usable after the savepoint rollback.
            $this->assertSame(1, ApparatusDefect::query()->count());
        });
    }

    public function test_participants_are_enrolled_once_per_user_and_actorless_legacy_inspections_are_skipped(): void
    {
        $apparatus = $this->apparatus();
        $user = User::factory()->create();
        $first = $this->inspection($apparatus, [$this->compartment('cab', 'Cab', [$this->item('cab-item-1', 'Radio', 'Missing')])], $user);
        $this->record($apparatus, $first);
        $second = $this->inspection($apparatus, [$this->compartment('cab', 'Cab', [$this->item('cab-item-1', 'Radio', 'Missing')])], $user);
        $this->record($apparatus, $second);
        $legacy = $this->inspection($apparatus, [$this->compartment('cab', 'Cab', [$this->item('cab-item-1', 'Radio', 'Missing')])]);
        $this->record($apparatus, $legacy);

        $this->assertDatabaseCount('apparatus_defect_participants', 1);
        $participant = ApparatusDefectParticipant::query()->firstOrFail();
        $this->assertSame('reported', $participant->enrollment_source);
        $this->assertSame($first->id, $participant->first_inspection_id);
        $this->assertSame($second->id, $participant->last_inspection_id);
        $this->assertDatabaseCount('apparatus_defect_observations', 3);
    }

    public function test_significant_damage_is_flagged_when_new_and_when_a_routine_defect_escalates(): void
    {
        $apparatus = $this->apparatus();
        $routine = $this->record($apparatus, $this->inspection($apparatus, [$this->compartment('cab', 'Cab', [
            $this->item('cab-item-1', 'Radio', 'Damaged'),
            $this->item('cab-item-2', 'Light', 'Damaged', null, true),
            $this->item('cab-item-3', 'Mirror', 'Missing', null, true),
        ])]));
        $light = ApparatusDefect::query()->where('item', 'Light')->firstOrFail();
        $radio = ApparatusDefect::query()->where('item', 'Radio')->firstOrFail();
        $mirror = ApparatusDefect::query()->where('item', 'Mirror')->firstOrFail();
        $this->assertSame([$light->id], $routine->significantNewOrEscalatedDefectIds);
        $this->assertSame('significant', $light->severity);
        $this->assertSame('routine', $radio->severity);
        $this->assertSame('routine', $mirror->severity, 'Only damage can be significant.');

        $again = $this->record($apparatus, $this->inspection($apparatus, [$this->compartment('cab', 'Cab', [
            $this->item('cab-item-1', 'Radio', 'Damaged', null, true),
            $this->item('cab-item-2', 'Light', 'Damaged', null, true),
        ])]));

        $this->assertSame([$radio->id], $again->significantNewOrEscalatedDefectIds, 'Known significant damage confirmed again is not new.');
        $this->assertSame('significant', $radio->fresh()->severity);
        $this->assertDatabaseHas('apparatus_defect_events', ['apparatus_defect_id' => $radio->id, 'event_type' => 'escalated']);
        $this->assertDatabaseMissing('apparatus_defect_events', ['apparatus_defect_id' => $light->id, 'event_type' => 'escalated']);
    }

    public function test_defects_follow_the_physical_vehicle_not_the_designation(): void
    {
        $assigned = $this->apparatus('E1');
        $physical = $this->apparatus('E9');
        $inspection = $this->inspection($assigned, [$this->compartment('cab', 'Cab', [$this->item('cab-item-1', 'Radio', 'Missing')])]);

        $this->record($physical, $inspection);

        $this->assertDatabaseHas('apparatus_defects', ['apparatus_id' => $physical->id, 'item' => 'Radio']);
        $this->assertDatabaseMissing('apparatus_defects', ['apparatus_id' => $assigned->id]);

        $next = $this->inspection($assigned, [$this->compartment('cab', 'Cab', [$this->item('cab-item-1', 'Radio', 'Missing')])]);
        $this->record($assigned, $next);
        $this->assertDatabaseCount('apparatus_defects', 2);
    }

    public function test_scheduled_duties_pseudo_compartment_is_tracked_like_equipment(): void
    {
        $apparatus = $this->apparatus();
        $inspection = $this->inspection($apparatus, []);
        $observations = [['id' => 'scheduled_duties', 'name' => 'Scheduled duties', 'items' => [['id' => 'bottom', 'name' => 'BOTTOM', 'status' => 'Damaged', 'notes' => 'Grate']]]];

        DB::transaction(fn () => app(DefectContinuityService::class)->recordFindings($inspection, $apparatus, $observations, [], []));

        $this->assertDatabaseHas('apparatus_defects', ['compartment' => 'Scheduled duties', 'item' => 'BOTTOM', 'compartment_key' => 'scheduled_duties', 'item_key' => 'bottom']);
    }

    public function test_legacy_record_defect_never_overwrites_origin_notes_or_status(): void
    {
        $apparatus = $this->apparatus();
        $origin = $this->inspection($apparatus, []);
        $defect = ApparatusDefect::recordDefect($apparatus->id, 'Cab', 'Radio', 'Missing', 'Original note', 'photos/original.jpg', $origin->id);
        $defect->update(['action_status' => 'repair_scheduled']);
        $later = $this->inspection($apparatus, []);

        $again = ApparatusDefect::recordDefect($apparatus->id, 'Cab', 'Radio', 'Damaged', 'Different note', 'photos/other.jpg', $later->id);

        $this->assertTrue($again->is($defect));
        $fresh = $defect->fresh();
        $this->assertSame($origin->id, $fresh->apparatus_inspection_id);
        $this->assertSame($origin->id, $fresh->first_reported_inspection_id);
        $this->assertSame($later->id, $fresh->last_observed_inspection_id);
        $this->assertSame('Original note', $fresh->notes);
        $this->assertSame('photos/original.jpg', $fresh->photo_path);
        $this->assertSame('repair_scheduled', $fresh->action_status);
        $this->assertSame('in_progress', $fresh->status);
        $this->assertSame('damaged', $fresh->issue_type);
        $this->assertCount(1, $fresh->defect_history);
    }

    public function test_legacy_record_defect_fills_empty_notes_and_photo_only(): void
    {
        $apparatus = $this->apparatus();
        $defect = ApparatusDefect::recordDefect($apparatus->id, 'Cab', 'Radio', 'Missing', null, null, null);

        ApparatusDefect::recordDefect($apparatus->id, 'Cab', 'Radio', 'Missing', 'Late note', 'photos/late.jpg', null);

        $this->assertSame('Late note', $defect->fresh()->notes);
        $this->assertSame('photos/late.jpg', $defect->fresh()->photo_path);
    }

    public function test_action_status_drives_the_legacy_status_and_resolved_columns(): void
    {
        $apparatus = $this->apparatus();
        $defect = ApparatusDefect::recordDefect($apparatus->id, 'Cab', 'Radio', 'Missing', null, null, null);
        $this->assertSame(['reported', 'open', false], [$defect->action_status ?? 'reported', $defect->status, $defect->resolved]);

        $defect->update(['action_status' => 'acknowledged']);
        $this->assertSame(['in_progress', false], [$defect->fresh()->status, $defect->fresh()->resolved]);

        $defect->update(['action_status' => 'dismissed']);
        $fresh = $defect->fresh();
        $this->assertSame(['resolved', true], [$fresh->status, $fresh->resolved]);
        $this->assertNotNull($fresh->resolved_at);

        $defect->update(['action_status' => 'reported']);
        $fresh = $defect->fresh();
        $this->assertSame(['open', false, null], [$fresh->status, $fresh->resolved, $fresh->resolved_at]);
    }

    public function test_legacy_status_writes_keep_action_status_consistent(): void
    {
        $apparatus = $this->apparatus();
        $defect = ApparatusDefect::recordDefect($apparatus->id, 'Cab', 'Radio', 'Missing', null, null, null);

        $defect->update(['status' => 'in_progress']);
        $this->assertSame('action_pending', $defect->fresh()->action_status);

        $defect->update(['status' => 'resolved']);
        $this->assertSame('resolved', $defect->fresh()->action_status);
        $this->assertTrue($defect->fresh()->resolved);

        $defect->update(['resolved' => false]);
        $this->assertSame('reported', $defect->fresh()->action_status);
        $this->assertSame('open', $defect->fresh()->status);
    }

    public function test_open_findings_shape_includes_continuity_fields_and_excludes_internal_notes(): void
    {
        $apparatus = $this->apparatus();
        $checklist = ['compartments' => [['id' => 'front_cab', 'name' => 'Front Cab', 'items' => [['id' => 'front_cab-item-1', 'name' => 'Radio']]]]];
        $reporter = User::factory()->create();
        $this->record($apparatus, $this->inspection($apparatus, [$this->compartment('front_cab', 'Front Cab', [$this->item('front_cab-item-1', 'Radio', 'Missing')])], $reporter), $checklist);
        $defect = ApparatusDefect::query()->firstOrFail();
        $legacy = ApparatusDefect::recordDefect($apparatus->id, 'Old', 'Thing', 'Damaged', null, null, null);

        ApparatusDefectEvent::query()->create(['apparatus_defect_id' => $defect->id, 'event_type' => 'acknowledged', 'member_message' => 'Logistics has your report', 'internal_note' => 'SECRET-INTERNAL-NOTE', 'created_at' => now()->subMinutes(10)]);
        ApparatusIssueMessage::query()->create(['apparatus_defect_id' => $defect->id, 'visibility' => 'internal', 'body' => 'SECRET-INTERNAL-MESSAGE', 'created_at' => now()->subMinute()]);
        $logistics = User::factory()->create(['account_status' => 'active']);
        \Spatie\Permission\Models\Role::findOrCreate('logistics_admin', 'web');
        $logistics->assignRole('logistics_admin');
        ApparatusIssueMessage::query()->create(['apparatus_defect_id' => $defect->id, 'author_user_id' => $logistics->id, 'visibility' => 'member', 'body' => 'Replacement is on the shelf', 'created_at' => now()->subMinutes(5)]);
        // A crew member's own reply is newer but must never be shown to the rest of the crew.
        ApparatusIssueMessage::query()->create(['apparatus_defect_id' => $defect->id, 'author_user_id' => $reporter->id, 'visibility' => 'member', 'body' => 'SECRET-MEMBER-REPLY', 'created_at' => now()->subMinutes(2)]);

        $findings = collect(app(DefectContinuityService::class)->openFindings($apparatus, $checklist))->keyBy('id');

        $row = $findings[$defect->id];
        foreach (['id', 'compartment', 'item', 'issue_type', 'operational_impact', 'last_observation', 'last_observed_at', 'service_status',
            'compartment_id', 'item_id', 'action_status', 'action_label', 'severity', 'first_reported_at', 'latest_member_update'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertSame('front_cab', $row['compartment_id']);
        $this->assertSame('front_cab-item-1', $row['item_id']);
        $this->assertSame('reported', $row['action_status']);
        $this->assertSame('Awaiting verification', $row['action_label']);
        $this->assertSame('routine', $row['severity']);
        $this->assertSame('Replacement is on the shelf', $row['latest_member_update']['text']);
        $this->assertStringNotContainsString('SECRET', json_encode($findings->all()));
        $this->assertNull($findings[$legacy->id]['compartment_id']);
        $this->assertNull($findings[$legacy->id]['latest_member_update']);
    }

    public function test_open_findings_omit_resolved_defects(): void
    {
        $apparatus = $this->apparatus();
        $defect = ApparatusDefect::recordDefect($apparatus->id, 'Cab', 'Radio', 'Missing', null, null, null);
        $defect->update(['action_status' => 'resolved']);

        $this->assertSame([], app(DefectContinuityService::class)->openFindings($apparatus, []));
    }

    public function test_append_only_models_reject_updates_and_deletes(): void
    {
        $apparatus = $this->apparatus();
        $defect = ApparatusDefect::recordDefect($apparatus->id, 'Cab', 'Radio', 'Missing', null, null, null);
        $event = ApparatusDefectEvent::query()->firstOrFail();
        $message = ApparatusIssueMessage::query()->create(['apparatus_defect_id' => $defect->id, 'visibility' => 'member', 'body' => 'Hello']);

        foreach ([[$event, 'update'], [$event, 'delete'], [$message, 'update'], [$message, 'delete']] as [$model, $operation]) {
            try {
                $operation === 'update' ? $model->update([$model instanceof ApparatusDefectEvent ? 'event_type' : 'body' => 'tampered']) : $model->delete();
                $this->fail('Append-only model was modified.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function apparatus(string $unit = 'E2'): Apparatus
    {
        Queue::fake();
        $this->sequence++;

        return Apparatus::create(['name' => 'Engine '.$unit, 'unit_id' => $unit.'-'.$this->sequence, 'designation' => $unit, 'type' => 'Engine', 'vehicle_number' => 'TEST-'.$unit.$this->sequence, 'status' => 'In Service']);
    }

    /** @param list<array<string, mixed>> $results */
    private function inspection(Apparatus $apparatus, array $results, ?User $actor = null): ApparatusInspection
    {
        return ApparatusInspection::create([
            'apparatus_id' => $apparatus->id, 'actor_user_id' => $actor?->id, 'operator_name' => 'Test Member', 'rank' => 'Captain',
            'review_status' => 'pending_review', 'results' => $results,
        ]);
    }

    /** @return array<string, mixed> */
    private function compartment(string $id, string $name, array $items): array
    {
        return ['id' => $id, 'name' => $name, 'items' => $items];
    }

    /** @return array<string, mixed> */
    private function item(string $id, string $name, string $status, ?string $notes = null, bool $significant = false): array
    {
        return array_filter(['id' => $id, 'name' => $name, 'status' => $status, 'notes' => $notes, 'significant' => $significant ?: null], static fn (mixed $value): bool => $value !== null);
    }

    private function record(Apparatus $physical, ApparatusInspection $inspection, array $checklist = []): FindingsResult
    {
        $definitions = app(InspectionPaperFields::class)->equipmentDefinitions($checklist);

        return DB::transaction(fn (): FindingsResult => app(DefectContinuityService::class)->recordFindings(
            $inspection, $physical, $inspection->results ?? [], $definitions, $inspection->pending_effects ?? [],
        ));
    }
}
