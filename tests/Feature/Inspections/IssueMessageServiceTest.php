<?php

declare(strict_types=1);

namespace Tests\Feature\Inspections;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectParticipant;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Inspections\IssueMessageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

final class IssueMessageServiceTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;

    private IssueMessageService $messages;

    private Apparatus $rig;

    private User $owner;

    private User $admin;

    private ApparatusInspection $inspection;

    private ApparatusDefect $defect;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->messages = app(IssueMessageService::class);
        $this->rig = $this->rig();
        $this->owner = $this->member('Olive Owner');
        $this->admin = $this->reviewer();
        $this->inspection = $this->inspectionBy($this->rig, $this->owner);
        $this->defect = $this->openDefect($this->rig, $this->inspection, $this->owner);
    }

    public function test_the_inspection_owner_posts_to_the_general_thread_and_an_unrelated_member_cannot(): void
    {
        $message = $this->messages->post($this->owner, [
            'inspection_id' => $this->inspection->id,
            'body' => '  Please check the left-side compartment too.  ',
        ]);

        $this->assertSame('Please check the left-side compartment too.', $message->body);
        $this->assertSame(ApparatusIssueMessage::VISIBILITY_MEMBER, $message->visibility);
        $this->assertNull($message->apparatus_defect_id);
        $this->assertCount(1, $this->messages->threadFor($this->owner, $this->inspection));

        $stranger = $this->member('Una Unrelated');
        $this->expectException(AuthorizationException::class);
        $this->messages->post($stranger, ['inspection_id' => $this->inspection->id, 'body' => 'Hello?']);
    }

    public function test_an_unrelated_member_cannot_read_the_thread(): void
    {
        $this->messages->post($this->owner, ['inspection_id' => $this->inspection->id, 'body' => 'Private-ish.']);

        $this->expectException(AuthorizationException::class);
        $this->messages->threadFor($this->member('Una Unrelated'), $this->inspection);
    }

    public function test_a_member_reply_on_an_issue_thread_enrolls_them_as_a_replier_and_notifies_reviewers(): void
    {
        $second = $this->member('Sky Second');
        $secondInspection = $this->inspectionBy($this->rig, $second, '2026-10-20 09:00:00');
        $this->observe($this->defect, $secondInspection, 'confirmed_again', $second);

        $message = $this->messages->post($second, [
            'inspection_id' => $secondInspection->id,
            'defect_id' => $this->defect->id,
            'body' => 'Still missing this morning.',
        ]);

        $this->assertSame($this->defect->id, $message->apparatus_defect_id);
        $participant = ApparatusDefectParticipant::query()->where('user_id', $second->id)->sole();
        $this->assertSame('replied', $participant->enrollment_source);
        Notification::assertSentTo($this->admin, InspectionEventNotification::class, function (InspectionEventNotification $notification) use ($secondInspection): bool {
            return $notification->kind === 'member_reply'
                && str_contains($notification->url, '/inspections/'.$secondInspection->id.'#issue-'.$this->defect->id);
        });
        Notification::assertNotSentTo($second, InspectionEventNotification::class);
    }

    public function test_a_participant_who_owns_no_related_inspection_can_still_reply_but_a_stranger_cannot(): void
    {
        $participant = $this->member('Pia Participant');
        $this->participant($this->defect, $participant, 'confirmed');

        $message = $this->messages->post($participant, ['defect_id' => $this->defect->id, 'body' => 'I saw it too.']);
        $this->assertSame($participant->id, $message->author_user_id);

        $this->expectException(AuthorizationException::class);
        $this->messages->post($this->member('Una Unrelated'), ['defect_id' => $this->defect->id, 'body' => 'Hi.']);
    }

    public function test_a_defect_from_another_apparatus_is_rejected_even_with_an_owned_inspection(): void
    {
        $otherRig = $this->rig('Engine 9', 'E9');
        $otherInspection = $this->inspectionBy($otherRig, $this->member('Other Crew'));
        $foreign = $this->openDefect($otherRig, $otherInspection, null);

        $this->expectException(AuthorizationException::class);
        $this->messages->post($this->owner, [
            'inspection_id' => $this->inspection->id,
            'defect_id' => $foreign->id,
            'body' => 'Forged cross-vehicle post.',
        ]);
    }

    public function test_a_defect_the_member_never_touched_is_rejected(): void
    {
        $otherRig = $this->rig('Engine 9', 'E9');
        $foreign = $this->openDefect($otherRig, $this->inspectionBy($otherRig, $this->member('Other Crew')), null);

        $this->expectException(AuthorizationException::class);
        $this->messages->post($this->owner, ['defect_id' => $foreign->id, 'body' => 'Nope.']);
    }

    public function test_a_forged_or_missing_inspection_id_is_denied_for_members_and_not_found_for_reviewers(): void
    {
        $other = $this->member('Other Crew');
        $theirs = $this->inspectionBy($this->rig, $other, '2026-10-20 09:00:00');

        try {
            $this->messages->post($this->owner, ['inspection_id' => $theirs->id, 'body' => 'Forged.']);
            $this->fail('A member must not post to another member\'s inspection.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->messages->post($this->owner, ['inspection_id' => 999_999, 'body' => 'Ghost.']);
            $this->fail('A missing inspection must look the same as a forbidden one.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(ModelNotFoundException::class);
        $this->messages->post($this->admin, ['inspection_id' => 999_999, 'visibility' => 'member', 'body' => 'Ghost.']);
    }

    public function test_a_member_cannot_write_an_internal_note(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->messages->post($this->owner, [
            'inspection_id' => $this->inspection->id,
            'visibility' => 'internal',
            'body' => 'Sneaky.',
        ]);
    }

    public function test_internal_notes_are_hidden_from_members_and_never_notify(): void
    {
        $this->messages->post($this->admin, ['defect_id' => $this->defect->id, 'visibility' => 'internal', 'body' => 'Vendor says six weeks.']);
        $this->messages->post($this->admin, ['defect_id' => $this->defect->id, 'visibility' => 'member', 'body' => 'We are working on it.']);
        $this->messages->post($this->admin, ['inspection_id' => $this->inspection->id, 'visibility' => 'internal', 'body' => 'Check his history.']);

        $memberView = $this->messages->threadFor($this->owner, $this->defect);
        $this->assertSame(['We are working on it.'], $memberView->pluck('body')->all());
        $this->assertCount(0, $this->messages->threadFor($this->owner, $this->inspection));
        $this->assertSame(
            ['Vendor says six weeks.', 'We are working on it.'],
            $this->messages->threadFor($this->admin, $this->defect)->pluck('body')->all(),
        );
        Notification::assertSentToTimes($this->owner, InspectionEventNotification::class, 1);
        Notification::assertSentTo($this->owner, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => ! str_contains($n->body, 'Vendor'));
    }

    public function test_body_must_be_present_not_blank_and_at_most_4000_characters(): void
    {
        foreach (['', '   ', "\n\t", str_repeat('x', 4001)] as $body) {
            try {
                $this->messages->post($this->owner, ['inspection_id' => $this->inspection->id, 'body' => $body]);
                $this->fail('An invalid body must be refused.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $ok = $this->messages->post($this->owner, ['inspection_id' => $this->inspection->id, 'body' => str_repeat('x', 4000)]);
        $this->assertSame(4000, strlen($ok->body));
    }

    public function test_a_reviewer_must_choose_the_audience_and_a_suspended_reviewer_is_treated_as_a_member(): void
    {
        try {
            $this->messages->post($this->admin, ['inspection_id' => $this->inspection->id, 'body' => 'No audience chosen.']);
            $this->fail('A reviewer must pick member or internal explicitly.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('visibility', $exception->errors());
        }
        $this->assertSame(0, ApparatusIssueMessage::query()->count());

        $this->admin->forceFill(['account_status' => \App\Enums\AccountStatus::Disabled])->save();
        $this->assertFalse($this->messages->canPost($this->admin->fresh(), $this->defect, 'internal'));
    }

    public function test_a_target_is_required(): void
    {
        $this->expectException(ValidationException::class);
        $this->messages->post($this->admin, ['body' => 'Nowhere to go.']);
    }

    public function test_a_client_message_id_makes_a_retry_return_the_same_row(): void
    {
        $clientId = (string) Str::uuid();
        $first = $this->messages->post($this->owner, ['inspection_id' => $this->inspection->id, 'body' => 'Once.', 'client_message_id' => $clientId]);
        $second = $this->messages->post($this->owner, ['inspection_id' => $this->inspection->id, 'body' => 'Once.', 'client_message_id' => $clientId]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ApparatusIssueMessage::query()->count());

        $this->expectException(AuthorizationException::class);
        $this->messages->post($this->admin, ['inspection_id' => $this->inspection->id, 'visibility' => 'member', 'body' => 'Hijack.', 'client_message_id' => $clientId]);
    }

    public function test_corrections_append_a_new_row_and_leave_the_original(): void
    {
        $original = $this->messages->post($this->owner, ['inspection_id' => $this->inspection->id, 'body' => 'Wrong unit number.']);
        $correction = $this->messages->correct($this->owner, $original->id, 'Correct: it was Engine 2.');

        $this->assertNotSame($original->id, $correction->id);
        $this->assertSame($original->id, $correction->corrects_message_id);
        $this->assertSame($original->apparatus_inspection_id, $correction->apparatus_inspection_id);
        $this->assertSame($original->visibility, $correction->visibility);
        $this->assertCount(2, $this->messages->threadFor($this->owner, $this->inspection));
        $this->assertSame('Wrong unit number.', $original->fresh()->body);

        $reviewerNote = $this->messages->post($this->admin, ['inspection_id' => $this->inspection->id, 'visibility' => 'internal', 'body' => 'Typo.']);
        $adminCorrection = $this->messages->correct($this->admin, $reviewerNote->id, 'Typo fixed.');
        $this->assertSame('internal', $adminCorrection->visibility);

        try {
            $this->messages->correct($this->member('Other Crew'), $original->id, 'Not mine.');
            $this->fail('Only the author or a reviewer may correct.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_messages_cannot_be_edited_or_deleted(): void
    {
        $message = $this->messages->post($this->owner, ['inspection_id' => $this->inspection->id, 'body' => 'Fixed forever.']);

        $this->expectException(LogicException::class);
        $message->update(['body' => 'Edited']);
    }

    public function test_a_reviewer_message_notifies_the_right_audience_with_the_right_kind(): void
    {
        $participant = $this->member('Pia Participant');
        $this->participant($this->defect, $participant, 'confirmed');

        // Issue thread: participants, minus the author, as an issue update.
        $this->messages->post($this->admin, ['defect_id' => $this->defect->id, 'visibility' => 'member', 'body' => 'Replacement ordered.']);
        Notification::assertSentTo($participant, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === 'issue_member_update');
        Notification::assertSentTo($this->owner, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === 'issue_member_update');
        Notification::assertNotSentTo($this->admin, InspectionEventNotification::class);

        // General thread: the inspection owner only.
        $this->messages->post($this->admin, ['inspection_id' => $this->inspection->id, 'visibility' => 'member', 'body' => 'Thanks for the checkout.']);
        Notification::assertSentTo($this->owner, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === 'inspection_message'
            && str_contains($n->url, '/employee/my-vehicle-inspections/'.$this->inspection->id));

        // A clarification request is labelled as such.
        $this->messages->post($this->admin, ['inspection_id' => $this->inspection->id, 'visibility' => 'member', 'body' => 'Which compartment?', 'kind' => 'clarification_requested']);
        Notification::assertSentTo($this->owner, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === 'clarification_requested');

        // A caller that notifies on its own can switch the automatic send off.
        $before = count(Notification::sent($this->owner, InspectionEventNotification::class));
        $this->messages->post($this->admin, ['inspection_id' => $this->inspection->id, 'visibility' => 'member', 'body' => 'Quiet note.', 'notify' => false]);
        $this->assertCount($before, Notification::sent($this->owner, InspectionEventNotification::class));
    }

    public function test_can_post_reports_the_same_rules_to_the_ui(): void
    {
        $stranger = $this->member('Una Unrelated');

        $this->assertTrue($this->messages->canPost($this->owner, $this->inspection));
        $this->assertFalse($this->messages->canPost($this->owner, $this->inspection, 'internal'));
        $this->assertFalse($this->messages->canPost($stranger, $this->inspection));
        $this->assertFalse($this->messages->canPost($stranger, $this->defect));
        $this->assertTrue($this->messages->canPost($this->admin, $this->defect, 'internal'));
    }
}
