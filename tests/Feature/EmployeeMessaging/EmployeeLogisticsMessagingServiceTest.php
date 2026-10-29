<?php

declare(strict_types=1);

namespace Tests\Feature\EmployeeMessaging;

use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Models\EmployeeMessaging\EmployeeLogisticsThread;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Threads, scoping, replies and idempotency of EmployeeLogisticsMessagingService. */
final class EmployeeLogisticsMessagingServiceTest extends TestCase
{
    use BuildsEmployeeMessagingFixtures;
    use RefreshDatabase;

    // ------------------------------------------------------------ starting a thread

    public function test_starting_a_thread_creates_exactly_one_thread_and_one_member_message(): void
    {
        $member = $this->messagingMember();

        $message = $this->service()->startThread($member, 'My helmet strap broke.', 'Helmet strap', $this->rid());

        $this->assertSame(1, EmployeeLogisticsThread::query()->count());
        $this->assertSame(1, EmployeeLogisticsMessage::query()->count());
        $this->assertSame(EmployeeLogisticsMessage::SIDE_MEMBER, $message->author_side);
        $this->assertSame($member->id, $message->author_user_id);
        $this->assertSame('My helmet strap broke.', $message->body);
        $this->assertSame('Pat Member', $message->author_name);

        $thread = $this->storedThread($message);
        $this->assertSame($member->id, $thread->member_user_id);
        $this->assertSame('Helmet strap', $thread->subject);
        $this->assertSame(1, $thread->message_count);
        $this->assertSame($message->id, $thread->last_member_message_id);
        $this->assertNull($thread->last_logistics_message_id);
        $this->assertSame(EmployeeLogisticsMessage::SIDE_MEMBER, $thread->last_message_side);
        $this->assertSame('My helmet strap broke.', $thread->last_message_preview);
        $this->assertNotNull($thread->last_message_at);
        $this->assertNull($thread->member_read_message_id);
        $this->assertNull($thread->logistics_read_message_id);
    }

    public function test_the_returned_message_carries_its_thread(): void
    {
        $message = $this->memberSends($this->messagingMember());

        $this->assertTrue($message->relationLoaded('thread'));
        $this->assertSame($message->thread_id, $message->thread->getKey());
    }

    public function test_the_thread_snapshots_the_member_at_the_time_of_sending(): void
    {
        $member = $this->messagingMember('Pat Member', [
            'rank' => 'Lieutenant', 'station' => 'Station 3', 'employee_id' => 'FF-0042',
        ]);

        $thread = $this->storedThread($this->memberSends($member));

        $this->assertSame('Pat Member', $thread->member_name);
        $this->assertSame('FF-0042', $thread->member_employee_number);
        $this->assertSame('Lieutenant', $thread->member_rank);
        $this->assertSame('Station 3', $thread->member_station);
        $this->assertSame($member->employee_profile_id, $thread->employee_profile_id);
    }

    public function test_the_snapshot_prefers_the_profile_display_name(): void
    {
        $member = $this->messagingMember('Patricia Member', ['display_name' => 'Pat M.']);

        $message = $this->memberSends($member);

        $this->assertSame('Pat M.', $this->storedThread($message)->member_name);
        $this->assertSame('Pat M.', $message->author_name);
    }

    public function test_the_snapshot_keeps_blank_optional_fields_null(): void
    {
        $member = $this->messagingMember('Bare Member', ['rank' => '', 'station' => '']);

        $thread = $this->storedThread($this->memberSends($member));

        $this->assertNull($thread->member_rank);
        $this->assertNull($thread->member_station);
    }

