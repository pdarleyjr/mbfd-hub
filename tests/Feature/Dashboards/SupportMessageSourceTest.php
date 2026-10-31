<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Filament\Widgets\EmployeeMessagesWidget;
use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Services\Dashboards\Sources\Station\SupportMessageSource;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardQuery;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueSourceRegistry;
use App\Support\OperationalDisplayWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\EmployeeMessaging\BuildsEmployeeMessagingFixtures;
use Tests\TestCase;

final class SupportMessageSourceTest extends TestCase
{
    use BuildsEmployeeMessagingFixtures;
    use RefreshDatabase;

    private function source(): SupportMessageSource
    {
        return app(SupportMessageSource::class);
    }

    public function test_it_is_registered_for_the_overview_only(): void
    {
        $registry = app(IssueSourceRegistry::class);

        $this->assertSame(['overview'], array_map(fn (DashboardView $v): string => $v->value, $registry->get('support_message')->views()));
        $this->assertNotContains('support_message', array_map(fn ($s) => $s->type(), $registry->forView(DashboardView::StationOperations)));
    }

    public function test_messages_never_appear_in_display_mode_even_through_a_direct_call(): void
    {
        $this->memberSends($this->messagingMember('Ann Jones'), 'Boots arrived damaged');
        $responder = $this->logisticsResponder();

        $this->assertFalse($this->source()->visibleTo($responder, true));
        $this->assertSame([], $this->source()->rows($responder, DashboardQuery::now(displayMode: true), 50));
        $this->assertSame(0, array_sum($this->source()->counts($responder, DashboardQuery::now(displayMode: true))));
        $this->assertCount(1, $this->source()->rows($responder, DashboardQuery::now(), 50));
    }

    public function test_only_accounts_the_widget_shows_messages_to_can_see_them(): void
    {
        $message = $this->memberSends($this->messagingMember('Ann Jones'));
        $unrelated = $this->unrelatedAdmin();

        $this->assertSame([], $this->source()->rows($unrelated, DashboardQuery::now(), 50));
        $this->assertNull($this->source()->detail($unrelated, (string) $message->thread_id));
        $this->assertSame('forbidden', $this->source()->perform($unrelated, (string) $message->thread_id, 'reply', ['message' => 'Hi'], 'x')->outcome);

        $viewer = $this->logisticsViewer();
        $rows = $this->source()->rows($viewer, DashboardQuery::now(), 50);
        $this->assertCount(1, $rows);
        $this->assertSame('Ann Jones', $rows[0]->actorLabel);
        $this->assertNull($rows[0]->imageKey);
        $this->assertSame([], $rows[0]->tags);
    }

    public function test_rows_counts_and_detail_never_move_the_read_marker(): void
    {
        $message = $this->memberSends($this->messagingMember());
        $responder = $this->logisticsResponder();

        $this->source()->rows($responder, DashboardQuery::now(), 50);
        $this->source()->counts($responder, DashboardQuery::now());
        $detail = $this->source()->detail($responder, (string) $message->thread_id);

        $this->assertNotNull($detail);
        $this->assertSame(['reply', 'mark_read'], array_map(fn ($a) => $a->key, $detail->actions));
        $thread = $this->storedThread($message);
        $this->assertNull($thread->logistics_read_message_id);
        $this->assertNull($thread->logistics_read_by_user_id);
        $this->assertSame(1, $this->service()->logisticsUnreadCount($responder));
    }

    public function test_counts_match_rows_and_replied_threads_count_as_resolved_inside_the_window(): void
    {
        $responder = $this->logisticsResponder();
        $awaiting = $this->memberSends($this->messagingMember('A'));
        $replied = $this->memberSends($this->messagingMember('B'));
        $this->logisticsReplies($responder, $replied);
        $old = $this->memberSends($this->messagingMember('C'));
        $this->logisticsReplies($responder, $old);
        DB::table('employee_logistics_threads')->where('id', $old->thread_id)->update(['last_message_at' => now()->subDays(2)]);

        $rows = $this->source()->rows($responder, DashboardQuery::now(), 50);
        $counts = $this->source()->counts($responder, DashboardQuery::now());

        $this->assertSame([$awaiting->thread_id, $replied->thread_id], array_map(fn (DashboardIssue $i): int => (int) $i->id, $rows));
        $this->assertSame(['awaiting_response', 'resolved'], array_map(fn (DashboardIssue $i): string => $i->stage, $rows));
        $this->assertSame(1, $counts['open']);
        $this->assertSame(1, $counts['awaiting_response']);
        $this->assertSame(1, $counts['resolved_today']);
        $this->assertSame(0, $counts['high']);
        $this->assertSame($this->service()->logisticsAwaitingCount($responder), $counts['awaiting_response']);
        $this->assertSame(count($rows), $counts['open'] + $counts['resolved_today']);
    }

