<?php

declare(strict_types=1);

namespace Tests\Feature\EmployeeMessaging;

use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Unread / awaiting filters, search, ordering and paging of the shared Logistics inbox. */
final class EmployeeLogisticsInboxFilterSearchTest extends TestCase
{
    use BuildsEmployeeMessagingFixtures;
    use RefreshDatabase;

    // ------------------------------------------------------------ filters

    public function test_the_unread_filter_lists_only_threads_with_unseen_member_messages(): void
    {
        $viewer = $this->logisticsResponder();
        $seen = $this->memberSends($this->messagingMember('Seen Member'));
        $unseen = $this->memberSends($this->messagingMember('Unseen Member'));
        $this->service()->markReadByLogistics($viewer, $seen->thread_id, $seen->id);

        $this->assertSame([$unseen->thread_id], $this->ids($viewer, 'unread'));
        $this->assertEqualsCanonicalizing([$seen->thread_id, $unseen->thread_id], $this->ids($viewer, 'all'));
    }

    public function test_the_awaiting_filter_lists_threads_whose_last_word_is_the_members(): void
    {
        $viewer = $this->logisticsResponder();
        $answeredMember = $this->messagingMember('Answered Member');
        $waitingMember = $this->messagingMember('Waiting Member');
        $answered = $this->memberSends($answeredMember);
        $waiting = $this->memberSends($waitingMember);
        $this->logisticsReplies($viewer, $answered);

        $this->assertSame([$waiting->thread_id], $this->ids($viewer, 'awaiting'));

        $this->memberReplies($answeredMember, $answered, 'Thanks, one more thing');
        $this->assertEqualsCanonicalizing([$answered->thread_id, $waiting->thread_id], $this->ids($viewer, 'awaiting'));
    }

    public function test_awaiting_does_not_depend_on_having_been_read(): void
    {
        $viewer = $this->logisticsResponder();
        $message = $this->memberSends($this->messagingMember());
        $this->service()->markReadByLogistics($viewer, $message->thread_id, $message->id);

        $this->assertSame([], $this->ids($viewer, 'unread'));
        $this->assertSame([$message->thread_id], $this->ids($viewer, 'awaiting'));
    }

    public function test_an_unknown_filter_behaves_like_all(): void
    {
        $viewer = $this->logisticsResponder();
        $message = $this->memberSends($this->messagingMember());
        $this->logisticsReplies($viewer, $message);

        $this->assertSame([$message->thread_id], $this->ids($viewer, 'bogus'));
        $this->assertSame([$message->thread_id], $this->ids($viewer, ''));
    }

    public function test_a_filter_and_a_search_combine(): void
    {
        $viewer = $this->logisticsResponder();
        $a = $this->memberSends($this->messagingMember('Alpha Member'), 'Need gloves');
        $b = $this->memberSends($this->messagingMember('Bravo Member'), 'Need gloves');
        $this->service()->markReadByLogistics($viewer, $a->thread_id, $a->id);

        $this->assertSame([$b->thread_id], $this->ids($viewer, 'unread', 'gloves'));
        $this->assertSame([$a->thread_id], $this->ids($viewer, 'all', 'alpha'));
        $this->assertSame([], $this->ids($viewer, 'unread', 'alpha'));
    }

    // ------------------------------------------------------------ search

    public function test_search_finds_a_member_by_name_case_insensitively(): void
    {
        $viewer = $this->logisticsResponder();
        $pat = $this->memberSends($this->messagingMember('Patricia Quintela'));
        $this->memberSends($this->messagingMember('Someone Else'));

        foreach (['quintela', 'QUINTELA', 'PatRicia', '  quintela  ', 'a quin'] as $term) {
            $this->assertSame([$pat->thread_id], $this->ids($viewer, 'all', $term), $term);
        }
    }

    public function test_search_finds_a_member_by_employee_number_and_station(): void
    {
        $viewer = $this->logisticsResponder();
        $one = $this->memberSends($this->messagingMember('One Member', ['employee_id' => 'FF-7788', 'station' => 'Station 5']));
        $this->memberSends($this->messagingMember('Two Member', ['employee_id' => 'FF-1234', 'station' => 'Station 2']));

        $this->assertSame([$one->thread_id], $this->ids($viewer, 'all', 'ff-7788'));
        $this->assertSame([$one->thread_id], $this->ids($viewer, 'all', '7788'));
        $this->assertSame([$one->thread_id], $this->ids($viewer, 'all', 'station 5'));
    }

