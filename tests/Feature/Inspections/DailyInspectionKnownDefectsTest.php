<?php

declare(strict_types=1);

namespace Tests\Feature\Inspections;

use App\Enums\AccountStatus;
use App\Filament\Employee\Pages\MyVehicleInspections;
use App\Filament\Resources\DefectResource\Pages\ListDefects;
use App\Filament\Resources\InspectionResource\Pages\ViewInspection;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\ApparatusInspectionExceptionService;
use App\Services\Inspections\InspectionReviewService;
use App\Services\Inspections\IssueMessageService;
use App\Support\Fleet\InspectionReviewers;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Inspections\Concerns\BuildsDailyScenarioWorld;
use Tests\TestCase;

/**
 * Defects found by Agent F's independent review. Each test asserts the behaviour the
 * contract (docs/daily-inspection-continuity.md) requires and therefore FAILS until the
 * owning agent fixes it. Kept apart from the passing regression suites on purpose;
 * once green, fold these into the owner's suite.
 */
final class DailyInspectionKnownDefectsTest extends TestCase
{
    use BuildsDailyScenarioWorld;
    use RefreshDatabase;

    private User $alpha;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
        $this->buildWorld();
        $this->alpha = $this->newMember('Alpha Member');
        $this->reviewer = $this->newReviewer('Logistics Reviewer', ['view_any_defect', 'view_defect', 'update_defect']);
    }

    /** DEFECT: DefectResource "Mark Resolved" is still a bare update(): no event, no notification, no resolver. */
    public function test_the_defect_list_mark_resolved_action_goes_through_the_corrective_action_workflow(): void
    {
        $defect = $this->reportedDefect();
        $this->actAs($this->reviewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListDefects::class)
            ->callTableAction('mark_resolved', $defect, data: ['resolution_notes' => 'Replacement battery installed.']);

        $this->assertTrue($defect->fresh()->resolved, 'The action itself must still work.');
        $this->assertSame(1, ApparatusDefectEvent::query()->where('apparatus_defect_id', $defect->id)->where('event_type', 'resolved')->count(), 'A resolution needs an audit event.');
        $this->assertSame($this->reviewer->id, $defect->fresh()->resolved_by_user_id);
        $this->assertSame(1, $this->sentCount($this->alpha, 'issue_resolved'), 'The reporting member must be told it was resolved.');
    }

    /** DEFECT: Review on a member-flag or significant-damage hold from Inspection Exceptions is a 404. */
    public function test_reconciling_a_member_flag_hold_is_decided_or_refused_cleanly_never_a_404(): void
    {
        $this->actAs($this->alpha);
        $payload = $this->payload();
        $payload['review_flag'] = ['explanation' => 'Check the charger.', 'items' => [$this->itemRef($payload)]];
        $inspection = ApparatusInspection::query()->findOrFail($this->submit($payload)->assertCreated()->json('id'));
        $hold = ApparatusInspectionException::query()->where('apparatus_inspection_id', $inspection->id)->where('field', 'member_review_flag')->sole();

        try {
            app(ApparatusInspectionExceptionService::class)->reconcile($hold->id, $this->reviewer, [
                'action' => 'dismiss', 'reason' => 'Looked fine.', 'expected_current_value' => null,
            ]);
            $this->addToAssertionCount(1);
        } catch (ValidationException) {
            $this->addToAssertionCount(1); // a clear "use Approve on the inspection page" message is acceptable
        } catch (ModelNotFoundException $exception) {
            $this->fail('The Review action listed for a member-flag hold crashes with a 404: '.$exception->getMessage());
        }
    }

    /** DEFECT: the Approve action on a meter-held inspection fails silently (validation error with no place to show it). */
    public function test_approving_an_inspection_with_an_open_meter_hold_tells_the_reviewer_why_it_did_not_happen(): void
    {
        $this->actAs($this->alpha);
        $inspection = ApparatusInspection::query()->findOrFail(
            $this->submit($this->payload(null, 900, 401.2))->assertCreated()->assertJsonPath('processing_status', 'held_for_review')->json('id'),
        );
        $this->actAs($this->reviewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()]);
        try {
            $page->callAction('approveInspection', data: ['mark_out_of_service' => false]);
        } catch (\Throwable) {
            // a thrown halt is fine as long as the reviewer is told why
        }

        $this->assertSame('pending_review', $inspection->fresh()->review_status, 'The meter must still be decided first.');
        $this->assertStringContainsStringIgnoringCase('meter reading first', $page->html(), 'The reviewer must be told to decide the meter reading first.');
    }

    /** DEFECT: no path gives the member a reason for a rejection. */
    public function test_a_rejected_inspection_shows_the_member_a_member_visible_reason(): void
    {
        $this->actAs($this->alpha);
        $inspection = ApparatusInspection::query()->findOrFail(
            $this->submit($this->payload(null, 900, 401.2))->assertCreated()->json('id'),
        );

        app(InspectionReviewService::class)->reject($inspection->id, $this->reviewer, 'Wrong vehicle was inspected.');

        $this->actAs($this->alpha);
        $html = preg_replace('/\s+/', ' ', $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertOk()->getContent());
        $this->assertStringContainsString('Rejected', $html);
        $this->assertStringContainsString('Wrong vehicle was inspected.', $html, 'Contract 5.4: Rejected + member-visible reason.');
    }

    /** DEFECT: the member list says "Approved" for an automatic acceptance and "Pending review" for a hold. */
    public function test_the_member_inspection_list_uses_accepted_and_needs_approval_wording(): void
    {
        $this->actAs($this->alpha);
        $this->submit($this->payload())->assertCreated()->assertJsonPath('processing_status', 'accepted');
        $this->submit($this->payload(null, 900, 401.2))->assertCreated()->assertJsonPath('processing_status', 'held_for_review');

        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        $html = Livewire::test(MyVehicleInspections::class)->loadTable()->html();

        $this->assertStringContainsString('Needs approval', $html, 'A held inspection must read "Needs approval" in the member list.');
        $this->assertStringContainsString('Accepted', $html, 'An automatic acceptance must read "Accepted".');
        // "Approved"/"Pending review" legitimately remain in the filter menu (one occurrence each); a row badge would add more.
        $this->assertLessThanOrEqual(1, substr_count($html, 'Approved'), 'A row still reads "Approved".');
        $this->assertLessThanOrEqual(1, substr_count($html, 'Pending review'), 'A row still reads "Pending review".');
    }

    /** DEFECT: GET checklist shows one member's free-text reply to every other member. */
    public function test_open_findings_do_not_expose_a_members_own_reply_to_other_members(): void
    {
        $defect = $this->reportedDefect();
        app(IssueMessageService::class)->post($this->reviewer, ['defect_id' => $defect->id, 'visibility' => 'member', 'body' => 'LOGISTICS-UPDATE-FOR-CREW']);
        app(IssueMessageService::class)->post($this->alpha, ['defect_id' => $defect->id, 'body' => 'PRIVATE-REPLY-FROM-ALPHA-about-my-situation']);

        $stranger = $this->newMember('Stranger Member');
        $this->actAs($stranger);
        $findings = $this->getJson($this->checklistUrl())->assertOk()->json('open_findings');

        $this->assertCount(1, $findings);
        $this->assertSame('LOGISTICS-UPDATE-FOR-CREW', $findings[0]['latest_member_update']['text'] ?? null, 'Only Logistics updates belong in the shared checkout view.');
        $this->assertStringNotContainsString('PRIVATE-REPLY-FROM-ALPHA', json_encode($findings, JSON_THROW_ON_ERROR));
        $this->assertSame(2, ApparatusIssueMessage::query()->count());
    }

    /** DEFECT: the member inspection page issues about ten queries per finding (MemberInspectionConversation loops per issue). */
    public function test_the_member_inspection_page_does_not_issue_queries_per_finding(): void
    {
        $this->actAs($this->alpha);
        $this->submit($this->findingsPayload(2, 1020, 402.0))->assertCreated();
        $small = ApparatusInspection::query()->latest('id')->firstOrFail();
        $this->get('/employee/my-vehicle-inspections/'.$small->id)->assertOk();
        $smallQueries = $this->queryCount(fn () => $this->get('/employee/my-vehicle-inspections/'.$small->id)->assertOk());

        $this->travel(1)->days();
        $this->actAs($this->alpha);
        $this->submit($this->findingsPayload(10, 1050, 405.0))->assertCreated();
        $large = ApparatusInspection::query()->latest('id')->firstOrFail();
        $this->get('/employee/my-vehicle-inspections/'.$large->id)->assertOk();
        $largeQueries = $this->queryCount(fn () => $this->get('/employee/my-vehicle-inspections/'.$large->id)->assertOk());

        $this->assertLessThanOrEqual($smallQueries + 6, $largeQueries, "2 findings: {$smallQueries} queries; 10 findings: {$largeQueries} queries (N+1).");
    }

    /** DEFECT: a member who verified the item as Present is notified, but the link lands on a page with no such issue. */
    public function test_a_member_who_found_the_item_present_can_open_the_issue_their_resolved_notification_links_to(): void
    {
        $defect = $this->reportedDefect();
        $beta = $this->newMember('Beta Member');
        $this->travel(1)->days();
        $this->actAs($beta);
        $this->submit($this->payload(null, 1050, 405.0))->assertCreated()->assertJsonPath('processing_status', 'accepted');

        app(\App\Services\Defects\DefectCorrectiveActionService::class)->resolve($defect->id, $this->reviewer, [
            'resolution_type' => 'item_located', 'resolution_notes' => 'Found in the officer seat.',
        ]);

        $notification = Notification::sent($beta, InspectionEventNotification::class)->firstWhere('kind', 'issue_resolved');
        $this->assertNotNull($notification, 'The verifying member is a participant and must be told.');
        $this->assertStringContainsString('#issue-'.$defect->id, $notification->url);

        $html = $this->get(strtok($notification->url, '#'))->assertOk()->getContent();
        $this->assertStringContainsString('id="issue-'.$defect->id.'"', $html, 'The notification anchor must exist on the page it links to.');
    }

    /** DEFECT: an assigned administrator gets the member-oriented link (employee portal) on updates, but the admin link on resolution. */
    public function test_an_assigned_administrator_is_sent_the_admin_page_link_on_issue_updates(): void
    {
        $defect = $this->reportedDefect();
        $assignee = $this->newReviewer('Assigned Admin');
        app(\App\Services\Defects\DefectCorrectiveActionService::class)->updateAction($defect->id, $this->reviewer, [
            'action_status' => 'repair_scheduled', 'details' => ['assigned_to' => $assignee->id],
        ]);

        app(IssueMessageService::class)->post($this->reviewer, ['defect_id' => $defect->id, 'visibility' => 'member', 'body' => 'Repair booked for Friday.']);

        $notification = Notification::sent($assignee, InspectionEventNotification::class)->firstWhere('kind', 'issue_member_update');
        $this->assertNotNull($notification, 'The assigned administrator follows the issue.');
        $this->assertStringStartsWith('/admin/inspections/', $notification->url, 'A reviewer must be linked to the admin page, not the employee portal: '.$notification->url);
    }

    /** DEFECT: "Use a corrected value" can lower the fleet meter without the explicit reset confirmation. */
    public function test_correcting_a_meter_to_a_lower_value_also_needs_the_explicit_reset_confirmation(): void
    {
        $this->actAs($this->alpha);
        $inspection = ApparatusInspection::query()->findOrFail(
            $this->submit($this->payload(null, 900, 401.2))->assertCreated()->assertJsonPath('processing_status', 'held_for_review')->json('id'),
        );
        $hold = ApparatusInspectionException::query()->where('apparatus_inspection_id', $inspection->id)->where('field', 'miles')->sole();

        try {
            app(ApparatusInspectionExceptionService::class)->reconcile($hold->id, $this->reviewer, [
                'action' => 'correct', 'value' => 800, 'reason' => 'Typo.', 'expected_current_value' => 1000,
            ]);
            $this->fail('Contract 2.3: lowering the fleet reading must be an explicit, confirmed meter reset; "correct" lowered it silently to '.$this->unit->fresh()->current_miles.'.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirm_meter_reset', $exception->errors());
            $this->assertSame(1000, $this->unit->fresh()->current_miles);
        }
    }

    /** DEFECT: "may review" is role-based in InspectionReviewers but every decision is gated by capability (AdminCapabilityGate). */
    public function test_a_role_holder_without_the_fleet_manage_capability_is_not_treated_as_a_reviewer(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $half = User::factory()->create(['account_status' => AccountStatus::Active, 'name' => 'Role Without Capability']);
        $half->assignRole(Role::findOrCreate('logistics_admin', 'web'));
        $half->givePermissionTo([Permission::findOrCreate('admin.access', 'web'), Permission::findOrCreate('admin.fleet.view', 'web')]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $half = $half->fresh();

        $this->actAs($this->alpha);
        $payload = $this->payload();
        $payload['review_flag'] = ['explanation' => 'Look at the pump panel.', 'items' => [$this->itemRef($payload)]];
        $inspection = ApparatusInspection::query()->findOrFail($this->submit($payload)->assertCreated()->json('id'));
        app(IssueMessageService::class)->post($this->reviewer, ['inspection_id' => $inspection->id, 'visibility' => 'internal', 'body' => 'INTERNAL-ONLY-NOTE']);

        $this->assertFalse(Gate::forUser($half)->allows('approve', $inspection), 'The gate that guards every decision refuses this user.');
        $this->assertFalse(InspectionReviewers::canReview($half), 'The reviewer rule must agree with the gate.');
        $this->assertNotContains($half->id, InspectionReviewers::all()->pluck('id')->all(), 'They must not be sent reviewer notifications.');
        try {
            $thread = app(IssueMessageService::class)->threadFor($half, $inspection);
            $this->fail('A user who cannot decide must not read internal notes: '.$thread->pluck('body')->implode('|'));
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function reportedDefect(): ApparatusDefect
    {
        $this->actAs($this->alpha);
        $payload = $this->payload(null, null, null);
        $this->markItem($payload, 0, 0, 'Missing', ['notes' => 'Battery gone.']);
        $this->submit($payload)->assertCreated();

        return ApparatusDefect::query()->sole();
    }

    private function sentCount(User $user, string $kind): int
    {
        return Notification::sent($user, InspectionEventNotification::class)
            ->filter(static fn (InspectionEventNotification $notification): bool => $notification->kind === $kind)
            ->count();
    }
}
