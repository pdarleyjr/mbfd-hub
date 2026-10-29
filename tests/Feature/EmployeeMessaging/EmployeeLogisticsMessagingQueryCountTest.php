<?php

declare(strict_types=1);

namespace Tests\Feature\EmployeeMessaging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The inbox and badges must cost a constant number of queries however many threads exist. */
final class EmployeeLogisticsMessagingQueryCountTest extends TestCase
{
    use BuildsEmployeeMessagingFixtures;
    use RefreshDatabase;

    private const SMALL_BUDGET = 12;

    public function test_listing_the_logistics_inbox_does_not_grow_with_the_number_of_threads(): void
    {
        $viewer = $this->logisticsResponder();
        $this->startThreads(3);
        $this->service()->logisticsThreads($viewer); // warm the viewer's lazily loaded roles
        $few = $this->queriesFor(fn () => $this->service()->logisticsThreads($viewer, 'all', null, 30));

        $this->startThreads(27, 3);
        $many = $this->queriesFor(fn () => $this->service()->logisticsThreads($viewer, 'all', null, 30));

        $this->assertSame($few, $many, 'Query count must not depend on the number of threads (N+1).');
        $this->assertLessThanOrEqual(self::SMALL_BUDGET, $many);
        $this->assertCount(30, $this->service()->logisticsThreads($viewer, 'all', null, 30)['threads']);
    }

    public function test_filters_and_search_stay_constant_too(): void
    {
        $viewer = $this->logisticsViewer();
        $this->startThreads(3);
        $this->service()->logisticsThreads($viewer); // warm the viewer's lazily loaded roles
        $few = [
            $this->queriesFor(fn () => $this->service()->logisticsThreads($viewer, 'unread', null, 30)),
            $this->queriesFor(fn () => $this->service()->logisticsThreads($viewer, 'awaiting', 'member', 30)),
            $this->queriesFor(fn () => $this->service()->logisticsUnreadCount($viewer)),
        ];

        $this->startThreads(27, 3);
        $many = [
            $this->queriesFor(fn () => $this->service()->logisticsThreads($viewer, 'unread', null, 30)),
            $this->queriesFor(fn () => $this->service()->logisticsThreads($viewer, 'awaiting', 'member', 30)),
            $this->queriesFor(fn () => $this->service()->logisticsUnreadCount($viewer)),
        ];

        $this->assertSame($few, $many);
        foreach ($many as $count) {
            $this->assertLessThanOrEqual(self::SMALL_BUDGET, $count);
        }
    }

    public function test_the_member_list_and_badge_stay_constant(): void
    {
        $member = $this->messagingMember();
        $this->memberSends($member);
        $this->service()->memberThreads($member);
        $few = [
            $this->queriesFor(fn () => $this->service()->memberThreads($member)),
            $this->queriesFor(fn () => $this->service()->memberUnreadCount($member)),
        ];

        for ($i = 0; $i < 9; $i++) {
            $this->memberSends($member, 'topic '.$i);
        }
        $this->travel(61)->seconds();
        for ($i = 0; $i < 9; $i++) {
            $this->memberSends($member, 'more '.$i);
        }
        $many = [
            $this->queriesFor(fn () => $this->service()->memberThreads($member)),
            $this->queriesFor(fn () => $this->service()->memberUnreadCount($member)),
        ];

        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(self::SMALL_BUDGET, max($many));
    }

    public function test_opening_a_long_thread_reads_its_history_in_one_query(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        $thread = $this->storedThread($first);
        $few = $this->queriesFor(fn () => $this->service()->messagesFor($thread));

        for ($i = 0; $i < 9; $i++) {
            $this->memberReplies($member, $first, 'reply '.$i);
        }
        $this->travel(61)->seconds();
        for ($i = 0; $i < 9; $i++) {
            $this->memberReplies($member, $first, 'again '.$i);
        }
        $many = $this->queriesFor(fn () => $this->service()->messagesFor($thread));

        $this->assertSame(1, $few);
        $this->assertSame(1, $many);
        $this->assertSame(19, $this->service()->messagesFor($thread)['messages']->count());
    }

    /** @param  callable(): mixed  $work */
    private function queriesFor(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $work();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    /** One thread each from $count different members (rank-and-file names contain "Member"). */
    private function startThreads(int $count, int $offset = 0): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->memberSends($this->messagingMember('Query Member '.($offset + $i)), 'Question number '.($offset + $i));
        }
    }
}