    public function test_search_finds_the_subject(): void
    {
        $viewer = $this->logisticsResponder();
        $a = $this->memberSends($this->messagingMember('A Member'), 'Body one', 'Radio battery swap');
        $this->memberSends($this->messagingMember('B Member'), 'Body two', 'Boots');

        $this->assertSame([$a->thread_id], $this->ids($viewer, 'all', 'BATTERY'));
    }

    public function test_search_finds_text_in_any_message_not_only_the_latest_one(): void
    {
        $viewer = $this->logisticsResponder();
        $member = $this->messagingMember();
        $first = $this->memberSends($member, 'The hydrant wrench is missing from the cab.');
        $this->memberReplies($member, $first, 'Any update?');
        $this->logisticsReplies($viewer, $first, 'Ordering a replacement spanner now.');
        $other = $this->memberSends($this->messagingMember('Other Member'), 'Nothing relevant here.');

        $this->assertSame([$first->thread_id], $this->ids($viewer, 'all', 'HYDRANT'));
        $this->assertSame([$first->thread_id], $this->ids($viewer, 'all', 'replacement spanner'), 'Logistics replies are searchable too.');
        $this->assertSame([$other->thread_id], $this->ids($viewer, 'all', 'relevant'));
        $this->assertSame([], $this->ids($viewer, 'all', 'zebra'));
    }

    public function test_a_blank_search_means_no_search(): void
    {
        $viewer = $this->logisticsResponder();
        $a = $this->memberSends($this->messagingMember('A Member'));
        $b = $this->memberSends($this->messagingMember('B Member'));

        foreach ([null, '', '   '] as $term) {
            $this->assertEqualsCanonicalizing([$a->thread_id, $b->thread_id], $this->ids($viewer, 'all', $term));
        }
    }

    public function test_percent_and_underscore_are_literal_characters_not_wildcards(): void
    {
        $viewer = $this->logisticsResponder();
        $percent = $this->memberSends($this->messagingMember('Percent Member'), 'Need 100% more hose');
        $plain = $this->memberSends($this->messagingMember('Plain Member'), 'Need 100 more hose');
        $under = $this->memberSends($this->messagingMember('Under Member'), 'Unit_7 radio is dead');
        $this->memberSends($this->messagingMember('Other Member'), 'UnitX7 radio is fine');

        $this->assertSame([$percent->thread_id], $this->ids($viewer, 'all', '%'));
        $this->assertSame([$percent->thread_id], $this->ids($viewer, 'all', '100%'));
        $this->assertSame([$under->thread_id], $this->ids($viewer, 'all', '_'));
        $this->assertSame([$under->thread_id], $this->ids($viewer, 'all', 't_7'));
        $this->assertSame([], $this->ids($viewer, 'all', '%%'));
        $this->assertSame([], $this->ids($viewer, 'all', '__'));
        $this->assertEqualsCanonicalizing([$percent->thread_id, $plain->thread_id], $this->ids($viewer, 'all', '100'));
    }

    public function test_the_escape_character_and_quotes_are_literal_too(): void
    {
        $viewer = $this->logisticsResponder();
        $bang = $this->memberSends($this->messagingMember('Bang Member'), 'Wow! It broke');
        $this->memberSends($this->messagingMember('Calm Member'), 'It broke');

        $this->assertSame([$bang->thread_id], $this->ids($viewer, 'all', '!'));
        $this->assertSame([$bang->thread_id], $this->ids($viewer, 'all', 'wow!'));
        $this->assertSame([], $this->ids($viewer, 'all', '!!'));
        $this->assertSame([], $this->ids($viewer, 'all', "x' OR '1'='1"));
        $this->assertSame([], $this->ids($viewer, 'all', "'; DROP TABLE employee_logistics_threads;--"));
        $this->assertSame(2, EmployeeLogisticsMessage::query()->count());
    }

    public function test_an_overlong_search_term_is_cut_not_an_error(): void
    {
        $viewer = $this->logisticsResponder();
        $this->memberSends($this->messagingMember());

        $this->assertSame([], $this->ids($viewer, 'all', str_repeat('zq', 300)));
    }

