<?php

declare(strict_types=1);

namespace Tests\Feature\EmployeeMessaging;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Docs section 4: read markers are message ids that only move forward, only on open. */
final class EmployeeLogisticsReadMarkersTest extends TestCase
{
    use BuildsEmployeeMessagingFixtures;
    use RefreshDatabase;

    // ------------------------------------------------------------ Logistics unread

    public function test_a_new_member_message_is_unread_for_logistics(): void
    {
        $viewer = $this->logisticsViewer();
        $message = $this->memberSends($this->messagingMember());

        $this->assertSame(1, $this->service()->logisticsUnreadCount($viewer));
        $this->assertTrue($this->storedThread($message)->isUnreadForLogistics());
        $this->assertSame([$message->thread_id], $this->service()->logisticsThreads($viewer, 'unread')['threads']->pluck('id')->all());
    }

    public function test_listing_previewing_and_counting_never_move_the_marker(): void
    {
        $viewer = $this->logisticsResponder();
        $message = $this->memberSends($this->messagingMember());
        $member = $this->service()->logisticsThread($viewer, $message->thread_id);

        $this->service()->logisticsThreads($viewer);
        $this->service()->logisticsThreads($viewer, 'unread', 'boots');
        $this->service()->logisticsThread($viewer, $message->thread_id);
        $this->service()->messagesFor($member);
        $this->service()->logisticsUnreadCount($viewer);

        $thread = $this->storedThread($message);
        $this->assertNull($thread->logistics_read_message_id);
        $this->assertNull($thread->logistics_read_by_user_id);
        $this->assertNull($thread->logistics_read_at);
        $this->assertSame(1, $this->service()->logisticsUnreadCount($viewer));
    }

    public function test_opening_a_thread_marks_it_read_up_to_the_newest_message_and_records_who(): void
    {
        $viewer = $this->logisticsResponder();
        $message = $this->memberSends($this->messagingMember());

        $this->service()->markReadByLogistics($viewer, $message->thread_id, $message->id);

        $thread = $this->storedThread($message);
        $this->assertSame($message->id, $thread->logistics_read_message_id);
        $this->assertSame($viewer->id, $thread->logistics_read_by_user_id);
        $this->assertNotNull($thread->logistics_read_at);
        $this->assertFalse($thread->isUnreadForLogistics());
        $this->assertSame(0, $this->service()->logisticsUnreadCount($viewer));
        $this->assertSame([], $this->service()->logisticsThreads($viewer, 'unread')['threads']->all());
    }

    public function test_the_read_marker_is_shared_by_the_whole_logistics_team(): void
    {
        $first = $this->logisticsResponder();
        $second = $this->logisticsViewer();
        $message = $this->memberSends($this->messagingMember());

        $this->service()->markReadByLogistics($first, $message->thread_id, $message->id);

        $this->assertSame(0, $this->service()->logisticsUnreadCount($second));
    }

    public function test_a_marker_never_moves_backwards(): void
    {
        $member = $this->messagingMember();
        $viewer = $this->logisticsResponder();
        $m1 = $this->memberSends($member, 'one');
        $m2 = $this->memberReplies($member, $m1, 'two');
        $m3 = $this->memberReplies($member, $m1, 'three');

        $this->service()->markReadByLogistics($viewer, $m1->thread_id, $m3->id);
        $this->service()->markReadByLogistics($viewer, $m1->thread_id, $m2->id);
        $this->service()->markReadByLogistics($viewer, $m1->thread_id, $m1->id);

        $this->assertSame($m3->id, $this->storedThread($m1)->logistics_read_message_id);
        $this->assertSame(0, $this->service()->logisticsUnreadCount($viewer));
    }