    public function test_history_keeps_the_old_snapshot_after_a_promotion_until_the_member_writes_again(): void
    {
        $member = $this->messagingMember('Pat Member', ['rank' => 'Firefighter', 'station' => 'Station 1']);
        $message = $this->memberSends($member);
        $responder = $this->logisticsResponder();

        $member->employeeProfile->forceFill(['rank' => 'Captain', 'station' => 'Station 4'])->save();

        $this->logisticsReplies($responder, $message);
        $this->assertSame('Firefighter', $this->storedThread($message)->member_rank, 'A Logistics reply never rewrites the member snapshot.');

        $this->memberReplies($member->fresh(), $message);
        $thread = $this->storedThread($message);
        $this->assertSame('Captain', $thread->member_rank);
        $this->assertSame('Station 4', $thread->member_station);
    }

    public function test_a_new_thread_is_visible_to_its_owner_and_to_an_authorized_viewer(): void
    {
        $member = $this->messagingMember();
        $message = $this->memberSends($member);

        $owner = $this->service()->memberThreads($member);
        $this->assertSame([$message->thread_id], $owner['threads']->pluck('id')->all());
        $this->assertFalse($owner['hasMore']);

        foreach ([$this->logisticsViewer(), $this->logisticsResponder(), $this->messagingAdmin(['super_admin'])] as $viewer) {
            $this->assertSame([$message->thread_id], $this->service()->logisticsThreads($viewer)['threads']->pluck('id')->all());
            $this->assertSame($message->thread_id, $this->service()->logisticsThread($viewer, $message->thread_id)->getKey());
        }
        $this->assertSame($message->thread_id, $this->service()->memberThread($member, $message->thread_id)->getKey());
    }

    public function test_two_threads_from_one_member_stay_separate(): void
    {
        $member = $this->messagingMember();

        $a = $this->memberSends($member, 'First topic', 'A');
        $b = $this->memberSends($member, 'Second topic', 'B');

        $this->assertNotSame($a->thread_id, $b->thread_id);
        $this->assertSame(2, EmployeeLogisticsThread::query()->count());
        $this->assertEqualsCanonicalizing([$a->thread_id, $b->thread_id], $this->service()->memberThreads($member)['threads']->pluck('id')->all());
    }

    // ------------------------------------------------------------ member scoping

    public function test_another_member_cannot_see_read_reply_to_or_mark_a_thread_and_nothing_leaks(): void
    {
        $owner = $this->messagingMember('Owner Member');
        $other = $this->messagingMember('Other Member');
        $message = $this->memberSends($owner, 'Private matter about my uniform size.');
        $this->logisticsReplies($this->logisticsResponder(), $message, 'Reply meant for the owner only.');
        $before = $this->storedThread($message)->getAttributes();

        $this->assertSame([], $this->service()->memberThreads($other)['threads']->all());
        $this->assertSame(0, $this->service()->memberUnreadCount($other));
        $this->assertFailsWith(ModelNotFoundException::class, fn () => $this->service()->memberThread($other, $message->thread_id));
        $this->assertFailsWith(ModelNotFoundException::class, fn () => $this->service()->replyAsMember($other, $message->thread_id, 'Hijack', $this->rid()));
        $this->assertFailsWith(ModelNotFoundException::class, fn () => $this->service()->markReadByMember($other, $message->thread_id, 999));
        $this->assertFailsWith(ModelNotFoundException::class, fn () => $this->service()->memberThread($other, 987654));

        $this->assertSame($before, $this->storedThread($message)->getAttributes(), 'The thread is untouched.');
        $this->assertSame(2, EmployeeLogisticsMessage::query()->count());
    }

    public function test_a_not_found_thread_and_a_forbidden_thread_are_indistinguishable_to_a_member(): void
    {
        $owner = $this->messagingMember('Owner Member');
        $other = $this->messagingMember('Other Member');
        $thread = $this->storedThread($this->memberSends($owner));

        $forbidden = $this->assertFailsWith(ModelNotFoundException::class, fn () => $this->service()->memberThread($other, $thread->id));
        $missing = $this->assertFailsWith(ModelNotFoundException::class, fn () => $this->service()->memberThread($other, $thread->id + 1000));

        $this->assertSame(
            preg_replace('/\d+/', '', $missing->getMessage()),
            preg_replace('/\d+/', '', $forbidden->getMessage()),
        );
    }