    public function test_a_search_reaches_only_the_inbox_the_viewer_may_see(): void
    {
        $member = $this->messagingMember('Secret Member');
        $this->memberSends($member, 'Highly private words');

        $this->assertSame([], $this->service()->memberThreads($this->messagingMember('Snoop Member'))['threads']->all());
        $this->assertSame(1, $this->service()->memberThreads($member)['threads']->count());
    }

    // ------------------------------------------------------------ ordering and paging

    public function test_threads_are_ordered_by_most_recent_activity_for_both_inboxes(): void
    {
        $viewer = $this->logisticsResponder();
        $a = $this->messagingMember('A Member');
        $b = $this->messagingMember('B Member');
        $this->travelTo(now()->startOfMinute());
        $first = $this->memberSends($a, 'a');
        $this->travel(1)->minutes();
        $second = $this->memberSends($b, 'b');
        $this->travel(1)->minutes();
        $third = $this->memberSends($a, 'c');

        $this->assertSame([$third->thread_id, $second->thread_id, $first->thread_id], $this->ids($viewer));
        $this->assertSame([$third->thread_id, $first->thread_id], $this->service()->memberThreads($a)['threads']->pluck('id')->all());

        $this->travel(1)->minutes();
        $this->logisticsReplies($viewer, $first, 'bump');

        $this->assertSame([$first->thread_id, $third->thread_id, $second->thread_id], $this->ids($viewer));
        $this->assertSame([$first->thread_id, $third->thread_id], $this->service()->memberThreads($a)['threads']->pluck('id')->all());
    }

    public function test_threads_active_in_the_same_second_fall_back_to_newest_first(): void
    {
        $viewer = $this->logisticsResponder();
        $this->travelTo(now()->startOfMinute());
        $ids = [];
        foreach (['A', 'B', 'C'] as $letter) {
            $ids[] = $this->memberSends($this->messagingMember($letter.' Member'))->thread_id;
        }

        $this->assertSame(array_reverse($ids), $this->ids($viewer));
    }

    public function test_the_inbox_pages_twenty_at_a_time_and_reports_more(): void
    {
        $viewer = $this->logisticsViewer();
        for ($i = 1; $i <= 21; $i++) {
            $this->memberSends($this->messagingMember('Member '.$i));
        }

        $page = $this->service()->logisticsThreads($viewer);
        $this->assertCount(20, $page['threads']);
        $this->assertTrue($page['hasMore']);

        $wider = $this->service()->logisticsThreads($viewer, 'all', null, 40);
        $this->assertCount(21, $wider['threads']);
        $this->assertFalse($wider['hasMore']);

        $exact = $this->service()->logisticsThreads($viewer, 'all', null, 21);
        $this->assertCount(21, $exact['threads']);
        $this->assertFalse($exact['hasMore']);
    }

    public function test_the_window_is_clamped_to_between_one_and_five_hundred(): void
    {
        $viewer = $this->logisticsViewer();
        $this->memberSends($this->messagingMember('One Member'));
        $this->memberSends($this->messagingMember('Two Member'));

        $zero = $this->service()->logisticsThreads($viewer, 'all', null, 0);
        $this->assertCount(1, $zero['threads']);
        $this->assertTrue($zero['hasMore']);
        $this->assertCount(2, $this->service()->logisticsThreads($viewer, 'all', null, 100000)['threads']);
    }

    public function test_the_member_list_pages_the_same_way(): void
    {
        $member = $this->messagingMember();
        for ($i = 1; $i <= 22; $i++) {
            $this->memberSends($member, 'topic '.$i);
            if ($i % 9 === 0) {
                $this->travel(61)->seconds();
            }
        }

        $page = $this->service()->memberThreads($member);
        $this->assertCount(20, $page['threads']);
        $this->assertTrue($page['hasMore']);
        $this->assertFalse($this->service()->memberThreads($member, 30)['hasMore']);
    }

    /** @return list<int> */
    private function ids(User $viewer, string $filter = 'all', ?string $search = null): array
    {
        return $this->service()->logisticsThreads($viewer, $filter, $search)['threads']->pluck('id')->all();
    }
}