    public function test_a_stale_older_mark_does_not_rewrite_who_read_it(): void
    {
        $member = $this->messagingMember();
        $a = $this->logisticsResponder();
        $b = $this->logisticsViewer();
        $m1 = $this->memberSends($member, 'one');
        $m2 = $this->memberReplies($member, $m1, 'two');

        $this->service()->markReadByLogistics($a, $m1->thread_id, $m2->id);
        $readAt = $this->storedThread($m1)->logistics_read_at;
        $this->service()->markReadByLogistics($b, $m1->thread_id, $m1->id);

        $thread = $this->storedThread($m1);
        $this->assertSame($a->id, $thread->logistics_read_by_user_id);
        $this->assertEquals($readAt, $thread->logistics_read_at);
    }

    public function test_two_admins_marking_in_any_order_end_at_the_highest_id(): void
    {
        $member = $this->messagingMember();
        $a = $this->logisticsResponder();
        $b = $this->logisticsViewer();
        $m1 = $this->memberSends($member, 'one');
        $m2 = $this->memberReplies($member, $m1, 'two');
        $m3 = $this->memberReplies($member, $m1, 'three');

        $this->service()->markReadByLogistics($a, $m1->thread_id, $m1->id);
        $this->service()->markReadByLogistics($b, $m1->thread_id, $m3->id);
        $this->service()->markReadByLogistics($a, $m1->thread_id, $m2->id);

        $thread = $this->storedThread($m1);
        $this->assertSame($m3->id, $thread->logistics_read_message_id);
        $this->assertSame($b->id, $thread->logistics_read_by_user_id, 'The admin who advanced the marker furthest is recorded.');
    }

    public function test_seen_up_to_beyond_the_newest_message_is_clamped(): void
    {
        $viewer = $this->logisticsResponder();
        $message = $this->memberSends($this->messagingMember());

        $this->service()->markReadByLogistics($viewer, $message->thread_id, 9_999_999);

        $this->assertSame($message->id, $this->storedThread($message)->logistics_read_message_id);
    }

    public function test_the_clamp_cannot_be_used_to_swallow_a_message_that_has_not_arrived(): void
    {
        $member = $this->messagingMember();
        $viewer = $this->logisticsResponder();
        $m1 = $this->memberSends($member, 'one');
        $this->service()->markReadByLogistics($viewer, $m1->thread_id, 9_999_999);

        $m2 = $this->memberReplies($member, $m1, 'two');

        $this->assertTrue($this->storedThread($m1)->isUnreadForLogistics());
        $this->assertSame(1, $this->service()->logisticsUnreadCount($viewer));
        $this->assertGreaterThan($this->storedThread($m1)->logistics_read_message_id, $m2->id);
    }

    public function test_zero_and_negative_seen_ids_change_nothing(): void
    {
        $viewer = $this->logisticsResponder();
        $message = $this->memberSends($this->messagingMember());

        $this->service()->markReadByLogistics($viewer, $message->thread_id, 0);
        $this->service()->markReadByLogistics($viewer, $message->thread_id, -5);

        $thread = $this->storedThread($message);
        $this->assertNull($thread->logistics_read_message_id);
        $this->assertNull($thread->logistics_read_by_user_id);
        $this->assertTrue($thread->isUnreadForLogistics());
    }

    public function test_a_member_message_arriving_after_the_read_makes_the_thread_unread_again(): void
    {
        $member = $this->messagingMember();
        $viewer = $this->logisticsResponder();
        $m1 = $this->memberSends($member, 'one');
        $this->service()->markReadByLogistics($viewer, $m1->thread_id, $m1->id);
        $this->assertSame(0, $this->service()->logisticsUnreadCount($viewer));

        $m2 = $this->memberReplies($member, $m1, 'two');

        $this->assertSame(1, $this->service()->logisticsUnreadCount($viewer));
        $this->assertTrue($this->storedThread($m1)->isUnreadForLogistics());
        $this->assertSame([$m1->thread_id], $this->service()->logisticsThreads($viewer, 'unread')['threads']->pluck('id')->all());

        $this->service()->markReadByLogistics($viewer, $m1->thread_id, $m2->id);
        $this->assertSame(0, $this->service()->logisticsUnreadCount($viewer));
    }

