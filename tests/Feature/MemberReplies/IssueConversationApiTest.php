<?php

declare(strict_types=1);

namespace Tests\Feature\MemberReplies;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Inspections\IssueMessageService;
use App\Services\MemberReplies\MemberReplyLedger;
use App\Support\MemberReplies\ReplySubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

/** docs/member-replies.md section 2.1: the issue conversation on the Daily Checkout issue page. */
final class IssueConversationApiTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;

    private const MESSAGE_ID = '6a8f0b9e-1c2d-4e3f-8a9b-0c1d2e3f4a51';

    private Apparatus $rig;

    private User $reporter;

    private User $admin;

    private ApparatusInspection $inspection;

    private ApparatusDefect $defect;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->rig = $this->rig();
        $this->reporter = $this->member('Rhea Reporter');
        $this->admin = $this->reviewer();
        $this->inspection = $this->inspectionBy($this->rig, $this->reporter);
        // openDefect enrolls the reporter as a participant.
        $this->defect = $this->openDefect($this->rig, $this->inspection, $this->reporter);
    }

    public function test_the_reporter_sees_staff_replies_but_never_internal_notes(): void
    {
        $messages = app(IssueMessageService::class);
        $messages->post($this->admin, ['defect_id' => $this->defect->id, 'visibility' => 'member', 'body' => 'A new battery is on order.']);
        $messages->post($this->admin, ['defect_id' => $this->defect->id, 'visibility' => 'internal', 'body' => 'Vendor invoice 4471, do not share.']);

        $this->actingAsCanonicalUser($this->reporter);
        $response = $this->getJson("/api/public/apparatus-issues/{$this->defect->id}/conversation")->assertOk()
            ->assertJsonPath('data.can_reply', true)
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.body', 'A new battery is on order.')
            ->assertJsonPath('data.messages.0.from_staff', true)
            ->assertJsonPath('data.messages.0.author', 'Logistics');
        $this->assertStringNotContainsString('Vendor invoice', $response->getContent());
        $this->assertStringNotContainsString('visibility', $response->getContent());

        // The public timeline tells this member they are on the issue.
        $this->getJson("/api/public/apparatus-issues/{$this->defect->id}/history")->assertOk()
            ->assertJsonPath('conversation.participant', true);
    }

    public function test_the_reporter_replies_once_per_client_id_staff_are_told_and_the_issue_awaits_staff(): void
    {
        $this->actingAsCanonicalUser($this->reporter);

        $this->postJson("/api/public/apparatus-issues/{$this->defect->id}/conversation", ['body' => 'Still dead this morning.', 'client_message_id' => self::MESSAGE_ID])
            ->assertCreated()
            ->assertJsonPath('data.messages.0.own', true)
            ->assertJsonPath('data.messages.0.author', 'You');
        $this->postJson("/api/public/apparatus-issues/{$this->defect->id}/conversation", ['body' => 'Still dead this morning.', 'client_message_id' => self::MESSAGE_ID])
            ->assertCreated()
            ->assertJsonCount(1, 'data.messages');

        $message = ApparatusIssueMessage::query()->sole();
        $this->assertSame(ApparatusIssueMessage::VISIBILITY_MEMBER, $message->visibility);
        $this->assertSame(ApparatusIssueMessage::CONTEXT_MEMBER, $message->author_context);
        Notification::assertSentTo($this->admin, InspectionEventNotification::class, fn (InspectionEventNotification $notification): bool => $notification->kind === 'member_reply');

        $ledger = app(MemberReplyLedger::class);
        $this->assertTrue($ledger->isAwaiting(ReplySubject::APPARATUS_DEFECT, $this->defect->id));
        $this->assertSame($this->reporter->id, $ledger->stateFor(ReplySubject::APPARATUS_DEFECT, [$this->defect->id])[$this->defect->id]->last_member_reply_user_id);

        // An internal note is not an answer; a member-visible reply from Logistics is.
        app(IssueMessageService::class)->post($this->admin, ['defect_id' => $this->defect->id, 'visibility' => 'internal', 'body' => 'Checking stock.']);
        $this->assertTrue($ledger->isAwaiting(ReplySubject::APPARATUS_DEFECT, $this->defect->id));
        app(IssueMessageService::class)->post($this->admin, ['defect_id' => $this->defect->id, 'visibility' => 'member', 'body' => 'Swapped it today.']);
        $this->assertFalse($ledger->isAwaiting(ReplySubject::APPARATUS_DEFECT, $this->defect->id));
        $this->assertSame([], $ledger->awaiting(ReplySubject::APPARATUS_DEFECT)->pluck('subject_id')->all());
    }

    public function test_a_member_who_acknowledged_the_issue_on_a_later_checkout_can_read_and_reply(): void
    {
        $second = $this->member('Sam Second');
        $later = $this->inspectionBy($this->rig, $second, '2026-10-20 09:00:00');
        $this->observe($this->defect, $later, 'confirmed_again', $second);
        $this->participant($this->defect, $second, 'confirmed', $later);

        $this->actingAsCanonicalUser($second);
        $this->getJson("/api/public/apparatus-issues/{$this->defect->id}/history")->assertOk()->assertJsonPath('conversation.participant', true);
        $this->postJson("/api/public/apparatus-issues/{$this->defect->id}/conversation", ['body' => 'Saw the same on B shift.', 'client_message_id' => self::MESSAGE_ID])
            ->assertCreated();

        // The reporter sees the other member's reply in the same thread.
        $this->actingAsCanonicalUser($this->reporter);
        $this->getJson("/api/public/apparatus-issues/{$this->defect->id}/conversation")->assertOk()
            ->assertJsonPath('data.messages.0.body', 'Saw the same on B shift.')
            ->assertJsonPath('data.messages.0.own', false)
            ->assertJsonPath('data.messages.0.from_staff', false);
    }

    public function test_a_member_who_is_not_on_the_issue_gets_403_for_read_and_reply(): void
    {
        $stranger = $this->member('Una Unrelated');
        $this->actingAsCanonicalUser($stranger);

        $this->getJson("/api/public/apparatus-issues/{$this->defect->id}/history")->assertOk()->assertJsonPath('conversation.participant', false);
        $this->getJson("/api/public/apparatus-issues/{$this->defect->id}/conversation")->assertForbidden();
        $this->postJson("/api/public/apparatus-issues/{$this->defect->id}/conversation", ['body' => 'Hi', 'client_message_id' => self::MESSAGE_ID])
            ->assertForbidden();
        $this->assertSame(0, ApparatusIssueMessage::query()->count());
    }

    public function test_a_reply_is_validated(): void
    {
        $this->actingAsCanonicalUser($this->reporter);

        $this->postJson("/api/public/apparatus-issues/{$this->defect->id}/conversation", ['body' => '', 'client_message_id' => self::MESSAGE_ID])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson("/api/public/apparatus-issues/{$this->defect->id}/conversation", ['body' => str_repeat('x', IssueMessageService::MAX_BODY + 1), 'client_message_id' => self::MESSAGE_ID])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson("/api/public/apparatus-issues/{$this->defect->id}/conversation", ['body' => 'Hello', 'client_message_id' => 'not-a-uuid'])
            ->assertUnprocessable()->assertJsonValidationErrors('client_message_id');
    }
}