    public function test_a_member_cannot_write_into_a_thread_by_reusing_the_owners_request_id_either(): void
    {
        $owner = $this->messagingMember('Owner Member');
        $other = $this->messagingMember('Other Member');
        $requestId = $this->rid();
        $message = $this->service()->startThread($owner, 'Owner message', null, $requestId);

        $this->assertFailsWith(ModelNotFoundException::class, fn () => $this->service()->replyAsMember($other, $message->thread_id, 'Owner message', $requestId));
        $this->assertSame(1, EmployeeLogisticsMessage::query()->count());
    }

    // ------------------------------------------------------------ replies

    public function test_a_logistics_reply_is_stored_on_the_logistics_side_and_the_member_sees_it(): void
    {
        $member = $this->messagingMember();
        $responder = $this->logisticsResponder(['name' => 'Lou Logistics']);
        $first = $this->memberSends($member);

        $reply = $this->service()->replyAsLogistics($responder, $first->thread_id, 'Replacement strap ordered.', $this->rid());

        $this->assertSame(EmployeeLogisticsMessage::SIDE_LOGISTICS, $reply->author_side);
        $this->assertSame($responder->id, $reply->author_user_id);
        $this->assertSame('Lou Logistics', $reply->author_name);
        $this->assertTrue($reply->isFromLogistics());
        $this->assertSame($first->thread_id, $reply->thread_id);

        $thread = $this->storedThread($first);
        $this->assertSame(2, $thread->message_count);
        $this->assertSame($reply->id, $thread->last_logistics_message_id);
        $this->assertSame($first->id, $thread->last_member_message_id, 'A Logistics reply does not move the member marker.');
        $this->assertSame(EmployeeLogisticsMessage::SIDE_LOGISTICS, $thread->last_message_side);
        $this->assertSame('Replacement strap ordered.', $thread->last_message_preview);

        $seen = $this->service()->messagesFor($this->service()->memberThread($member, $first->thread_id))['messages'];
        $this->assertSame(['Hello Logistics, my boots arrived damaged.', 'Replacement strap ordered.'], $seen->pluck('body')->all());
        $this->assertSame(['member', 'logistics'], $seen->pluck('author_side')->all());
    }

    public function test_the_logistics_author_name_prefers_the_display_name(): void
    {
        $responder = $this->logisticsResponder(['name' => 'louis.l', 'display_name' => 'Lou from Logistics']);
        $first = $this->memberSends($this->messagingMember());

        $this->assertSame('Lou from Logistics', $this->logisticsReplies($responder, $first)->author_name);
    }

    public function test_a_super_administrator_can_reply_as_logistics(): void
    {
        $first = $this->memberSends($this->messagingMember());

        $reply = $this->logisticsReplies($this->messagingAdmin(['super_admin']), $first);

        $this->assertSame('logistics', $reply->author_side);
    }

    public function test_the_member_can_reply_again_in_the_same_thread_and_history_reads_oldest_first(): void
    {
        $member = $this->messagingMember();
        $responder = $this->logisticsResponder();
        $first = $this->memberSends($member, 'one');
        $this->logisticsReplies($responder, $first, 'two');
        $this->memberReplies($member, $first, 'three');
        $this->logisticsReplies($responder, $first, 'four');

        $thread = $this->storedThread($first);
        $history = $this->service()->messagesFor($thread);

        $this->assertSame(['one', 'two', 'three', 'four'], $history['messages']->pluck('body')->all());
        $this->assertSame($history['messages']->pluck('id')->sort()->values()->all(), $history['messages']->pluck('id')->all());
        $this->assertFalse($history['hasEarlier']);
        $this->assertSame(4, $thread->message_count);
        $this->assertSame(1, EmployeeLogisticsThread::query()->count());
    }

