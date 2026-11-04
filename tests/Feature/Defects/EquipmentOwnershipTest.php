<?php

declare(strict_types=1);

namespace Tests\Feature\Defects;

use App\Enums\AccountStatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusInspection;
use App\Models\StretcherIssueReport;
use App\Models\User;
use App\Services\Captain5\Captain5AdministrationService;
use App\Services\Captain5\Captain5IssueScope;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Services\Defects\DefectOwnership;
use App\Support\Dashboards\DashboardQuery;
use App\Support\Dashboards\IssueSourceRegistry;
use App\Support\OperationalDisplayWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\TestCase;

/**
 * One responsible queue per equipment issue, decided by the equipment (docs/unified-activity-and-ownership.md),
 * member "it's fixed", and standalone stretcher reports. Findings go through the real Daily Checkout endpoint.
 */
final class EquipmentOwnershipTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private const STRETCHER_BATTERY = 'compartment_b-item-12'; // Rescue, Stretcher compartment: Spare Battery

    private const DEWALT = 'compartment_b-item-6';             // Rescue: Dewalt K-Saw with Spare battery

    private const RESCUE_SCBA = 'compartment_d-item-4';        // Rescue: SCBAs

    private const RESCUE_AMBIGUOUS = 'compartment_d-item-11';  // Rescue: LP CO MONITOR (ambiguous)

    private const ENGINE2_LIFEPAK = 'comp_r1-item-1';          // Engine 2: Life-Pak

    private User $captain;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildCaptain5World('2026-10-20 10:00:00');
        $this->captain = $this->officer('Captain', 'Captain Alpha');
        $this->seat($this->captain, 'Captain 5', 'A Shift');
    }

    public function test_mixed_rescue_findings_split_into_two_issues_each_in_exactly_one_queue(): void
    {
        $member = $this->newMember('Firefighter Reporter');
        [$stretcher, $dewalt] = $this->ordered($this->reportMissing($this->rescue, $member, [self::STRETCHER_BATTERY, self::DEWALT]), [self::STRETCHER_BATTERY, self::DEWALT]);

        self::assertSame(1, ApparatusInspection::query()->where('apparatus_id', $this->rescue->id)->count(), 'One inspection');
        self::assertSame(ApparatusDefect::OWNER_CAPTAIN5, $stretcher->responsible_owner);
        self::assertSame(ApparatusDefect::OWNER_LOGISTICS, $dewalt->responsible_owner, 'A DeWalt battery on a Rescue is Logistics\'');

        self::assertSame([$stretcher->id], $this->captain5QueueIds());
        $board = $this->logisticsBoardRefs();
        self::assertContains('apparatus_defect:'.$dewalt->id, $board);
        self::assertNotContains('apparatus_defect:'.$stretcher->id, $board, 'Never in both work queues');

        // Both stay in the unit's history that every member can read.
        $this->actAs($member);
        $open = collect($this->getJson('/api/public/apparatuses/'.$this->rescue->id.'/findings')->assertOk()->json('open'))->keyBy('id');
        self::assertSame('captain5', $open[$stretcher->id]['owner']);
        self::assertSame('logistics', $open[$dewalt->id]['owner']);
    }

    public function test_a_medical_item_on_an_engine_belongs_to_captain5(): void
    {
        [$lifepak] = $this->reportMissing($this->unit, $this->newMember('Engine Crew'), [self::ENGINE2_LIFEPAK]);

        self::assertSame(ApparatusDefect::OWNER_CAPTAIN5, $lifepak->responsible_owner);
        self::assertSame([$lifepak->id], $this->captain5QueueIds());
        self::assertNotContains('apparatus_defect:'.$lifepak->id, $this->logisticsBoardRefs());
        self::assertDatabaseMissing('admin_alert_events', ['related_type' => 'apparatus_defect', 'related_id' => $lifepak->id]);
    }

    public function test_scba_on_a_rescue_is_logistics_and_captain5_cannot_act_on_it(): void
    {
        [$scba] = $this->reportMissing($this->rescue, $this->newMember('Rescue Crew'), [self::RESCUE_SCBA]);

        self::assertSame(ApparatusDefect::OWNER_LOGISTICS, $scba->responsible_owner);
        self::assertSame([], $this->captain5QueueIds());
        $this->actAs($this->captain);
        $this->forgetCaptain5Memo();
        $this->getJson('/api/captain5/issues/'.$scba->id)->assertForbidden();
        $this->postJson('/api/captain5/issues/'.$scba->id.'/actions', [
            'action' => 'acknowledge', 'expected_state' => DefectCorrectiveActionService::stateToken($scba), 'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
    }

    public function test_a_repeat_report_keeps_one_issue_its_owner_and_alerts_nobody_again(): void
    {
        [$first] = $this->reportMissing($this->rescue, $this->newMember('Rescue Crew'), [self::STRETCHER_BATTERY]);
        $alerts = DB::table('inspection_notification_deliveries')->count();
        $this->travel(1)->days();
        [$again] = $this->reportMissing($this->rescue, $this->newMember('Next Shift'), [self::STRETCHER_BATTERY]);

        self::assertSame($first->id, $again->id);
        self::assertSame(1, ApparatusDefect::query()->where('item_key', self::STRETCHER_BATTERY)->count());
        self::assertSame(ApparatusDefect::OWNER_CAPTAIN5, $again->responsible_owner);
        self::assertSame(1, ApparatusDefectEvent::query()->where('apparatus_defect_id', $first->id)->where('event_type', 'reported')->count());
        self::assertSame(0, ApparatusDefectEvent::query()->where('apparatus_defect_id', $first->id)->where('event_type', 'owner_transferred')->count());
        self::assertSame($alerts, DB::table('inspection_notification_deliveries')->count(), 'Observed again is history only');
    }

    public function test_an_administrators_classification_moves_the_open_issue_to_the_right_queue_with_an_event(): void
    {
        [$monitor] = $this->reportMissing($this->rescue, $this->newMember('Rescue Crew'), [self::RESCUE_AMBIGUOUS]);
        self::assertSame(ApparatusDefect::OWNER_LOGISTICS, $monitor->responsible_owner, 'Ambiguous equipment waits with Logistics');
        self::assertSame('unclassified', $monitor->owner_basis);

        app(Captain5AdministrationService::class)->classify($this->captain5Administrator(), 'rescue', self::RESCUE_AMBIGUOUS, 'LP CO MONITOR', 'medical', 'LifePak CO-oximetry module, patient care.');

        self::assertSame(ApparatusDefect::OWNER_CAPTAIN5, $monitor->fresh()->responsible_owner);
        $event = ApparatusDefectEvent::query()->where('apparatus_defect_id', $monitor->id)->where('event_type', 'owner_transferred')->sole();
        self::assertSame(['logistics', 'captain5'], [$event->details['from'], $event->details['to']]);
        self::assertNull($event->actor_user_id, 'The system moved it');
        self::assertSame([$monitor->id], $this->captain5QueueIds());
    }

    public function test_logistics_can_send_an_issue_to_captain5_and_reopen_returns_it_to_its_equipment_owner(): void
    {
        [$dewalt] = $this->reportMissing($this->rescue, $this->newMember('Rescue Crew'), [self::DEWALT]);
        $admin = $this->superAdmin();
        $result = app(IssueSourceRegistry::class)->get('apparatus_defect')->perform(
            $admin, (string) $dewalt->id, 'send_to_captain5', ['internal_note' => 'Captain 5 tracks this saw.'], DefectCorrectiveActionService::stateToken($dewalt),
        );
        self::assertTrue($result->succeeded(), $result->message);

        $moved = $dewalt->fresh();
        self::assertSame([ApparatusDefect::OWNER_CAPTAIN5, 'manual'], [$moved->responsible_owner, $moved->owner_basis]);
        self::assertSame((int) $admin->id, (int) $moved->owner_set_by_user_id);
        self::assertSame([$dewalt->id], $this->captain5QueueIds());
        self::assertNotContains('apparatus_defect:'.$dewalt->id, $this->logisticsBoardRefs());

        $engine = app(DefectCorrectiveActionService::class);
        $engine->resolve($dewalt->id, $admin, ['resolution_type' => 'replacement_installed', 'resolution_notes' => 'New saw.'], (string) Str::uuid());
        $engine->reopen($dewalt->id, $admin, ['reason' => 'Wrong blade size.'], (string) Str::uuid());
        self::assertSame(ApparatusDefect::OWNER_LOGISTICS, $dewalt->fresh()->responsible_owner, 'Reopened: back with the owner its equipment belongs to');
    }

    public function test_the_release_backfill_reports_first_and_then_writes_each_owner_once(): void
    {
        $member = $this->newMember('Rescue Crew');
        [$stretcher, $dewalt] = $this->ordered($this->reportMissing($this->rescue, $member, [self::STRETCHER_BATTERY, self::DEWALT]), [self::STRETCHER_BATTERY, self::DEWALT]);
        [$lifepak] = $this->reportMissing($this->unit, $member, [self::ENGINE2_LIFEPAK]);
        $ids = [$stretcher->id, $dewalt->id, $lifepak->id];
        // The rows as the previous release left them: no owner; Captain 5 had cleared the LifePak for Logistics.
        ApparatusDefect::query()->whereKey($ids)->update(['responsible_owner' => null, 'owner_basis' => null]);
        ApparatusDefect::query()->whereKey($lifepak->id)->update(['captain5_cleared_at' => now(), 'captain5_cleared_by_user_id' => $this->captain->id]);

        $this->artisan('defects:project-ownership', ['--verify' => true])->assertFailed();
        $this->artisan('defects:project-ownership', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Issues whose queue changes');
        $this->artisan('defects:project-ownership', ['--dry-run' => true, '--summary' => true])->assertSuccessful()
            ->expectsOutputToContain('DRY RUN')->doesntExpectOutputToContain('Issues whose queue changes');
        self::assertSame(3, ApparatusDefect::query()->whereKey($ids)->whereNull('responsible_owner')->count(), 'A dry run writes nothing');

        $this->artisan('defects:project-ownership', ['--summary' => true])->assertSuccessful();
        $this->artisan('defects:project-ownership', ['--verify' => true])->assertSuccessful()->expectsOutputToContain('Every open issue has exactly one owner.');
        self::assertSame(ApparatusDefect::OWNER_CAPTAIN5, $stretcher->fresh()->responsible_owner);
        self::assertSame(ApparatusDefect::OWNER_LOGISTICS, $dewalt->fresh()->responsible_owner);
        self::assertSame([ApparatusDefect::OWNER_LOGISTICS, 'manual'], [$lifepak->fresh()->responsible_owner, $lifepak->fresh()->owner_basis], 'A person\'s clear stands');
        self::assertSame(0, ApparatusDefectEvent::query()->where('event_type', 'owner_transferred')->count(), 'The backfill is quiet');

        $this->artisan('defects:project-ownership')->assertSuccessful();
        self::assertSame(0, ApparatusDefectEvent::query()->where('event_type', 'owner_transferred')->count(), 'Idempotent');
    }

    public function test_a_member_who_finds_a_routine_missing_item_resolves_it_once(): void
    {
        $reporter = $this->newMember('Reporter');
        [$dewalt] = $this->reportMissing($this->rescue, $reporter, [self::DEWALT]);
        $finder = $this->newMember('Finder');
        $this->actAs($finder);
        $key = (string) Str::uuid();
        $url = '/api/public/apparatus-issues/'.$dewalt->id.'/member-correction';

        $this->postJson($url, ['client_action_id' => $key, 'outcome' => 'found', 'note' => 'It was on Engine 2.'])->assertOk()->assertJsonPath('data.result', 'resolved');
        $this->postJson($url, ['client_action_id' => $key, 'outcome' => 'found'])->assertOk()->assertJsonPath('data.result', 'resolved');

        $closed = $dewalt->fresh();
        self::assertSame('resolved', $closed->action_status);
        self::assertSame((int) $finder->id, (int) $closed->resolved_by_user_id);
        self::assertSame('item_located', $closed->resolution_type);
        $event = ApparatusDefectEvent::query()->where('apparatus_defect_id', $dewalt->id)->where('event_type', 'member_resolved')->sole();
        self::assertSame((int) $finder->id, (int) $event->actor_user_id);
        $key2 = 'defect:'.$dewalt->id.':resolved:'.$event->id;
        self::assertDatabaseHas('inspection_notification_deliveries', ['event_key' => $key2, 'recipient_user_id' => $reporter->id]);
        self::assertDatabaseMissing('inspection_notification_deliveries', ['event_key' => $key2, 'recipient_user_id' => $finder->id]);
        self::assertSame('In Service', $this->rescue->fresh()->status, 'Never changes service status');

        $this->postJson($url, ['client_action_id' => (string) Str::uuid(), 'outcome' => 'found'])->assertStatus(409)->assertJsonPath('code', 'ISSUE_CLOSED');
        $this->actAs($reporter);
        $this->postJson($url, ['client_action_id' => $key, 'outcome' => 'found'])->assertStatus(409)->assertJsonPath('code', 'CLIENT_ACTION_CONFLICT');
    }

    public function test_a_damaged_or_out_of_service_item_needs_the_owner_to_verify(): void
    {
        [$scba] = $this->reportMissing($this->rescue, $this->newMember('Reporter'), [self::RESCUE_SCBA]);
        ApparatusDefect::query()->whereKey($scba->id)->update(['issue_type' => 'damaged', 'operational_impact' => 'out_of_service']);
        $this->actAs($this->newMember('Member'));

        $this->postJson('/api/public/apparatus-issues/'.$scba->id.'/member-correction', ['client_action_id' => (string) Str::uuid(), 'outcome' => 'repaired'])
            ->assertOk()->assertJsonPath('data.result', 'awaiting_verification');
        $pending = $scba->fresh();
        self::assertFalse((bool) $pending->resolved, 'Not closed by a member');
        self::assertNotNull($pending->correction_reported_at);
        $open = collect($this->getJson('/api/public/apparatuses/'.$this->rescue->id.'/findings')->json('open'))->firstWhere('id', $scba->id);
        self::assertNotNull($open['member_correction']['pending']);
        self::assertFalse($open['member_correction']['eligible']);

        app(DefectCorrectiveActionService::class)->verifyCorrection($scba->id, $this->superAdmin(), [], (string) Str::uuid());
        self::assertSame('resolved', $scba->fresh()->action_status);
        self::assertSame('In Service', $this->rescue->fresh()->status);
    }

    public function test_someone_without_an_employee_profile_cannot_resolve_or_report(): void
    {
        [$dewalt] = $this->reportMissing($this->rescue, $this->newMember('Reporter'), [self::DEWALT]);
        $this->actAs(User::factory()->create(['account_status' => AccountStatus::Active]));

        $this->postJson('/api/public/apparatus-issues/'.$dewalt->id.'/member-correction', ['client_action_id' => (string) Str::uuid(), 'outcome' => 'found'])->assertForbidden();
        $this->postJson('/api/public/apparatuses/'.$this->unit->id.'/stretcher-issues', ['client_submission_id' => (string) Str::uuid(), 'problem' => 'battery', 'description' => 'Dead', 'unusable' => false])->assertForbidden();
        self::assertFalse((bool) $dewalt->fresh()->resolved);
        self::assertSame(0, StretcherIssueReport::query()->count());
    }

    public function test_a_standalone_stretcher_report_is_captain5s_issue_without_an_inspection(): void
    {
        Storage::fake('public');
        $ems = $this->officer('Captain', 'EMS Captain');
        $this->seat($ems, 'EMS', 'D Shift');
        $inspections = ApparatusInspection::query()->count();
        $this->actAs($this->newMember('Engine Crew'));
        $submission = (string) Str::uuid();
        $send = function (array $extra = []) use (&$submission) {
            return $this->post('/api/public/apparatuses/'.$this->unit->id.'/stretcher-issues', [
                'client_submission_id' => $submission, 'problem' => 'battery', 'description' => 'Battery will not hold a charge.', 'unusable' => '0',
                'photo' => UploadedFile::fake()->image('stretcher.jpg', 40, 40), ...$extra,
            ], ['Accept' => 'application/json']);
        };

        $created = $send()->assertCreated()->assertJsonPath('data.attached', false);
        $defect = ApparatusDefect::query()->findOrFail($created->json('data.issue_id'));
        self::assertSame([ApparatusDefect::STRETCHER_REPORT_COMPARTMENT, 'stretcher:battery', ApparatusDefect::OWNER_CAPTAIN5, 'stretcher_report'],
            [$defect->compartment_key, $defect->item_key, $defect->responsible_owner, $defect->owner_basis]);
        self::assertNull($defect->apparatus_inspection_id);
        self::assertSame($inspections, ApparatusInspection::query()->count(), 'No inspection is created');
        Storage::disk('public')->assertExists((string) $defect->photo_path);
        $report = StretcherIssueReport::query()->sole();
        self::assertDatabaseHas('inspection_notification_deliveries', ['event_key' => 'captain5:stretcher:'.$report->id.':reported', 'recipient_user_id' => $this->captain->id]);
        self::assertDatabaseMissing('inspection_notification_deliveries', ['event_key' => 'captain5:stretcher:'.$report->id.':unusable']);

        // A retry of the same form is the same report.
        $send()->assertOk()->assertJsonPath('data.issue_id', $defect->id);
        self::assertSame(1, StretcherIssueReport::query()->count());

        // Someone else reports the same problem and it cannot be used now: one issue, two reports, EMS Captain told.
        $this->actAs($this->newMember('Next Crew'));
        $submission = (string) Str::uuid();
        $send(['unusable' => '1', 'description' => 'Will not raise at all.'])->assertCreated()->assertJsonPath('data.attached', true)->assertJsonPath('data.issue_id', $defect->id);
        self::assertSame(2, StretcherIssueReport::query()->count());
        self::assertSame('significant', $defect->fresh()->severity);
        self::assertSame(1, ApparatusDefectEvent::query()->where('apparatus_defect_id', $defect->id)->where('event_type', 'observed_again')->count());
        $second = StretcherIssueReport::query()->latest('id')->firstOrFail();
        self::assertDatabaseHas('inspection_notification_deliveries', ['event_key' => 'captain5:stretcher:'.$second->id.':unusable', 'recipient_user_id' => $ems->id]);

        self::assertSame([$defect->id], $this->captain5QueueIds());
        self::assertNotContains('apparatus_defect:'.$defect->id, $this->logisticsBoardRefs());
        self::assertSame('In Service', $this->unit->fresh()->status, 'Never changes service status');
    }

    public function test_a_stretcher_report_never_closes_on_a_members_word(): void
    {
        $this->actAs($this->newMember('Engine Crew'));
        $id = $this->postJson('/api/public/apparatuses/'.$this->unit->id.'/stretcher-issues', [
            'client_submission_id' => (string) Str::uuid(), 'problem' => 'loading', 'description' => 'Legs stick when loading.', 'unusable' => false,
        ])->assertCreated()->json('data.issue_id');

        $this->postJson('/api/public/apparatus-issues/'.$id.'/member-correction', ['client_action_id' => (string) Str::uuid(), 'outcome' => 'repaired'])
            ->assertOk()->assertJsonPath('data.result', 'awaiting_verification')
            ->assertJsonPath('data.message', 'Thanks. Captain 5 will verify it before the issue is closed.');
    }

    public function test_captain5_sees_and_verifies_a_members_report_that_it_is_fixed(): void
    {
        [$lifepak] = $this->reportMissing($this->unit, $this->newMember('Engine Crew'), [self::ENGINE2_LIFEPAK]);
        ApparatusDefect::query()->whereKey($lifepak->id)->update(['issue_type' => 'damaged']);
        $this->actAs($this->newMember('Finder'));
        $this->postJson('/api/public/apparatus-issues/'.$lifepak->id.'/member-correction', ['client_action_id' => (string) Str::uuid(), 'outcome' => 'repaired'])
            ->assertOk()->assertJsonPath('data.result', 'awaiting_verification');

        $this->actAs($this->captain);
        $this->forgetCaptain5Memo();
        $this->getJson('/api/captain5/workspace')->assertOk()->assertJsonPath('counts.awaiting_verification', 1);
        $detail = $this->getJson('/api/captain5/issues/'.$lifepak->id)->assertOk();
        self::assertContains('verify', $detail->json('issue.actions'));
        $this->postJson('/api/captain5/issues/'.$lifepak->id.'/actions', [
            'action' => 'verify', 'expected_state' => DefectCorrectiveActionService::stateToken($lifepak->fresh()), 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk();
        self::assertSame('resolved', $lifepak->fresh()->action_status);
    }

    public function test_a_clear_made_by_the_previous_image_after_a_rollback_is_adopted_not_lost(): void
    {
        [$lifepak] = $this->reportMissing($this->unit, $this->newMember('Engine Crew'), [self::ENGINE2_LIFEPAK]);
        self::assertSame(ApparatusDefect::OWNER_CAPTAIN5, $lifepak->responsible_owner);
        // The previous image's "Clear, this is for Logistics" writes only these two columns.
        $this->travel(1)->minutes();
        ApparatusDefect::query()->whereKey($lifepak->id)->update(['captain5_cleared_at' => now(), 'captain5_cleared_by_user_id' => $this->captain->id]);

        $this->artisan('defects:project-ownership', ['--pending' => true])->assertSuccessful();

        $adopted = $lifepak->fresh();
        self::assertSame([ApparatusDefect::OWNER_LOGISTICS, 'manual'], [$adopted->responsible_owner, $adopted->owner_basis]);
        self::assertSame([], $this->captain5QueueIds());
        self::assertContains('apparatus_defect:'.$lifepak->id, $this->logisticsBoardRefs(), 'In exactly one queue, never neither');
        $this->artisan('defects:project-ownership', ['--pending' => true])->assertSuccessful()->doesntExpectOutputToContain('Owners written');
    }

    public function test_before_the_projection_adopts_it_a_clear_by_the_previous_image_is_logistics_work_not_in_neither_queue(): void
    {
        [$lifepak] = $this->reportMissing($this->unit, $this->newMember('Engine Crew'), [self::ENGINE2_LIFEPAK]);
        $this->travel(1)->minutes();
        ApparatusDefect::query()->whereKey($lifepak->id)->update(['captain5_cleared_at' => now(), 'captain5_cleared_by_user_id' => $this->captain->id]);

        self::assertSame(ApparatusDefect::OWNER_CAPTAIN5, $lifepak->fresh()->responsible_owner, 'Not adopted yet');
        self::assertSame([], $this->captain5QueueIds());
        self::assertContains('apparatus_defect:'.$lifepak->id, $this->logisticsBoardRefs());
        self::assertContains($lifepak->id, app(Captain5IssueScope::class)->sentToLogisticsQuery()->pluck('id')->all(), 'Captain 5 can still bring it back');
    }

    public function test_an_issue_on_a_unit_outside_captain5s_checklist_types_is_logistics_by_rule_not_unclassified(): void
    {
        // The reserve engine uses the "default" checklist, which has no medical scope.
        $defect = ApparatusDefect::query()->create([
            'apparatus_id' => $this->reserve->id, 'compartment' => 'Cab', 'item' => 'Radio mount', 'status' => 'open', 'issue_type' => 'damaged', 'reported_date' => now(),
        ]);

        $decision = app(DefectOwnership::class)->decide($defect);

        self::assertSame([ApparatusDefect::OWNER_LOGISTICS, DefectOwnership::BASIS_NOT_MEDICAL], [$decision->owner, $decision->basis]);
    }

    public function test_a_row_written_by_the_previous_image_is_owned_by_the_scheduled_projection(): void
    {
        [$scba] = $this->reportMissing($this->rescue, $this->newMember('Rescue Crew'), [self::RESCUE_SCBA]);
        ApparatusDefect::query()->whereKey($scba->id)->update(['responsible_owner' => null, 'owner_basis' => null, 'owner_set_at' => null]);

        $this->artisan('defects:project-ownership', ['--pending' => true])->assertSuccessful()->expectsOutputToContain('Owners written: 1');
        self::assertSame(ApparatusDefect::OWNER_LOGISTICS, $scba->fresh()->responsible_owner);
    }

    public function test_a_stretcher_report_cannot_be_cleared_for_logistics(): void
    {
        $this->actAs($this->newMember('Engine Crew'));
        $id = (int) $this->postJson('/api/public/apparatuses/'.$this->unit->id.'/stretcher-issues', [
            'client_submission_id' => (string) Str::uuid(), 'problem' => 'armrest', 'description' => 'Armrest will not latch.', 'unusable' => false,
        ])->assertCreated()->json('data.issue_id');
        $defect = ApparatusDefect::query()->findOrFail($id);

        $this->actAs($this->captain);
        $this->forgetCaptain5Memo();
        self::assertNotContains('send_to_logistics', $this->getJson('/api/captain5/issues/'.$id)->assertOk()->json('issue.clearing_actions'));
        $this->postJson('/api/captain5/issues/'.$id.'/actions', [
            'action' => 'send_to_logistics', 'expected_state' => DefectCorrectiveActionService::stateToken($defect), 'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(422);
        self::assertSame(ApparatusDefect::OWNER_CAPTAIN5, $defect->fresh()->responsible_owner);
    }

    /**
     * @param  list<ApparatusDefect>  $defects
     * @param  list<string>  $keys
     * @return list<ApparatusDefect> in the order of $keys
     */
    private function ordered(array $defects, array $keys): array
    {
        $byKey = collect($defects)->keyBy('item_key');

        return array_map(static fn (string $key): ApparatusDefect => $byKey[$key], $keys);
    }

    /** @return list<int> ids in the Captain 5 open queue */
    private function captain5QueueIds(): array
    {
        $this->actAs($this->captain);
        $this->forgetCaptain5Memo();

        return array_map('intval', array_column($this->getJson('/api/captain5/workspace')->assertOk()->json('issues'), 'id'));
    }

    /** @return list<string> refs on the Logistics (Admin Overview) equipment board */
    private function logisticsBoardRefs(): array
    {
        $board = new DashboardQuery(OperationalDisplayWindow::forNow(now()->setTimezone('America/New_York')->format('Y-m-d H:i:s').' America/New_York'));

        return array_map(static fn ($row): string => $row->ref(), app(IssueSourceRegistry::class)->get('apparatus_defect')->rows($this->superAdmin(), $board, 50));
    }
}
