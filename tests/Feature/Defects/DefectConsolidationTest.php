<?php

declare(strict_types=1);

namespace Tests\Feature\Defects;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusDefectParticipant;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Services\Defects\DefectConsolidationService;
use App\Services\Defects\DefectContinuityService;
use App\Services\Defects\DuplicateDefectFinder;
use App\Services\Defects\MemberIssueHistory;
use App\Services\InspectionPaperFields;
use App\Services\Inspections\EmployeeInspectionHistoryService;
use App\Support\Fleet\DefectActionStatus;
use App\Support\Fleet\DefectFollowUpState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Duplicate reports of one issue on one vehicle become ONE issue (DefectConsolidationService, defects:consolidate-duplicates). */
final class DefectConsolidationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
    }

    public function test_merge_moves_observations_members_and_messages_and_closes_the_duplicate_quietly(): void
    {
        $apparatus = $this->apparatus();
        [$alice, $bob, $carol] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
        $actor = User::factory()->create();
        $shared = $this->inspection($apparatus, $carol);
        $onlyDuplicate = $this->inspection($apparatus, $bob);
        $survivor = $this->defect($apparatus, 'Cab', 'Radio', ['first_reported_at' => now()->subDays(2), 'last_observed_at' => now()->subDays(2), 'first_reported_by_user_id' => $alice->id]);
        $duplicate = $this->defect($apparatus, 'Cab', 'Portable radio', ['first_reported_at' => now()->subDays(3), 'last_observed_at' => now()->subHour(),
            'last_observed_inspection_id' => $onlyDuplicate->id, 'first_reported_by_user_id' => $bob->id, 'severity' => 'significant',
            'item_key' => 'cab-radio', 'compartment_key' => 'cab']);
        $this->observe($survivor, $shared);
        $this->observe($duplicate, $shared);
        $this->observe($duplicate, $onlyDuplicate);
        $this->participant($survivor, $alice);
        $this->participant($survivor, $carol, now()->subDay());
        $this->participant($duplicate, $bob);
        $this->participant($duplicate, $carol, now()->subDays(5));
        $message = ApparatusIssueMessage::query()->create(['apparatus_defect_id' => $duplicate->id, 'author_user_id' => $bob->id, 'visibility' => ApparatusIssueMessage::VISIBILITY_MEMBER, 'body' => 'Still dead today']);

        $result = app(DefectConsolidationService::class)->merge($survivor, [$duplicate], $actor, 'Same radio');

        $this->assertSame([$duplicate->id], $result['merged']);
        $kept = $survivor->fresh();
        $this->assertFalse((bool) $kept->resolved);
        $this->assertSame($bob->id, $kept->first_reported_by_user_id);
        $this->assertSame('significant', $kept->severity);
        $this->assertSame('cab-radio', $kept->item_key);
        $this->assertSame($onlyDuplicate->id, $kept->last_observed_inspection_id);
        // The shared inspection keeps the survivor's own observation; the other one moves.
        $this->assertSame(2, ApparatusDefectObservation::query()->where('apparatus_defect_id', $survivor->id)->count());
        $this->assertSame(1, ApparatusDefectObservation::query()->where('apparatus_defect_id', $duplicate->id)->count());
        $this->assertEqualsCanonicalizing([$alice->id, $bob->id, $carol->id], ApparatusDefectParticipant::query()->where('apparatus_defect_id', $survivor->id)->pluck('user_id')->all());
        $carolOnSurvivor = ApparatusDefectParticipant::query()->where('apparatus_defect_id', $survivor->id)->where('user_id', $carol->id)->sole();
        $this->assertTrue($carolOnSurvivor->first_participated_at->lt(now()->subDays(4)));
        $this->assertSame($survivor->id, (int) DB::table('apparatus_issue_messages')->where('id', $message->id)->value('apparatus_defect_id'));

        $closed = $duplicate->fresh();
        $this->assertTrue((bool) $closed->resolved);
        $this->assertSame(DefectActionStatus::DISMISSED, $closed->action_status);
        $this->assertSame('merged_duplicate', $closed->resolution_type);
        $this->assertSame($survivor->id, $closed->duplicate_of_defect_id);
        $this->assertSame($actor->id, $closed->resolved_by_user_id);
        $this->assertStringContainsString('#'.$survivor->id, (string) $closed->resolution_notes);
        $this->assertTrue($closed->isMergedDuplicate());
        $this->assertSame('Merged', DefectActionStatus::labelFor($closed->action_status, $closed->resolution_type));
        $this->assertSame(['merged_into'], ApparatusDefectEvent::query()->where('apparatus_defect_id', $duplicate->id)->pluck('event_type')->all());
        $this->assertSame(['duplicates_merged'], ApparatusDefectEvent::query()->where('apparatus_defect_id', $survivor->id)->pluck('event_type')->all());
        Notification::assertNothingSent();
        $this->assertDatabaseCount('inspection_notification_deliveries', 0);
    }

    public function test_a_rerun_is_a_no_op_and_closed_or_foreign_reports_are_refused(): void
    {
        $apparatus = $this->apparatus();
        $actor = User::factory()->create();
        $survivor = $this->defect($apparatus, 'Cab', 'Radio');
        $duplicate = $this->defect($apparatus, 'Cab', 'Radio set');
        $service = app(DefectConsolidationService::class);
        $service->merge($survivor, [$duplicate], $actor, 'Same radio');

        $again = $service->merge($survivor, [$duplicate->id], $actor, 'Same radio');

        $this->assertSame([], $again['merged']);
        $this->assertSame([$duplicate->id], $again['skipped']);
        $this->assertSame(1, ApparatusDefectEvent::query()->where('event_type', 'merged_into')->count());
        $this->assertSame(1, ApparatusDefectEvent::query()->where('event_type', 'duplicates_merged')->count());

        $foreign = $this->defect($this->apparatus('E3'), 'Cab', 'Radio');
        $this->assertRefused(fn () => $service->merge($survivor, [$foreign], $actor, 'Same radio'));
        $this->assertFalse((bool) $foreign->fresh()->resolved);

        $closed = $this->defect($apparatus, 'Cab', 'Old radio', ['action_status' => DefectActionStatus::RESOLVED]);
        $this->assertRefused(fn () => $service->merge($survivor, [$closed], $actor, 'Same radio'));
        $this->assertRefused(fn () => $service->merge($closed, [$survivor], $actor, 'Same radio'));
        $this->assertSame(DefectActionStatus::RESOLVED, $closed->fresh()->action_status);
    }

    public function test_a_merged_duplicate_cannot_be_reopened_and_leaves_the_member_closed_list(): void
    {
        $apparatus = $this->apparatus();
        $actor = User::factory()->create();
        $survivor = $this->defect($apparatus, 'Cab', 'Radio');
        $duplicate = $this->defect($apparatus, 'Cab', 'Radio set');
        app(DefectConsolidationService::class)->merge($survivor, [$duplicate], $actor, 'Same radio');

        $history = app(MemberIssueHistory::class);
        $this->assertSame(0, $history->list($apparatus)['closed_count']);
        $this->assertSame([], $history->closed($apparatus, null)['data']);
        $this->assertSame([$survivor->id], array_column($history->list($apparatus)['open'], 'id'));
        $this->assertSame([$survivor->id], array_column(app(DefectContinuityService::class)->openFindings($apparatus, []), 'id'));
        $this->assertSame('Merged', $history->timeline($duplicate->fresh())['issue']['action_label']);
    }

    public function test_a_later_checkout_of_a_merged_item_reaches_the_survivor_and_is_not_a_recurrence_of_the_duplicate(): void
    {
        $apparatus = $this->apparatus();
        $actor = User::factory()->create();
        $survivor = $this->defect($apparatus, 'Cab', 'Radio');
        $duplicate = $this->defect($apparatus, 'Cab', 'radio ', ['item_key' => 'cab-1', 'compartment_key' => 'cab']);
        app(DefectConsolidationService::class)->merge($survivor, [$duplicate], $actor, 'Same radio');
        $member = User::factory()->create();
        $inspection = ApparatusInspection::query()->create(['apparatus_id' => $apparatus->id, 'actor_user_id' => $member->id, 'operator_name' => 'Test Member', 'rank' => 'Captain', 'review_status' => 'pending_review',
            'results' => [['id' => 'cab', 'name' => 'Cab', 'items' => [['id' => 'cab-1', 'name' => 'Radio', 'status' => 'Missing']]]]]);

        $result = DB::transaction(fn () => app(DefectContinuityService::class)->recordFindings($inspection, $apparatus, $inspection->results, app(InspectionPaperFields::class)->equipmentDefinitions([]), []));

        $this->assertSame([$survivor->id], $result->confirmedDefectIds);
        $this->assertSame(2, ApparatusDefect::query()->count());
        $this->assertDatabaseHas('apparatus_defect_participants', ['apparatus_defect_id' => $survivor->id, 'user_id' => $member->id]);
    }

    public function test_the_command_lists_groups_on_a_dry_run_and_merges_into_the_oldest_with_apply(): void
    {
        $apparatus = $this->apparatus();
        $other = $this->apparatus('E3');
        $actor = $this->reviewerUser();
        $oldest = $this->defect($apparatus, 'Cab', 'Radio', ['first_reported_at' => now()->subDays(3)]);
        $spelling = $this->defect($apparatus, ' cab', 'RADIO ', ['first_reported_at' => now()->subDay()]);
        $unlocatedA = $this->defect($apparatus, 'Member-reported concerns', 'Hinge is cracked', ['compartment_key' => 'member-concerns', 'item_key' => 'concern-a', 'first_reported_at' => now()->subDays(2)]);
        $unlocatedB = $this->defect($apparatus, 'member-reported concerns ', 'hinge is  cracked', ['compartment_key' => 'member-concerns', 'item_key' => 'concern-b']);
        $located = $this->defect($apparatus, 'Port locker', 'Hinge is cracked', ['compartment_key' => 'member-concerns', 'item_key' => 'concern-c']);
        $distinct = $this->defect($apparatus, 'Cab', 'Radio mount');
        $elsewhere = $this->defect($other, 'Cab', 'radio');

        $this->artisan('defects:consolidate-duplicates')->assertSuccessful()
            ->expectsOutputToContain('Dry run')->expectsOutputToContain('possible, not merged')->expectsOutputToContain('[no key]');
        $this->assertSame(7, ApparatusDefect::query()->where('resolved', false)->count());

        $this->artisan('defects:consolidate-duplicates', ['--apply' => true])->assertFailed();
        $this->artisan('defects:consolidate-duplicates', ['--apply' => true, '--actor' => (string) User::factory()->create()->id])->assertFailed();
        $this->assertSame(7, ApparatusDefect::query()->where('resolved', false)->count());

        $this->artisan('defects:consolidate-duplicates', ['--apply' => true, '--actor' => (string) $actor->id, '--apparatus' => (string) $apparatus->id])->assertSuccessful();

        $this->assertSame($oldest->id, $spelling->fresh()->duplicate_of_defect_id);
        $this->assertSame($unlocatedA->id, $unlocatedB->fresh()->duplicate_of_defect_id);
        // The same text in a stated location is a different place: listed as possible, never merged.
        $this->assertFalse((bool) $located->fresh()->resolved);
        $this->assertFalse((bool) $oldest->fresh()->resolved);
        $this->assertFalse((bool) $unlocatedA->fresh()->resolved);
        $this->assertFalse((bool) $distinct->fresh()->resolved);
        $this->assertFalse((bool) $elsewhere->fresh()->resolved);
        $this->assertNull($elsewhere->fresh()->duplicate_of_defect_id);

        $this->artisan('defects:consolidate-duplicates', ['--apply' => true, '--actor' => (string) $actor->id])->assertSuccessful()->expectsOutputToContain('No open duplicate');
    }

    public function test_two_checklist_lines_with_one_name_never_merge_even_through_a_bridging_report(): void
    {
        $apparatus = $this->apparatus();
        $hoseOne = $this->defect($apparatus, 'Rear', 'Hose', ['compartment_key' => 'rear', 'item_key' => 'rear-1', 'first_reported_at' => now()->subDays(3)]);
        $bridge = $this->defect($apparatus, 'rear ', 'HOSE', ['first_reported_at' => now()->subDays(2)]);
        $hoseTwo = $this->defect($apparatus, 'REAR', 'hose', ['compartment_key' => 'rear', 'item_key' => 'rear-2', 'first_reported_at' => now()->subDay()]);

        $groups = app(DuplicateDefectFinder::class)->groups((int) $apparatus->id);

        $this->assertCount(1, $groups);
        $this->assertSame($hoseOne->id, $groups[0]['survivor']->id);
        $this->assertSame([$bridge->id], array_map(static fn (ApparatusDefect $d): int => (int) $d->id, $groups[0]['duplicates']));
        $this->assertNotContains($hoseTwo->id, array_map(static fn (ApparatusDefect $d): int => (int) $d->id, $groups[0]['duplicates']));

        // The service refuses it too, for any pair in the request.
        $this->assertRefused(fn () => app(DefectConsolidationService::class)->merge($hoseOne, [$hoseTwo], $this->reviewerUser(), 'Same hose'));
        $this->assertRefused(fn () => app(DefectConsolidationService::class)->merge($bridge, [$hoseOne, $hoseTwo], $this->reviewerUser(), 'Same hose'));
        $this->assertSame(0, ApparatusDefect::query()->where('resolved', true)->count());
    }

    public function test_the_command_carries_on_past_a_failed_group_and_exits_non_zero(): void
    {
        $apparatus = $this->apparatus();
        $actor = $this->reviewerUser();
        $failing = $this->defect($apparatus, 'Cab', 'Radio', ['first_reported_at' => now()->subDays(2)]);
        $failingDuplicate = $this->defect($apparatus, 'cab', 'radio');
        $survivor = $this->defect($apparatus, 'Rear', 'Axe', ['first_reported_at' => now()->subDays(2)]);
        $duplicate = $this->defect($apparatus, 'rear', 'AXE');
        // Make the first group's write fail inside the database.
        DB::statement("CREATE TRIGGER fail_one_merge BEFORE UPDATE ON apparatus_defects WHEN OLD.id = {$failingDuplicate->id} BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");

        $this->artisan('defects:consolidate-duplicates', ['--apply' => true, '--actor' => (string) $actor->id])
            ->expectsOutputToContain('failed, rolled back')
            ->expectsOutputToContain('1 group(s) failed')
            ->assertFailed();

        $this->assertFalse((bool) $failingDuplicate->fresh()->resolved);
        $this->assertNull($failingDuplicate->fresh()->duplicate_of_defect_id);
        $this->assertFalse((bool) $failing->fresh()->resolved);
        $this->assertSame($survivor->id, $duplicate->fresh()->duplicate_of_defect_id);
    }

    public function test_every_status_surface_reads_a_merged_duplicate_as_merged_never_as_a_fix_or_a_no_action_call(): void
    {
        $apparatus = $this->apparatus();
        $survivor = $this->defect($apparatus, 'Cab', 'Radio');
        $duplicate = $this->defect($apparatus, 'Cab', 'Radio set');
        $dismissed = $this->defect($apparatus, 'Cab', 'Map', ['action_status' => DefectActionStatus::DISMISSED, 'resolution_notes' => 'Not on this rig']);
        app(DefectConsolidationService::class)->merge($survivor, [$duplicate], $this->reviewerUser(), 'Same radio');
        $merged = $duplicate->fresh();

        $this->assertFalse($merged->isClosedNoAction());
        $this->assertSame([$dismissed->id], ApparatusDefect::query()->closedNoAction()->pluck('id')->all());
        $this->assertSame(0, ApparatusDefect::query()->fixed()->count());
        $this->assertSame('Merged into issue #'.$survivor->id, $merged->attributedActionLabel());
        $this->assertSame('Merged', $merged->actionLabel());
        $this->assertSame(DefectFollowUpState::MERGED, DefectFollowUpState::for($merged));
        $this->assertSame([$merged->id], DefectFollowUpState::applyFilter(ApparatusDefect::query(), DefectFollowUpState::MERGED)->pluck('id')->all());
        $this->assertSame([$dismissed->id], DefectFollowUpState::applyFilter(ApparatusDefect::query(), DefectFollowUpState::CLOSED_NO_ACTION)->pluck('id')->all());
        $this->assertSame('Merged into issue #'.$survivor->id, app(EmployeeInspectionHistoryService::class)->defectStageLabel($merged));
        $this->assertSame('merged', DefectActionStatus::closureKindFor($merged->action_status, $merged->resolution_type));
    }

    private function reviewerUser(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $user->assignRole(Role::findOrCreate('logistics_admin', 'web'));

        return $user;
    }

    private function assertRefused(callable $merge): void
    {
        try {
            $merge();
            $this->fail('The merge should have been refused.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function apparatus(string $unit = 'E2'): Apparatus
    {
        $this->sequence++;

        return Apparatus::create(['name' => 'Engine '.$unit, 'unit_id' => $unit.'-'.$this->sequence, 'designation' => $unit, 'type' => 'Engine', 'vehicle_number' => 'TEST-'.$unit.$this->sequence, 'status' => 'In Service']);
    }

    /** @param array<string, mixed> $attributes */
    private function defect(Apparatus $apparatus, string $compartment, string $item, array $attributes = []): ApparatusDefect
    {
        return ApparatusDefect::query()->create([
            'apparatus_id' => $apparatus->id, 'compartment' => $compartment, 'item' => $item, 'status' => 'open',
            'issue_type' => 'damaged', 'action_status' => DefectActionStatus::REPORTED, 'first_reported_at' => now(), ...$attributes,
        ]);
    }

    private function inspection(Apparatus $apparatus, User $actor): ApparatusInspection
    {
        return ApparatusInspection::query()->create(['apparatus_id' => $apparatus->id, 'actor_user_id' => $actor->id, 'operator_name' => 'Test Member', 'rank' => 'Captain', 'review_status' => 'pending_review', 'results' => []]);
    }

    private function observe(ApparatusDefect $defect, ApparatusInspection $inspection): void
    {
        ApparatusDefectObservation::query()->create(['apparatus_defect_id' => $defect->id, 'apparatus_inspection_id' => $inspection->id, 'actor_user_id' => $inspection->actor_user_id, 'observation' => 'confirmed_again']);
    }

    private function participant(ApparatusDefect $defect, User $user, mixed $at = null): void
    {
        $at ??= now();
        ApparatusDefectParticipant::query()->create(['apparatus_defect_id' => $defect->id, 'user_id' => $user->id, 'enrollment_source' => 'reported', 'first_participated_at' => $at, 'last_participated_at' => $at]);
    }
}