    public function test_the_operational_day_starts_at_eight_across_the_dst_change(): void
    {
        $responder = $this->logisticsResponder();
        $thread = $this->memberSends($this->messagingMember());
        $this->logisticsReplies($responder, $thread);
        // 2026-11-01 is the day clocks fall back; 08:00 EST is 13:00 UTC.
        $query = new DashboardQuery(OperationalDisplayWindow::forNow('2026-11-01 09:00:00'));

        DB::table('employee_logistics_threads')->update(['last_message_at' => '2026-11-01 12:59:59']);
        $this->assertSame(0, $this->source()->counts($responder, $query)['resolved_today']);

        DB::table('employee_logistics_threads')->update(['last_message_at' => '2026-11-01 13:00:00']);
        $this->assertSame(1, $this->source()->counts($responder, $query)['resolved_today']);
        $this->assertCount(1, $this->source()->rows($responder, $query, 50));
    }

    public function test_reply_goes_through_the_messaging_service_and_marks_seen_like_the_widget(): void
    {
        $member = $this->messagingMember('Bo Smith');
        $viaDashboard = $this->memberSends($member, 'Need a size swap');
        $viaWidget = $this->memberSends($this->messagingMember('Cy Doe'), 'Need gloves');
        $responder = $this->logisticsResponder();
        $version = $this->source()->detail($responder, (string) $viaDashboard->thread_id)->issue->version;

        $result = $this->source()->perform($responder, (string) $viaDashboard->thread_id, 'reply', ['message' => 'Swap approved.'], $version);

        $this->assertSame('done', $result->outcome);
        $reply = EmployeeLogisticsMessage::query()->where('thread_id', $viaDashboard->thread_id)->latest('id')->firstOrFail();
        $this->assertSame(EmployeeLogisticsMessage::SIDE_LOGISTICS, $reply->author_side);
        $this->assertSame((int) $responder->id, (int) $reply->author_user_id);
        $this->assertSame('Swap approved.', $reply->body);

        $this->actingAs($responder);
        Livewire::test(EmployeeMessagesWidget::class)
            ->call('toggleThread', $viaWidget->thread_id)
            ->call('sendReply', 'Gloves on the way.', $this->rid())
            ->assertReturned(['ok' => true]);
        $widgetReply = EmployeeLogisticsMessage::query()->where('thread_id', $viaWidget->thread_id)->latest('id')->firstOrFail();

        foreach ([[$viaDashboard, $reply], [$viaWidget, $widgetReply]] as [$sent, $answer]) {
            $thread = $this->storedThread($sent);
            $this->assertSame((int) $answer->id, $thread->logistics_read_message_id);
            $this->assertSame((int) $responder->id, $thread->logistics_read_by_user_id);
            $this->assertSame(EmployeeLogisticsMessage::SIDE_LOGISTICS, $thread->last_message_side);
            $this->assertSame(2, $thread->message_count);
        }
    }

    public function test_an_identical_repeat_is_idempotent(): void
    {
        $message = $this->memberSends($this->messagingMember());
        $responder = $this->logisticsResponder();
        $version = $this->source()->detail($responder, (string) $message->thread_id)->issue->version;

        $first = $this->source()->perform($responder, (string) $message->thread_id, 'reply', ['message' => 'On it.'], $version);
        $second = $this->source()->perform($responder, (string) $message->thread_id, 'reply', ['message' => 'On it.'], $version);

        $this->assertSame(['done', 'done'], [$first->outcome, $second->outcome]);
        $this->assertSame(2, $this->storedThread($message)->message_count);
    }

