<?php

declare(strict_types=1);

namespace Tests\Feature\MemberReplies;

use App\Enums\AccountStatus;
use App\Enums\HubSupportTicketStatus;
use App\Models\HubSupportTicket;
use App\Models\SubmissionReplyState;
use App\Models\User;
use App\Services\HubSupport\HubSupportTicketWorkflowService;
use App\Services\MemberReplies\MemberReplyLedger;
use App\Support\MemberReplies\ReplySubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\Feature\EmployeeMessaging\BuildsEmployeeMessagingFixtures;
use Tests\TestCase;

/**
 * Workflows that already had a two-way thread now also feed the shared "awaiting staff" ledger
 * (docs/member-replies.md section 4): Employee <-> Logistics messages and Hub support issues.
 */
final class ExistingThreadLedgerTest extends TestCase
{
    use BuildsEmployeeMessagingFixtures;
    use RefreshDatabase;

    public function test_logistics_messages_mark_and_clear_the_thread(): void
    {
        Queue::fake();
        $member = $this->messagingMember();
        $responder = $this->logisticsResponder();
        $ledger = app(MemberReplyLedger::class);

        $first = $this->memberSends($member);
        $threadId = $this->threadIdOf($first);
        $this->assertTrue($ledger->isAwaiting(ReplySubject::LOGISTICS_THREAD, $threadId));

        $this->logisticsReplies($responder, $threadId);
        $this->assertFalse($ledger->isAwaiting(ReplySubject::LOGISTICS_THREAD, $threadId));

        $this->memberReplies($member, $threadId);
        $state = SubmissionReplyState::query()->ofType(ReplySubject::LOGISTICS_THREAD)->where('subject_id', $threadId)->sole();
        $this->assertTrue($state->awaiting_staff_reply);
        $this->assertSame($member->id, $state->last_member_reply_user_id);
        $this->assertNotNull($state->last_staff_reply_at);
        $this->assertSame([$threadId], $ledger->awaiting()->pluck('subject_id')->all());
    }

    public function test_hub_support_member_reply_awaits_staff_until_a_public_response(): void
    {
        Queue::fake();
        $member = User::factory()->create(['account_status' => AccountStatus::Active]);
        $ticket = HubSupportTicket::factory()->for($member, 'reporter')->create(['status' => HubSupportTicketStatus::WaitingForReporter]);
        $manager = User::factory()->create(['account_status' => AccountStatus::Active]);
        foreach (['admin.access', 'admin.support.view', 'admin.support.manage'] as $permission) {
            $manager->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $workflow = app(HubSupportTicketWorkflowService::class);
        $ledger = app(MemberReplyLedger::class);

        $workflow->reply($ticket, $member, 'It happens on the PDF upload step.');
        $this->assertTrue($ledger->isAwaiting(ReplySubject::HUB_SUPPORT_TICKET, $ticket->id));

        $workflow->transition($ticket->fresh(), $manager, HubSupportTicketStatus::Acknowledged, ['internal_note' => 'Reproduced locally.']);
        $this->assertTrue($ledger->isAwaiting(ReplySubject::HUB_SUPPORT_TICKET, $ticket->id));

        $workflow->transition($ticket->fresh(), $manager, HubSupportTicketStatus::InProgress, ['public_response' => 'Thanks, we found the cause.']);
        $this->assertFalse($ledger->isAwaiting(ReplySubject::HUB_SUPPORT_TICKET, $ticket->id));
    }
}
