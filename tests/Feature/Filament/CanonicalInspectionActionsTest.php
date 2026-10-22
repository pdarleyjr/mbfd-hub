<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\ApparatusServiceTicketCategory;
use App\Enums\ApparatusServiceTicketPriority;
use App\Filament\Resources\DefectResource\Pages\ListDefects;
use App\Filament\Resources\InspectionResource\Pages\ViewInspection;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Inspections\IssueMessageService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

/** Per-issue corrective actions, issue threads and the inspection thread on the canonical inspection page. */
final class CanonicalInspectionActionsTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;

    private Apparatus $rig;

    private User $reporter;

    private User $admin;

    private ApparatusInspection $inspection;

    private ApparatusDefect $defect;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // Logistics administrators pass every gate; members and view-only users keep their real rights.
        Gate::before(fn (User $user): ?bool => $user->hasRole('logistics_admin') ? true : null);
        $this->rig = $this->rig();
        $this->reporter = $this->member('Riley Reporter');
        $this->admin = $this->reviewer();
        $this->inspection = $this->inspectionBy($this->rig, $this->reporter);
        $this->defect = $this->openDefect($this->rig, $this->inspection, $this->reporter);
        $this->actAs($this->admin);
    }

    // ------------------------------------------------------------------ actions

    public function test_acknowledge_changes_state_and_writes_one_event_with_a_member_message(): void
    {
        $this->page()
            ->callAction('acknowledgeIssue', ['idempotency_key' => 'ack-1', 'member_message' => 'Logistics has your report.'], ['defect' => $this->defect->id])
            ->assertHasNoActionErrors();

        $this->assertSame('acknowledged', $this->defect->fresh()->action_status);
        $this->assertSame(1, $this->events('acknowledged'));
        $this->assertSame(1, ApparatusDefectEvent::query()->count());
        $this->assertDatabaseHas('apparatus_issue_messages', [
            'apparatus_defect_id' => $this->defect->id, 'visibility' => 'member', 'body' => 'Logistics has your report.',
        ]);
        Notification::assertSentTo($this->reporter, InspectionEventNotification::class);
    }

    public function test_the_same_key_submitted_twice_writes_one_event(): void
    {
        $page = $this->page();
        foreach (range(1, 2) as $attempt) {
            $page->callAction('acknowledgeIssue', ['idempotency_key' => 'double-click', 'member_message' => 'On it.'], ['defect' => $this->defect->id]);
        }

        $this->assertSame(1, $this->events('acknowledged'));
        $this->assertSame(1, ApparatusIssueMessage::query()->where('apparatus_defect_id', $this->defect->id)->count());
    }

    public function test_update_action_records_replacement_details_and_the_prefilled_member_message(): void
    {
        $page = $this->page()
            ->callAction('updateIssue', [
                'idempotency_key' => 'upd-1',
                'action_status' => 'replacement_available',
                'details' => ['vendor_reference' => 'PO-4471', 'expected_delivery' => '2026-10-30', 'pickup_instructions' => 'Ask at the Logistics window.'],
                'member_message' => 'A replacement Portable radio battery is available in Logistics. Please contact Support Services or arrange pickup.',
            ], ['defect' => $this->defect->id])
            ->assertHasNoActionErrors();

        $defect = $this->defect->fresh();
        $this->assertSame('replacement_available', $defect->action_status);
        $this->assertSame('PO-4471', $defect->action_details['vendor_reference']);
        $this->assertSame(1, $this->events('replacement_available'));

        $page->mountAction('updateIssue', ['defect' => $this->defect->id])->assertActionDataSet([
            'action_status' => 'replacement_available',
            'details' => ['vendor_reference' => 'PO-4471', 'pickup_instructions' => 'Ask at the Logistics window.'],
            'member_message' => 'A replacement Portable radio battery is available in Logistics. Please contact Support Services or arrange pickup.',
        ]);
    }

    public function test_resolve_requires_type_and_notes_then_resolves_once(): void
    {
        $this->page()
            ->callAction('resolveIssue', ['idempotency_key' => 'res-0', 'resolution_notes' => ''], ['defect' => $this->defect->id])
            ->assertHasActionErrors(['resolution_type', 'resolution_notes']);
        $this->assertSame(0, ApparatusDefectEvent::query()->count());

        $this->page()
            ->callAction('resolveIssue', [
                'idempotency_key' => 'res-1', 'resolution_type' => 'replacement_installed',
                'resolution_notes' => 'New battery installed and tested.', 'member_message' => 'Fixed. Thank you for reporting it.',
            ], ['defect' => $this->defect->id])
            ->assertHasNoActionErrors();

        $defect = $this->defect->fresh();
        $this->assertSame('resolved', $defect->action_status);
        $this->assertTrue($defect->resolved);
        $this->assertSame('replacement_installed', $defect->resolution_type);
        $this->assertSame(1, $this->events('resolved'));
        Notification::assertSentTo($this->reporter, InspectionEventNotification::class);
    }

    public function test_dismiss_then_reopen_keeps_one_history(): void
    {
        $this->page()
            ->callAction('dismissIssue', ['idempotency_key' => 'dis-1', 'reason' => 'Logged against the wrong rig.'], ['defect' => $this->defect->id])
            ->assertHasNoActionErrors();
        $this->assertSame('dismissed', $this->defect->fresh()->action_status);

        $this->page()
            ->callAction('reopenIssue', ['idempotency_key' => 'reo-1', 'reason' => 'The report was correct after all.'], ['defect' => $this->defect->id])
            ->assertHasNoActionErrors();

        $this->assertSame('reported', $this->defect->fresh()->action_status);
        $this->assertSame([1, 1], [$this->events('dismissed'), $this->events('reopened')]);
    }

    public function test_verify_correction_resolves_when_the_latest_observation_appears_corrected(): void
    {
        $next = $this->inspectionBy($this->rig, $this->reporter, '2026-10-20 09:00:00');
        $this->observe($this->defect, $next, 'appears_corrected', $this->reporter);

        Livewire::test(ViewInspection::class, ['record' => $next->getRouteKey()])
            ->assertSeeHtml('data-issue-action="verifyCorrection"')
            ->callAction('verifyCorrection', ['idempotency_key' => 'ver-1', 'resolution_type' => 'item_located', 'notes' => 'Found in the officer compartment.'], ['defect' => $this->defect->id])
            ->assertHasNoActionErrors();

        $this->assertSame('resolved', $this->defect->fresh()->action_status);
        $this->assertSame(1, $this->events('correction_verified'));
    }

    public function test_classify_impact_and_service_ticket_each_write_one_event(): void
    {
        $page = $this->page()
            ->callAction('classifyImpact', ['idempotency_key' => 'cls-1', 'operational_impact' => 'needs_repair'], ['defect' => $this->defect->id])
            ->assertHasNoActionErrors();
        $this->assertSame('needs_repair', $this->defect->fresh()->operational_impact);
        $this->assertSame(1, $this->events('impact_classified'));
        $this->assertSame('In Service', $this->rig->fresh()->status);

        $page->callAction('linkServiceTicket', [
            'idempotency_key' => 'tkt-1', 'title' => 'Radio battery missing - E2', 'description' => 'The cab radio battery is missing.',
            'category' => ApparatusServiceTicketCategory::RepairMechanical->value, 'priority' => ApparatusServiceTicketPriority::Attention->value,
        ], ['defect' => $this->defect->id])->assertHasNoActionErrors();

        $defect = $this->defect->fresh();
        $this->assertNotNull($defect->service_ticket_id);
        $this->assertSame(1, $this->events('ticket_linked'));
        $this->assertFalse((bool) $defect->resolved, 'A ticket never resolves the issue.');
    }

    public function test_only_the_actions_that_apply_to_the_current_status_are_offered(): void
    {
        $this->assertSame(
            ['acknowledgeIssue', 'updateIssue', 'resolveIssue', 'classifyImpact', 'linkServiceTicket', 'dismissIssue'],
            $this->offered($this->page()->html()),
        );

        $this->defect->update(['action_status' => 'acknowledged']);
        $this->assertNotContains('acknowledgeIssue', $this->offered($this->page()->html()));
        $this->assertNotContains('reopenIssue', $this->offered($this->page()->html()));

        $this->defect->update(['action_status' => 'resolved']);
        $this->assertSame(['reopenIssue'], $this->offered($this->page()->html()));
    }

    // ----------------------------------------------------------- authorization

    public function test_a_viewer_who_cannot_manage_issues_sees_and_runs_no_actions(): void
    {
        $this->actAs($this->viewOnly());

        $page = $this->page();
        $this->assertSame([], $this->offered($page->html()));
        $page->assertDontSee('Messages with the member')
            ->mountAction('acknowledgeIssue', ['defect' => $this->defect->id])
            ->assertActionNotMounted('acknowledgeIssue');

        $this->assertSame(0, ApparatusDefectEvent::query()->count());
        $this->assertSame('reported', $this->defect->fresh()->action_status);
    }

    public function test_a_member_cannot_open_the_page_at_all(): void
    {
        $this->actAs($this->reporter);

        Livewire::test(ViewInspection::class, ['record' => $this->inspection->getRouteKey()])->assertForbidden();
        $this->assertSame(0, ApparatusDefectEvent::query()->count());
    }

    public function test_a_defect_id_from_another_vehicle_is_rejected_and_never_reaches_the_service(): void
    {
        $otherRig = $this->rig('Engine 9', 'E9');
        $otherInspection = $this->inspectionBy($otherRig, $this->member('Other Member'));
        $foreign = $this->openDefect($otherRig, $otherInspection, null, 'Foreign pry bar');

        $this->assertNotFound(fn () => $this->page()->callAction('acknowledgeIssue', ['idempotency_key' => 'forge-1'], ['defect' => $foreign->id]));
        $this->assertNotFound(fn () => $this->page()->call('postIssueMessage', $foreign->id, (string) Str::uuid()));

        $this->assertSame(0, ApparatusDefectEvent::query()->count());
        $this->assertSame(0, ApparatusIssueMessage::query()->count());
        $this->assertSame('reported', $foreign->fresh()->action_status);
    }

    public function test_a_defect_of_this_vehicle_that_this_inspection_never_observed_is_rejected(): void
    {
        $unobserved = $this->openDefect($this->rig, $this->inspectionBy($this->rig, $this->reporter, '2026-10-18 09:00:00'), null, 'Hose reel');
        ApparatusDefect::query()->whereKey($unobserved->id)->update(['apparatus_inspection_id' => null]);
        DB::table('apparatus_defect_observations')->where('apparatus_defect_id', $unobserved->id)->delete();

        $this->assertNotFound(fn () => $this->page()->callAction('resolveIssue', [
            'idempotency_key' => 'unobs-1', 'resolution_type' => 'other', 'resolution_notes' => 'Should not apply.',
        ], ['defect' => $unobserved->id]));

        $this->assertSame('reported', $unobserved->fresh()->action_status);
    }

    // ------------------------------------------------------------------ threads

    public function test_internal_notes_are_labelled_for_reviewers_and_hidden_from_the_member(): void
    {
        $id = $this->defect->id;
        $clientId = (string) Str::uuid();

        $page = $this->page()
            ->set("issueDrafts.{$id}.body", 'Vendor says two weeks. Do not promise a date.')
            ->set("issueDrafts.{$id}.visibility", 'internal')
            ->call('postIssueMessage', $id, $clientId)
            ->set("issueDrafts.{$id}.body", 'Vendor says two weeks. Do not promise a date.')
            ->set("issueDrafts.{$id}.visibility", 'internal')
            ->call('postIssueMessage', $id, $clientId);

        $page->assertSee('Internal note')->assertSee('Vendor says two weeks. Do not promise a date.');
        $this->assertSame(1, ApparatusIssueMessage::query()->where('visibility', 'internal')->count(), 'Same client id sent twice is one message.');
        $this->assertCount(0, app(IssueMessageService::class)->threadFor($this->reporter, $this->defect->fresh()));
    }

    public function test_a_member_visible_message_defaults_to_the_member(): void
    {
        $id = $this->defect->id;

        $this->page()
            ->set("issueDrafts.{$id}.body", 'Please leave the old battery at the station.')
            ->call('postIssueMessage', $id, (string) Str::uuid())
            ->assertSee('Member-visible');

        $this->assertDatabaseHas('apparatus_issue_messages', ['apparatus_defect_id' => $id, 'visibility' => 'member', 'author_user_id' => $this->admin->id]);
        $this->assertCount(1, app(IssueMessageService::class)->threadFor($this->reporter, $this->defect->fresh()));
    }

    public function test_an_empty_message_is_refused(): void
    {
        $this->page()->call('postIssueMessage', $this->defect->id, (string) Str::uuid());

        $this->assertSame(0, ApparatusIssueMessage::query()->count());
    }

    public function test_reply_to_member_posts_to_the_general_inspection_thread(): void
    {
        $page = $this->page()
            ->assertSeeHtml('id="messages"')
            ->callAction('replyToMember', ['client_message_id' => (string) Str::uuid(), 'body' => 'Thanks for the thorough checkout.'])
            ->assertHasNoActionErrors();

        $message = ApparatusIssueMessage::query()->sole();
        $this->assertNull($message->apparatus_defect_id);
        $this->assertSame($this->inspection->id, (int) $message->apparatus_inspection_id);
        $this->assertSame('member', $message->visibility);
        $page->assertSee('Thanks for the thorough checkout.');
        $this->assertCount(1, app(IssueMessageService::class)->threadFor($this->reporter, $this->inspection));
    }

    public function test_reply_to_member_is_hidden_from_viewers_who_cannot_review(): void
    {
        $this->actAs($this->viewOnly());

        $this->page()->assertActionHidden('replyToMember');
    }

    public function test_the_timeline_uses_plain_labels_for_every_new_event_type(): void
    {
        $page = $this->page();
        $page->callAction('acknowledgeIssue', ['idempotency_key' => 't1'], ['defect' => $this->defect->id]);
        $page->callAction('updateIssue', ['idempotency_key' => 't2', 'action_status' => 'repair_scheduled', 'details' => []], ['defect' => $this->defect->id]);
        $page->callAction('classifyImpact', ['idempotency_key' => 't3', 'operational_impact' => 'non_blocking'], ['defect' => $this->defect->id]);
        $page->callAction('resolveIssue', ['idempotency_key' => 't4', 'resolution_type' => 'other', 'resolution_notes' => 'Done.'], ['defect' => $this->defect->id]);
        $page->callAction('reopenIssue', ['idempotency_key' => 't5', 'reason' => 'Came back.'], ['defect' => $this->defect->id]);

        $html = $this->page()->html();
        foreach (['Acknowledged: ', 'Repair scheduled: ', 'Operational impact classified: ', 'Resolved: ', 'Reopened: '] as $label) {
            $this->assertStringContainsString($label.'Portable radio battery', $html);
        }
        $this->assertStringNotContainsString('impact_classified', $html);
    }

    // ---------------------------------------------------- defect list resolution

    public function test_mark_resolved_on_the_defect_list_goes_through_the_service(): void
    {
        $list = Livewire::test(ListDefects::class)->call('loadTable');

        $list->callTableAction('mark_resolved', $this->defect, data: ['resolution_notes' => 'Only notes.'])
            ->assertHasTableActionErrors(['resolution_type']);
        $this->assertSame('reported', $this->defect->fresh()->action_status);

        $list->callTableAction('mark_resolved', $this->defect, data: [
            'idempotency_key' => 'list-1', 'resolution_type' => 'spare_issued', 'resolution_notes' => 'Spare battery issued.',
            'member_message' => 'A spare battery was issued to your rig.',
        ])->assertHasNoTableActionErrors();

        $defect = $this->defect->fresh();
        $this->assertSame('resolved', $defect->action_status);
        $this->assertSame('spare_issued', $defect->resolution_type);
        $this->assertSame($this->admin->id, $defect->resolved_by_user_id);
        $this->assertSame(1, $this->events('resolved'));
        Notification::assertSentTo($this->reporter, InspectionEventNotification::class);
    }

    // ------------------------------------------------------------------ helpers

    /** Livewire surfaces an abort(404) raised inside an action as the exception itself. */
    private function assertNotFound(\Closure $attempt): void
    {
        try {
            $result = $attempt();
        } catch (NotFoundHttpException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->assertInstanceOf(Testable::class, $result);
        $result->assertNotFound();
    }

    private function page(): Testable
    {
        return Livewire::test(ViewInspection::class, ['record' => $this->inspection->getRouteKey()]);
    }

    private function events(string $type): int
    {
        return ApparatusDefectEvent::query()->where('apparatus_defect_id', $this->defect->id)->where('event_type', $type)->count();
    }

    /** @return list<string> action names offered on the issue cards, in page order */
    private function offered(string $html): array
    {
        preg_match_all('/data-issue-action="([A-Za-z]+)"/', $html, $matches);

        return $matches[1];
    }

    private function viewOnly(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate('view_only_inspections', 'web');
        $role->givePermissionTo(array_map(
            fn (string $name): Permission => Permission::findOrCreate($name, 'web'),
            ['view_any_apparatus', 'view_apparatus', 'view_any_inspection', 'view_inspection'],
        ));
        $user = $this->member('View Only');
        $user->assignRole($role);

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();
    }
}