    public function test_a_second_responder_with_a_stale_view_gets_a_conflict_and_nothing_is_sent(): void
    {
        $message = $this->memberSends($this->messagingMember());
        $first = $this->logisticsResponder();
        $second = $this->logisticsResponder();
        $seenBySecond = $this->source()->detail($second, (string) $message->thread_id)->issue->version;
        $this->logisticsReplies($first, $message, 'Handled by first responder.');

        $result = $this->source()->perform($second, (string) $message->thread_id, 'reply', ['message' => 'Duplicate answer'], $seenBySecond);

        $this->assertSame('conflict', $result->outcome);
        $this->assertSame(2, $this->storedThread($message)->message_count);
        $this->assertFalse(EmployeeLogisticsMessage::query()->where('body', 'Duplicate answer')->exists());

        $fresh = $this->source()->detail($second, (string) $message->thread_id)->issue->version;
        $this->assertSame('done', $this->source()->perform($second, (string) $message->thread_id, 'reply', ['message' => 'Adding detail.'], $fresh)->outcome);
        $this->assertSame(3, $this->storedThread($message)->message_count);
    }

    public function test_a_read_only_viewer_cannot_reply_but_can_explicitly_mark_read(): void
    {
        $message = $this->memberSends($this->messagingMember());
        $viewer = $this->logisticsViewer();
        $detail = $this->source()->detail($viewer, (string) $message->thread_id);

        $this->assertSame(['mark_read'], array_map(fn ($a) => $a->key, $detail->actions));
        $this->assertSame('forbidden', $this->source()->perform($viewer, (string) $message->thread_id, 'reply', ['message' => 'Hi'], $detail->issue->version)->outcome);
        $this->assertSame(1, $this->storedThread($message)->message_count);

        $this->assertSame('done', $this->source()->perform($viewer, (string) $message->thread_id, 'mark_read', [], $detail->issue->version)->outcome);
        $this->assertSame('done', $this->source()->perform($viewer, (string) $message->thread_id, 'mark_read', [], $detail->issue->version)->outcome);
        $thread = $this->storedThread($message);
        $this->assertSame((int) $message->id, $thread->logistics_read_message_id);
        $this->assertSame((int) $viewer->id, $thread->logistics_read_by_user_id);

        $after = $this->source()->detail($viewer, (string) $message->thread_id);
        $this->assertSame([], $after->actions);
        $this->assertNotNull($after->readOnlyReason);
    }

    public function test_mark_read_with_a_stale_view_does_not_cover_a_newer_message(): void
    {
        $member = $this->messagingMember();
        $message = $this->memberSends($member);
        $responder = $this->logisticsResponder();
        $version = $this->source()->detail($responder, (string) $message->thread_id)->issue->version;
        $this->memberReplies($member, $message, 'One more thing');

        $this->assertSame('conflict', $this->source()->perform($responder, (string) $message->thread_id, 'mark_read', [], $version)->outcome);
        $this->assertNull($this->storedThread($message)->logistics_read_message_id);
    }

    public function test_invalid_reply_text_returns_the_domain_message(): void
    {
        $message = $this->memberSends($this->messagingMember());
        $responder = $this->logisticsResponder();
        $version = $this->source()->detail($responder, (string) $message->thread_id)->issue->version;

        $result = $this->source()->perform($responder, (string) $message->thread_id, 'reply', ['message' => '   '], $version);

        $this->assertSame('invalid', $result->outcome);
        $this->assertSame('Type a message before sending.', $result->errors['message']);
    }

    public function test_rows_use_a_fixed_number_of_queries(): void
    {
        $responder = $this->logisticsResponder();
        $this->memberSends($this->messagingMember());
        $this->source()->rows($responder, DashboardQuery::now(), 50);
        $small = $this->queriesFor(fn () => $this->source()->rows($responder, DashboardQuery::now(), 50));
        for ($i = 0; $i < 49; $i++) {
            $this->memberSends($this->messagingMember('M'.$i));
        }

        $large = $this->queriesFor(fn () => $this->assertCount(50, $this->source()->rows($responder, DashboardQuery::now(), 50)));

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(2, $large);
    }

    private function queriesFor(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