    public function test_reading_a_stale_message_leaves_a_later_one_unread(): void
    {
        $member = $this->messagingMember();
        $viewer = $this->logisticsResponder();
        $m1 = $this->memberSends($member, 'one');
        $this->memberReplies($member, $m1, 'two');

        $this->service()->markReadByLogistics($viewer, $m1->thread_id, $m1->id);

        $this->assertSame(1, $this->service()->logisticsUnreadCount($viewer), 'The page only showed the first message.');
    }

    public function test_a_logistics_reply_does_not_count_as_unread_for_logistics(): void
    {
        $member = $this->messagingMember();
        $viewer = $this->logisticsResponder();
        $m1 = $this->memberSends($member);
        $this->service()->markReadByLogistics($viewer, $m1->thread_id, $m1->id);

        $this->logisticsReplies($viewer, $m1);

        $this->assertSame(0, $this->service()->logisticsUnreadCount($viewer));
        $this->assertFalse($this->storedThread($m1)->isUnreadForLogistics());
    }

    public function test_unread_for_logistics_counts_threads_not_messages(): void
    {
        $viewer = $this->logisticsResponder();
        $member = $this->messagingMember();
        $a = $this->memberSends($member, 'a1');
        $this->memberReplies($member, $a, 'a2');
        $this->memberReplies($member, $a, 'a3');
        $this->memberSends($this->messagingMember('Other Member'), 'b1');

        $this->assertSame(2, $this->service()->logisticsUnreadCount($viewer));
    }

    public function test_a_view_only_administrator_may_mark_a_thread_read_because_viewing_is_reading(): void
    {
        $viewer = $this->logisticsViewer();
        $message = $this->memberSends($this->messagingMember());

        $this->service()->markReadByLogistics($viewer, $message->thread_id, $message->id);

        $this->assertSame($viewer->id, $this->storedThread($message)->logistics_read_by_user_id);
        $this->assertSame(0, $this->service()->logisticsUnreadCount($viewer));
    }

    public function test_marking_a_missing_thread_read_is_not_found(): void
    {
        $viewer = $this->logisticsResponder();

        $this->assertFailsWith(ModelNotFoundException::class, fn () => $this->service()->markReadByLogistics($viewer, 31337, 1));
    }

    public function test_an_unauthorized_actor_cannot_move_the_logistics_marker(): void
    {
        $member = $this->messagingMember();
        $message = $this->memberSends($member);

        foreach ([$member, $this->unrelatedAdmin()] as $actor) {
            $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->markReadByLogistics($actor, $message->thread_id, $message->id));
        }

