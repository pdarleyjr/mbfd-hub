<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Filament\Employee\Pages\MyVehicleInspectionView;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Services\Inspections\IssueMessageService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

/** Member-safe conversation, acceptance state and issue status on the employee inspection page. */
final class MyVehicleInspectionMessagesTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;

    private Apparatus $rig;

    private User $owner;

    private User $reviewer;

    private ApparatusInspection $inspection;

    private ApparatusDefect $defect;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();

        $this->rig = $this->rig();
        $this->owner = $this->member('Olive Owner');
        $this->reviewer = $this->reviewer();
        $this->inspection = $this->inspectionBy($this->rig, $this->owner);
        // Submitted results are immutable through the model; the fixture shapes the stored evidence directly.
        DB::table('apparatus_inspections')->where('id', $this->inspection->id)->update(['results' => json_encode([[
            'id' => 'cab', 'name' => 'Cab',
            'items' => [['id' => 'cab-radio', 'name' => 'Portable radio battery', 'status' => 'Missing', 'notes' => null]],
        ]])]);
        $this->inspection = $this->inspection->fresh();
        $this->defect = $this->openDefect($this->rig, $this->inspection, $this->owner);
    }

    public function test_owner_sees_member_visible_messages_and_acceptance_but_never_internal_text(): void
    {
        $messages = app(IssueMessageService::class);
        $messages->post($this->reviewer, ['inspection_id' => $this->inspection->id, 'visibility' => 'member', 'body' => 'MEMBER-SAFE general message']);
        $messages->post($this->reviewer, ['inspection_id' => $this->inspection->id, 'visibility' => 'internal', 'body' => 'INTERNAL-GENERAL-SECRET']);
        $messages->post($this->reviewer, ['defect_id' => $this->defect->id, 'visibility' => 'member', 'body' => 'MEMBER-SAFE issue message']);
        $messages->post($this->reviewer, ['defect_id' => $this->defect->id, 'visibility' => 'internal', 'body' => 'INTERNAL-ISSUE-SECRET']);
        ApparatusInspectionReviewEvent::query()->create([
            'apparatus_inspection_id' => $this->inspection->id,
            'previous_status' => 'pending_review',
            'status' => 'approved',
            'internal_note' => 'INTERNAL-REVIEW-NOTE-SECRET',
            'changed_by_user_id' => $this->reviewer->id,
            'metadata' => ['internal' => 'INTERNAL-METADATA-SECRET'],
        ]);

        $html = $this->actingAs($this->owner)->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString('MEMBER-SAFE general message', $html);
        $this->assertStringContainsString('MEMBER-SAFE issue message', $html);
        $this->assertStringContainsString('Accepted', $html);
        $this->assertStringNotContainsString('INTERNAL-GENERAL-SECRET', $html);
        $this->assertStringNotContainsString('INTERNAL-ISSUE-SECRET', $html);
        $this->assertStringNotContainsString('INTERNAL-REVIEW-NOTE-SECRET', $html);
        $this->assertStringNotContainsString('INTERNAL-METADATA-SECRET', $html);
    }

    public function test_a_reviewer_who_also_owns_the_inspection_still_gets_no_internal_notes_in_the_member_portal(): void
    {
        $reviewerMember = $this->member('Rita Reviewer');
        $reviewerMember->assignRole(\Spatie\Permission\Models\Role::findOrCreate('logistics_admin', 'web'));
        $reviewerInspection = $this->inspectionBy($this->rig, $reviewerMember);
        app(IssueMessageService::class)->post($reviewerMember, ['inspection_id' => $reviewerInspection->id, 'visibility' => 'internal', 'body' => 'REVIEWER-INTERNAL-SECRET']);
        app(IssueMessageService::class)->post($reviewerMember, ['inspection_id' => $reviewerInspection->id, 'visibility' => 'member', 'body' => 'REVIEWER-MEMBER-VISIBLE']);

        $html = $this->actingAs($reviewerMember)->get($this->url($reviewerInspection))->assertOk()->getContent();

        $this->assertStringContainsString('REVIEWER-MEMBER-VISIBLE', $html);
        $this->assertStringNotContainsString('REVIEWER-INTERNAL-SECRET', $html);
    }

    public function test_page_has_the_anchors_that_notification_links_target(): void
    {
        $html = $this->actingAs($this->owner)->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString('id="messages"', $html);
        $this->assertStringContainsString('id="issue-'.$this->defect->id.'"', $html);

        $link = MyVehicleInspectionView::getUrl(['inspection' => $this->inspection->id], false, 'employee');
        $this->assertStringEndsWith('/employee/my-vehicle-inspections/'.$this->inspection->id, $link);
    }

    public function test_acceptance_shows_each_open_hold_in_plain_language(): void
    {
        $this->inspection->forceFill(['review_status' => 'pending_review', 'processing_status' => 'held_for_review'])->save();
        foreach ([['miles', 'rollback'], ['member_review_flag', 'member_flagged'], ['significant_damage', 'new_significant_damage']] as [$field, $reason]) {
            ApparatusInspectionException::query()->create([
                'apparatus_id' => $this->rig->id,
                'apparatus_inspection_id' => $this->inspection->id,
                'field' => $field,
                'reason' => $reason,
                'status' => 'open',
                'blocks_acceptance' => true,
            ]);
        }
        // A decided hold is no longer a reason.
        ApparatusInspectionException::query()->create([
            'apparatus_id' => $this->rig->id,
            'apparatus_inspection_id' => $this->inspection->id,
            'field' => 'engine_hours',
            'reason' => 'rollback',
            'status' => 'resolved',
            'blocks_acceptance' => true,
        ]);

        $html = $this->actingAs($this->owner)->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString('Needs approval: Mileage lower than last reading; You flagged this for review; New significant damage', preg_replace('/\s+/', ' ', $html));
        $this->assertStringNotContainsString('Engine hours lower than last reading', $html);
    }

    public function test_latest_logistics_message_is_highlighted_when_the_inspection_needs_approval(): void
    {
        $this->inspection->forceFill(['review_status' => 'pending_review', 'processing_status' => 'held_for_review'])->save();
        $messages = app(IssueMessageService::class);
        $messages->post($this->reviewer, ['inspection_id' => $this->inspection->id, 'visibility' => 'member', 'body' => 'OLDER question', 'kind' => 'clarification_requested']);
        $messages->post($this->reviewer, ['inspection_id' => $this->inspection->id, 'visibility' => 'member', 'body' => 'NEWEST question: which odometer did you read?', 'kind' => 'clarification_requested']);

        $html = $this->actingAs($this->owner)->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString('Latest message from Logistics', $html);
        $callout = substr($html, (int) strpos($html, 'clarification-title'), 700);
        $this->assertStringContainsString('NEWEST question', $callout);
        $this->assertStringNotContainsString('OLDER question', $callout);
    }

    public function test_rejected_inspection_shows_the_reason_from_member_visible_messages_only(): void
    {
        $this->inspection->forceFill(['review_status' => 'rejected', 'processing_status' => 'rejected'])->save();
        app(IssueMessageService::class)->post($this->reviewer, ['inspection_id' => $this->inspection->id, 'visibility' => 'member', 'body' => 'Wrong vehicle was inspected.']);
        app(IssueMessageService::class)->post($this->reviewer, ['inspection_id' => $this->inspection->id, 'visibility' => 'internal', 'body' => 'REJECT-INTERNAL-SECRET']);

        $html = $this->actingAs($this->owner)->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString('Rejected', $html);
        $this->assertStringContainsString('Reason from Logistics: Wrong vehicle was inspected.', preg_replace('/\s+/', ' ', $html));
        $this->assertStringNotContainsString('REJECT-INTERNAL-SECRET', $html);
    }

    public function test_issue_shows_its_current_action_status_first_reported_date_and_later_resolution_without_changing_the_observation(): void
    {
        $this->defect->forceFill(['action_status' => 'replacement_available'])->save();
        $html = $this->actingAs($this->owner)->get($this->url())->assertOk()->getContent();
        $this->assertStringContainsString('Replacement available', $html);
        $this->assertStringContainsString('First reported Oct 19, 2026', $html);
        $this->assertStringNotContainsString('Resolved on', $html);

        $this->defect->forceFill(['action_status' => 'resolved', 'resolved' => true, 'resolved_at' => '2026-10-21 15:00:00'])->save();
        $html = $this->actingAs($this->owner)->get($this->url())->assertOk()->getContent();
        $this->assertStringContainsString('Resolved on Oct 21, 2026', $html);
        // What the member recorded that day is not rewritten.
        $this->assertSame('Missing', $this->inspection->fresh()->results[0]['items'][0]['status']);
    }

    public function test_owner_replies_to_the_inspection_and_to_an_issue_with_member_visibility_only(): void
    {
        $page = $this->page($this->owner);

        $page->set('inspectionReply', 'It was the dash odometer.')->call('sendInspectionReply')->assertHasNoErrors();
        $page->set('issueReplies.'.$this->defect->id, 'Battery is still missing today.')->call('sendIssueReply', $this->defect->id)->assertHasNoErrors();

        $general = ApparatusIssueMessage::query()->where('apparatus_inspection_id', $this->inspection->id)->whereNull('apparatus_defect_id')->sole();
        $this->assertSame('It was the dash odometer.', $general->body);
        $this->assertSame('member', $general->visibility);
        $this->assertSame($this->owner->id, $general->author_user_id);
        $this->assertTrue(Str::isUuid((string) $general->client_message_id));

        $issue = ApparatusIssueMessage::query()->where('apparatus_defect_id', $this->defect->id)->sole();
        $this->assertSame('member', $issue->visibility);
        $this->assertSame($this->inspection->id, $issue->apparatus_inspection_id);

        $page->assertSee('It was the dash odometer.')->assertSee('Battery is still missing today.');
    }

    public function test_blank_and_oversized_replies_are_rejected_with_an_inline_error(): void
    {
        $page = $this->page($this->owner);

        $page->set('inspectionReply', '   ')->call('sendInspectionReply')->assertHasErrors(['inspectionReply']);
        $page->set('inspectionReply', str_repeat('x', 4001))->call('sendInspectionReply')->assertHasErrors(['inspectionReply']);
        $this->assertSame(0, ApparatusIssueMessage::query()->count());
    }

    public function test_unrelated_member_gets_403_on_the_page_and_cannot_post_through_a_livewire_call(): void
    {
        $stranger = $this->member('Una Unrelated');
        $this->actingAs($stranger)->get($this->url())->assertForbidden();

        // A component mounted for the owner cannot be driven from someone else's session.
        $page = $this->page($this->owner);
        $page->set('inspectionReply', 'hello');
        $this->actingAs($stranger);
        $this->bindCanonicalSessionToLivewireTestRequests();
        $page->call('sendInspectionReply')->assertForbidden();

        $this->actingAs($this->owner);
        $issuePage = $this->page($this->owner);
        $issuePage->set('issueReplies.'.$this->defect->id, 'hello');
        $this->actingAs($stranger);
        $this->bindCanonicalSessionToLivewireTestRequests();
        $issuePage->call('sendIssueReply', $this->defect->id)->assertForbidden();

        $this->assertSame(0, ApparatusIssueMessage::query()->count());
    }

    public function test_owner_cannot_write_to_an_issue_that_is_not_part_of_this_inspection(): void
    {
        $otherRig = $this->rig('Engine 9', 'E9');
        $otherInspection = $this->inspectionBy($otherRig, $this->member('Other Owner'));
        $foreignDefect = $this->openDefect($otherRig, $otherInspection, null, 'Axe');

        $page = $this->page($this->owner);
        $page->set('issueReplies.'.$foreignDefect->id, 'let me in')->call('sendIssueReply', $foreignDefect->id)->assertForbidden();

        $this->assertSame(0, ApparatusIssueMessage::query()->count());
    }

    public function test_a_retried_reply_with_the_same_client_message_id_is_stored_once(): void
    {
        $page = $this->page($this->owner);
        $page->set('inspectionReply', 'Sent once.')->call('sendInspectionReply');
        $this->assertSame(1, ApparatusIssueMessage::query()->count());

        $first = ApparatusIssueMessage::query()->sole();
        $again = app(IssueMessageService::class)->post($this->owner, [
            'inspection_id' => $this->inspection->id,
            'body' => 'Sent once.',
            'client_message_id' => $first->client_message_id,
        ]);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, ApparatusIssueMessage::query()->count());
    }

    private function url(?ApparatusInspection $inspection = null): string
    {
        return '/employee/my-vehicle-inspections/'.($inspection ?? $this->inspection)->id;
    }

    private function page(User $user): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAs($user);
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('employee'));

        return Livewire::test(MyVehicleInspectionView::class, ['inspection' => $this->inspection->id]);
    }
}