    public function test_messages_for_returns_the_newest_window_oldest_first_and_flags_earlier_ones(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member, 'm1');
        foreach (['m2', 'm3', 'm4', 'm5'] as $body) {
            $this->memberReplies($member, $first, $body);
        }

        $window = $this->service()->messagesFor($this->storedThread($first), 3);

        $this->assertSame(['m3', 'm4', 'm5'], $window['messages']->pluck('body')->all());
        $this->assertTrue($window['hasEarlier']);

        $all = $this->service()->messagesFor($this->storedThread($first), 5);
        $this->assertSame(['m1', 'm2', 'm3', 'm4', 'm5'], $all['messages']->pluck('body')->all());
        $this->assertFalse($all['hasEarlier']);

        $this->assertCount(1, $this->service()->messagesFor($this->storedThread($first), 0)['messages'], 'The window never drops below one message.');
    }

    public function test_messages_for_only_returns_that_threads_messages(): void
    {
        $a = $this->memberSends($this->messagingMember('A Member'), 'in thread a');
        $this->memberSends($this->messagingMember('B Member'), 'in thread b');

        $this->assertSame(['in thread a'], $this->service()->messagesFor($this->storedThread($a))['messages']->pluck('body')->all());
    }

    public function test_replying_to_a_missing_thread_is_not_found(): void
    {
        $responder = $this->logisticsResponder();

        $this->assertFailsWith(ModelNotFoundException::class, fn () => $this->service()->replyAsLogistics($responder, 424242, 'Hello?', $this->rid()));
        $this->assertSame(0, EmployeeLogisticsMessage::query()->count());
    }

    public function test_per_thread_message_ids_follow_the_order_messages_were_written(): void
    {
        $member = $this->messagingMember();
        $responder = $this->logisticsResponder();
        $first = $this->memberSends($member, 'a');
        $ids = [$first->id];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = ($i % 2 === 0 ? $this->logisticsReplies($responder, $first, 'r'.$i) : $this->memberReplies($member, $first, 'r'.$i))->id;
        }

        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
    }

    // ------------------------------------------------------------ idempotency

    public function test_the_same_request_id_and_text_returns_the_original_message_without_a_duplicate(): void
    {
        $member = $this->messagingMember();
        $requestId = $this->rid();

        $first = $this->service()->startThread($member, 'Retry me', 'Subj', $requestId);
        $again = $this->service()->startThread($member, 'Retry me', 'Subj', $requestId);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, EmployeeLogisticsMessage::query()->count());
        $this->assertSame(1, EmployeeLogisticsThread::query()->count());
        $this->assertSame(1, $this->storedThread($first)->message_count);
    }

    public function test_a_replay_is_matched_after_the_same_normalization_as_the_first_send(): void
    {
        $member = $this->messagingMember();
        $requestId = $this->rid();

        $first = $this->service()->startThread($member, "Line one\r\nLine two  ", null, $requestId);
        $again = $this->service()->startThread($member, "  Line one\nLine two", null, $requestId);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, EmployeeLogisticsMessage::query()->count());
    }

    public function test_a_member_reply_retry_returns_the_original_message(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        $requestId = $this->rid();

        $one = $this->service()->replyAsMember($member, $first->thread_id, 'Second', $requestId);
        $two = $this->service()->replyAsMember($member, $first->thread_id, 'Second', $requestId);

        $this->assertSame($one->id, $two->id);
        $this->assertSame(2, EmployeeLogisticsMessage::query()->count());
        $this->assertSame(2, $this->storedThread($first)->message_count);
    }

    public function test_a_logistics_reply_retry_returns_the_original_message(): void
    {
        $responder = $this->logisticsResponder();
        $first = $this->memberSends($this->messagingMember());
        $requestId = $this->rid();

        $one = $this->service()->replyAsLogistics($responder, $first->thread_id, 'On it', $requestId);
        $two = $this->service()->replyAsLogistics($responder, $first->thread_id, 'On it', $requestId);

        $this->assertSame($one->id, $two->id);
        $this->assertSame(2, EmployeeLogisticsMessage::query()->count());
        $this->assertSame(2, $this->storedThread($first)->message_count);
    }

    public function test_the_same_request_id_with_different_text_is_refused_and_nothing_is_written(): void
    {
        $member = $this->messagingMember();
        $requestId = $this->rid();
        $this->service()->startThread($member, 'Original text', null, $requestId);

        $this->assertInvalid(fn () => $this->service()->startThread($member, 'Different text', null, $requestId));

        $this->assertSame(1, EmployeeLogisticsMessage::query()->count());
        $this->assertSame('Original text', EmployeeLogisticsMessage::query()->sole()->body);
    }

    public function test_the_same_request_id_with_different_text_on_a_reply_is_refused(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        $requestId = $this->rid();
        $this->service()->replyAsMember($member, $first->thread_id, 'Follow up', $requestId);

        $this->assertInvalid(fn () => $this->service()->replyAsMember($member, $first->thread_id, 'Something else', $requestId));
        $this->assertSame(2, EmployeeLogisticsMessage::query()->count());
    }

    public function test_the_same_request_id_reused_on_a_different_thread_is_refused(): void
    {
        $member = $this->messagingMember();
        $a = $this->memberSends($member, 'Topic A');
        $b = $this->memberSends($member, 'Topic B');
        $requestId = $this->rid();
        $this->service()->replyAsMember($member, $a->thread_id, 'Same words', $requestId);

        $this->assertInvalid(fn () => $this->service()->replyAsMember($member, $b->thread_id, 'Same words', $requestId));

        $this->assertSame(1, $this->storedThread($b)->message_count);
        $this->assertSame(3, EmployeeLogisticsMessage::query()->count());
    }

    public function test_a_logistics_request_id_reused_on_a_different_thread_is_refused(): void
    {
        $responder = $this->logisticsResponder();
        $a = $this->memberSends($this->messagingMember('A Member'));
        $b = $this->memberSends($this->messagingMember('B Member'));
        $requestId = $this->rid();
        $this->service()->replyAsLogistics($responder, $a->thread_id, 'Same words', $requestId);

        $this->assertInvalid(fn () => $this->service()->replyAsLogistics($responder, $b->thread_id, 'Same words', $requestId));
        $this->assertSame(1, $this->storedThread($b)->message_count);
    }

    public function test_a_new_request_id_creates_a_new_message_even_with_identical_text(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member, 'Same words');

        $second = $this->memberReplies($member, $first, 'Same words');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $this->storedThread($first)->message_count);
    }

    public function test_request_ids_are_scoped_to_the_author(): void
    {
        $member = $this->messagingMember();
        $responder = $this->logisticsResponder();
        $requestId = $this->rid();

        $first = $this->service()->startThread($member, 'Member words', null, $requestId);
        $reply = $this->service()->replyAsLogistics($responder, $first->thread_id, 'Logistics words', $requestId);
        $other = $this->service()->startThread($this->messagingMember('Other Member'), 'Other words', null, $requestId);

        $this->assertCount(3, array_unique([$first->id, $reply->id, $other->id]));
    }

    public function test_the_database_enforces_one_message_per_author_and_request_id(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        $existing = EmployeeLogisticsMessage::query()->findOrFail($first->id);
        $row = [
            'thread_id' => $first->thread_id, 'author_user_id' => $member->id, 'author_side' => 'member',
            'author_name' => 'x', 'body' => 'dup', 'client_request_id' => $existing->client_request_id,
            'created_at' => now(), 'updated_at' => now(),
        ];

        $this->assertFailsWith(\Illuminate\Database\UniqueConstraintViolationException::class, fn () => DB::table('employee_logistics_messages')->insert($row));
    }

    // ------------------------------------------------------------ concurrent duplicates

    public function test_a_concurrent_duplicate_start_is_resolved_by_the_unique_index_to_the_winning_message(): void
    {
        $member = $this->messagingMember();
        $requestId = $this->rid();
        $winner = $this->injectOnceAfterFirstMessageLookup(function () use ($member, $requestId): int {
            return $this->insertCompetingMessage($member, null, 'Race body', $requestId);
        });

        $result = $this->service()->startThread($member, 'Race body', null, $requestId);

        $this->assertSame($winner(), $result->id, 'The loser gets the winner\'s message back.');
        $this->assertSame(1, EmployeeLogisticsMessage::query()->count());
        $this->assertSame(1, EmployeeLogisticsThread::query()->count(), 'The loser\'s thread is rolled back, no orphan remains.');
    }

    public function test_a_concurrent_duplicate_member_reply_is_resolved_to_the_winning_message(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        $requestId = $this->rid();
        $winner = $this->injectOnceAfterFirstMessageLookup(function () use ($member, $first, $requestId): int {
            return $this->insertCompetingMessage($member, $first->thread_id, 'Race reply', $requestId);
        });

        $result = $this->service()->replyAsMember($member, $first->thread_id, 'Race reply', $requestId);

        $this->assertSame($winner(), $result->id);
        $this->assertSame(2, EmployeeLogisticsMessage::query()->count());
    }

    public function test_a_concurrent_duplicate_logistics_reply_is_resolved_to_the_winning_message(): void
    {
        $responder = $this->logisticsResponder();
        $first = $this->memberSends($this->messagingMember());
        $requestId = $this->rid();
        $winner = $this->injectOnceAfterFirstMessageLookup(function () use ($responder, $first, $requestId): int {
            return $this->insertCompetingMessage($responder, $first->thread_id, 'Race answer', $requestId, 'logistics');
        });

        $result = $this->service()->replyAsLogistics($responder, $first->thread_id, 'Race answer', $requestId);

        $this->assertSame($winner(), $result->id);
        $this->assertSame(2, EmployeeLogisticsMessage::query()->count());
    }

    public function test_a_concurrent_duplicate_with_different_text_is_refused(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        $requestId = $this->rid();
        $this->injectOnceAfterFirstMessageLookup(function () use ($member, $first, $requestId): int {
            return $this->insertCompetingMessage($member, $first->thread_id, 'Winner text', $requestId);
        });

        $this->assertInvalid(fn () => $this->service()->replyAsMember($member, $first->thread_id, 'Loser text', $requestId));
        $this->assertSame(2, EmployeeLogisticsMessage::query()->count());
    }

    /**
     * Run $competitor right after the service's first look-up of an existing message (which finds
     * nothing), so the row appears between that check and the service's own insert: the lost-race path.
     *
     * @param  callable(): int  $competitor
     * @return callable(): int the id the competitor inserted
     */
    private function injectOnceAfterFirstMessageLookup(callable $competitor): callable
    {
        $fired = false;
        $winnerId = 0;
        DB::listen(function (QueryExecuted $query) use (&$fired, &$winnerId, $competitor): void {
            if ($fired || ! str_starts_with(strtolower(ltrim($query->sql)), 'select') || ! str_contains($query->sql, 'employee_logistics_messages')) {
                return;
            }
            $fired = true;
            $winnerId = $competitor();
        });

        return static function () use (&$winnerId): int {
            return $winnerId;
        };
    }

    private function insertCompetingMessage(\App\Models\User $author, ?int $threadId, string $body, string $requestId, string $side = 'member'): int
    {
        $now = now();
        if ($threadId === null) {
            $threadId = DB::table('employee_logistics_threads')->insertGetId([
                'member_user_id' => $author->id, 'member_name' => 'Pat Member', 'message_count' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return (int) DB::table('employee_logistics_messages')->insertGetId([
            'thread_id' => $threadId, 'author_user_id' => $author->id, 'author_side' => $side,
            'author_name' => 'Racer', 'body' => $body, 'client_request_id' => $requestId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