        $this->assertNull($this->storedThread($message)->logistics_read_message_id);
    }

    public function test_a_members_own_messages_never_count_as_unread_for_them(): void
    {
        $member = $this->messagingMember();
        $m1 = $this->memberSends($member);
        $this->memberReplies($member, $m1, 'again');

        $this->assertSame(0, $this->service()->memberUnreadCount($member));
        $this->assertFalse($this->storedThread($m1)->isUnreadForMember());
        $this->assertSame([], $this->unreadForMemberIds($member));
    }

    // ------------------------------------------------------------ member unread

    public function test_a_logistics_reply_is_unread_for_the_member_until_they_open_it(): void
    {
        $member = $this->messagingMember();
        $m1 = $this->memberSends($member);
        $reply = $this->logisticsReplies($this->logisticsResponder(), $m1);

        $this->assertSame(1, $this->service()->memberUnreadCount($member));
        $this->assertTrue($this->storedThread($m1)->isUnreadForMember());

        $this->service()->memberThreads($member);
        $this->service()->memberThread($member, $m1->thread_id);
        $this->service()->messagesFor($this->service()->memberThread($member, $m1->thread_id));
        $this->assertSame(1, $this->service()->memberUnreadCount($member), 'Loading lists and history never clears the badge.');
        $this->assertNull($this->storedThread($m1)->member_read_message_id);

        $this->service()->markReadByMember($member, $m1->thread_id, $reply->id);

        $this->assertSame(0, $this->service()->memberUnreadCount($member));
        $this->assertSame($reply->id, $this->storedThread($m1)->member_read_message_id);
    }

    public function test_the_member_marker_only_moves_forward_and_is_clamped(): void
    {
        $member = $this->messagingMember();
        $responder = $this->logisticsResponder();
        $m1 = $this->memberSends($member);
        $l1 = $this->logisticsReplies($responder, $m1, 'first reply');
        $l2 = $this->logisticsReplies($responder, $m1, 'second reply');

        $this->service()->markReadByMember($member, $m1->thread_id, $l1->id);
        $this->assertSame(1, $this->service()->memberUnreadCount($member), 'The second reply is still unseen.');

        $this->service()->markReadByMember($member, $m1->thread_id, 9_999_999);
        $this->assertSame($l2->id, $this->storedThread($m1)->member_read_message_id);
        $this->assertSame(0, $this->service()->memberUnreadCount($member));

        $this->service()->markReadByMember($member, $m1->thread_id, $l1->id);
        $this->service()->markReadByMember($member, $m1->thread_id, 0);
        $this->assertSame($l2->id, $this->storedThread($m1)->member_read_message_id);
    }

    public function test_a_new_logistics_reply_after_the_member_read_is_unread_again(): void
    {
        $member = $this->messagingMember();
        $responder = $this->logisticsResponder();
        $m1 = $this->memberSends($member);
        $l1 = $this->logisticsReplies($responder, $m1, 'first reply');
        $this->service()->markReadByMember($member, $m1->thread_id, $l1->id);

        $this->logisticsReplies($responder, $m1, 'second reply');

        $this->assertSame(1, $this->service()->memberUnreadCount($member));
    }

    public function test_the_members_marker_does_not_touch_the_logistics_marker_and_vice_versa(): void
    {
        $member = $this->messagingMember();
        $responder = $this->logisticsResponder();
        $m1 = $this->memberSends($member);
        $l1 = $this->logisticsReplies($responder, $m1);

        $this->service()->markReadByMember($member, $m1->thread_id, $l1->id);
        $thread = $this->storedThread($m1);
        $this->assertNull($thread->logistics_read_message_id);
        $this->assertSame(1, $this->service()->logisticsUnreadCount($responder));

        $this->service()->markReadByLogistics($responder, $m1->thread_id, $m1->id);
        $this->assertSame($l1->id, $this->storedThread($m1)->member_read_message_id);
    }

    public function test_the_member_unread_count_is_per_member(): void
    {
        $responder = $this->logisticsResponder();
        $a = $this->messagingMember('A Member');
        $b = $this->messagingMember('B Member');
        $threadA = $this->memberSends($a);
        $this->memberSends($b);

        $this->logisticsReplies($responder, $threadA);

        $this->assertSame(1, $this->service()->memberUnreadCount($a));
        $this->assertSame(0, $this->service()->memberUnreadCount($b));
    }

    public function test_a_member_cannot_move_another_members_marker(): void
    {
        $owner = $this->messagingMember('Owner Member');
        $other = $this->messagingMember('Other Member');
        $m1 = $this->memberSends($owner);
        $l1 = $this->logisticsReplies($this->logisticsResponder(), $m1);

        $this->assertFailsWith(ModelNotFoundException::class, fn () => $this->service()->markReadByMember($other, $m1->thread_id, $l1->id));

        $this->assertNull($this->storedThread($m1)->member_read_message_id);
        $this->assertSame(1, $this->service()->memberUnreadCount($owner));
    }

    /** @return list<int> */
    private function unreadForMemberIds(\App\Models\User $member): array
    {
        return $this->service()->memberThreads($member)['threads']
            ->filter(fn ($thread): bool => $thread->isUnreadForMember())->pluck('id')->all();
    }
}
