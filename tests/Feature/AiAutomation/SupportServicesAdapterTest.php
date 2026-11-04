<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Filament\Admin\Pages\EmployeeMessages;
use App\Filament\Employee\Pages\MyMessages;
use App\Models\AiAutomation\AiIntakeEvent;
use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Models\EmployeeMessaging\EmployeeLogisticsThread;
use App\Services\AiAutomation\Domains\SupportServicesAdapter;
use App\Services\EmployeeMessaging\EmployeeLogisticsMessagingService;
use App\Services\EmployeeMessaging\EmployeeMessageReason;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\EmployeeMessaging\BuildsEmployeeMessagingFixtures;
use Tests\TestCase;

/** The Support Services adapter of the AI Response automation, on synthetic members and threads only. */
final class SupportServicesAdapterTest extends TestCase
{
    use BuildsEmployeeMessagingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        RateLimiter::clear('employee-logistics-message:1');
        // Only this adapter, so the suite does not depend on the other domains' adapters being present.
        config(['ai_automation.adapters' => [SupportServicesAdapter::class]]);
    }

    private function adapter(): SupportServicesAdapter
    {
        return app(SupportServicesAdapter::class);
    }

    private function switchOn(): void
    {
        DB::table('ai_automation_settings')->update(['enabled' => true]);
    }

    private function intakeFor(EmployeeLogisticsMessage $message): AiIntakeEvent
    {
        return AiIntakeEvent::query()->firstOrCreate(
            ['source_type' => SupportServicesAdapter::SOURCE_TYPE, 'source_id' => $message->id, 'event_key' => 'received'],
            ['domain' => 'support', 'origin' => 'live', 'epoch' => 1, 'state' => AiIntakeEvent::STATE_QUEUED, 'needs_human' => true],
        );
    }

    private function startWithReason(string $reason, string $body = 'Dropped my gear at Station 2.'): EmployeeLogisticsMessage
    {
        return $this->service()->startThread($this->messagingMember(), $body, null, $this->rid(), $reason);
    }

    // ------------------------------------------------------------ the hook

    public function test_a_new_member_thread_records_one_intake_when_the_switch_is_on(): void
    {
        Queue::fake();
        $this->switchOn();

        $message = $this->memberSends($this->messagingMember());

        $rows = AiIntakeEvent::query()->get();
        $this->assertCount(1, $rows);
        $this->assertSame('support', $rows[0]->domain);
        $this->assertSame('employee_logistics_message', $rows[0]->source_type);
        $this->assertSame($message->id, (int) $rows[0]->source_id);
        $this->assertSame('received', $rows[0]->event_key);
        $this->assertSame(AiIntakeEvent::ORIGIN_LIVE, $rows[0]->origin);
    }

    public function test_a_member_reply_records_its_own_intake(): void
    {
        Queue::fake();
        $this->switchOn();
        $member = $this->messagingMember();
        $first = $this->memberSends($member);

        $second = $this->memberReplies($member, $first);

        $this->assertSame([$first->id, $second->id], AiIntakeEvent::query()->orderBy('source_id')->pluck('source_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_nothing_is_recorded_while_the_switch_is_off(): void
    {
        Queue::fake();
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        $this->memberReplies($member, $first);

        $this->assertSame(0, AiIntakeEvent::query()->count());
    }

    public function test_a_staff_reply_is_never_recorded(): void
    {
        Queue::fake();
        $this->switchOn();
        $first = $this->memberSends($this->messagingMember());
        $this->assertSame(1, AiIntakeEvent::query()->count());

        $this->logisticsReplies($this->logisticsResponder(), $first);

        $this->assertSame(1, AiIntakeEvent::query()->count());
    }

    public function test_a_retried_send_does_not_record_a_second_intake(): void
    {
        Queue::fake();
        $this->switchOn();
        $member = $this->messagingMember();
        $requestId = $this->rid();

        $this->service()->startThread($member, 'Same text', null, $requestId);
        $this->service()->startThread($member, 'Same text', null, $requestId);

        $this->assertSame(1, AiIntakeEvent::query()->count());
    }

    // ------------------------------------------------------------ facts

    public function test_facts_describe_an_unanswered_member_message(): void
    {
        $member = $this->messagingMember();
        $message = $this->service()->startThread($member, 'Where do I drop off bunker gear?', 'Bunker gear', $this->rid(), EmployeeMessageReason::BUNKER_GEAR_CLEANING);

        $facts = $this->adapter()->facts($this->intakeFor($message));

        $this->assertSame('support', $facts->domain);
        $this->assertSame('support_message', $facts->kind);
        $this->assertSame($member->id, $facts->requesterUserId);
        $this->assertSame(EmployeeMessageReason::BUNKER_GEAR_CLEANING, $facts->category);
        $this->assertStringContainsString('Where do I drop off bunker gear?', (string) $facts->memberText);
        $this->assertStringContainsString('Bunker gear', (string) $facts->memberText);
        $this->assertFalse($facts->alreadyAnswered);
        $this->assertNull($facts->ineligibleReason);
        $this->assertTrue($facts->eligible());
        $this->assertNotNull($facts->submittedAt);
    }

    public function test_a_thread_without_a_reason_is_treated_as_general(): void
    {
        $message = $this->memberSends($this->messagingMember());

        $facts = $this->adapter()->facts($this->intakeFor($message));

        $this->assertSame(EmployeeMessageReason::GENERAL, $facts->category);
        $this->assertTrue($facts->eligible());
    }

    /** @return array<string, array{string, bool}> */
    public static function reasonScope(): array
    {
        return [
            'general' => [EmployeeMessageReason::GENERAL, true],
            'equipment' => [EmployeeMessageReason::EQUIPMENT, true],
            'supplies' => [EmployeeMessageReason::SUPPLIES, true],
            'bunker gear' => [EmployeeMessageReason::BUNKER_GEAR_CLEANING, true],
            'uniforms' => [EmployeeMessageReason::UNIFORMS, false],
        ];
    }

    #[DataProvider('reasonScope')]
    public function test_the_reason_decides_scope(string $reason, bool $inScope): void
    {
        $facts = $this->adapter()->facts($this->intakeFor($this->startWithReason($reason)));

        $this->assertSame($inScope, $facts->eligible());
        $this->assertSame($inScope ? null : 'out_of_scope', $facts->ineligibleReason);
    }

    public function test_every_known_reason_has_a_decision(): void
    {
        $outOfScope = SupportServicesAdapter::OUT_OF_SCOPE_REASONS;
        $this->assertSame([], array_diff($outOfScope, EmployeeMessageReason::keys()), 'An out-of-scope reason must be a real reason key.');
    }

    public function test_only_the_newest_message_of_an_unanswered_burst_is_eligible(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member, 'One');
        $second = $this->memberReplies($member, $first, 'Two');
        $third = $this->memberReplies($member, $first, 'Three');

        $oldest = $this->adapter()->facts($this->intakeFor($first));
        $middle = $this->adapter()->facts($this->intakeFor($second));
        $newest = $this->adapter()->facts($this->intakeFor($third));

        $this->assertSame('superseded_by_newer_member_message', $oldest->ineligibleReason);
        $this->assertSame('superseded_by_newer_member_message', $middle->ineligibleReason);
        $this->assertFalse($oldest->alreadyAnswered);
        $this->assertTrue($newest->eligible());
    }

    public function test_a_message_followed_by_staff_is_already_answered(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        $this->logisticsReplies($this->logisticsResponder(), $first);

        $facts = $this->adapter()->facts($this->intakeFor($first));

        $this->assertTrue($facts->alreadyAnswered);
        $this->assertFalse($facts->eligible());
    }

    public function test_a_message_followed_by_the_assistant_is_already_answered(): void
    {
        $first = $this->memberSends($this->messagingMember());
        $intake = $this->intakeFor($first);
        $facts = $this->adapter()->facts($intake);
        $this->adapter()->writeReply($intake, $facts, 'Acknowledged.');

        $this->assertTrue($this->adapter()->facts($intake)->alreadyAnswered);
    }

    public function test_a_member_message_after_a_staff_reply_is_eligible_again(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        $this->logisticsReplies($this->logisticsResponder(), $first);
        $second = $this->memberReplies($member, $first, 'Thanks, one more thing.');

        $this->assertTrue($this->adapter()->facts($this->intakeFor($second))->eligible());
        $this->assertTrue($this->adapter()->facts($this->intakeFor($first))->alreadyAnswered);
    }

    public function test_a_staff_message_is_not_a_member_submission(): void
    {
        $first = $this->memberSends($this->messagingMember());
        $staff = $this->logisticsReplies($this->logisticsResponder(), $first);

        $facts = $this->adapter()->facts($this->intakeFor($staff));

        $this->assertSame('not_member_message', $facts->ineligibleReason);
    }

    public function test_a_missing_source_is_ineligible(): void
    {
        $intake = AiIntakeEvent::query()->create([
            'domain' => 'support', 'source_type' => 'employee_logistics_message', 'source_id' => 999999, 'event_key' => 'received',
            'origin' => 'live', 'epoch' => 1, 'state' => AiIntakeEvent::STATE_QUEUED, 'needs_human' => true,
        ]);

        $this->assertSame('source_missing', $this->adapter()->facts($intake)->ineligibleReason);
        $this->assertNull($this->adapter()->memberUrl($intake));
    }

    public function test_a_departed_member_is_not_answered(): void
    {
        $member = $this->messagingMember();
        $message = $this->memberSends($member);
        $member->employeeProfile->forceFill(['roster_status' => 'departed'])->save();

        $this->assertSame('member_inactive', $this->adapter()->facts($this->intakeFor($message))->ineligibleReason);
    }

    // ------------------------------------------------------------ writeReply

    public function test_write_reply_appends_one_assistant_message_and_nothing_else_moves(): void
    {
        $member = $this->messagingMember();
        $message = $this->memberSends($member, 'Dropped off gear', 'Gear');
        $before = $this->storedThread($message);
        $intake = $this->intakeFor($message);

        $record = $this->adapter()->writeReply($intake, $this->adapter()->facts($intake), 'Acknowledged.');

        $reply = EmployeeLogisticsMessage::query()->findOrFail($record->id);
        $this->assertSame('employee_logistics_message', $record->type);
        $this->assertSame(EmployeeLogisticsMessage::SIDE_ASSISTANT, $reply->author_side);
        $this->assertNull($reply->author_user_id);
        $this->assertSame('Support Services', $reply->author_name);
        $this->assertSame('Acknowledged.', $reply->body);
        $this->assertSame($intake->id, (int) $reply->ai_intake_event_id);
        $this->assertSame($message->thread_id, (int) $reply->thread_id);

        $after = $this->storedThread($message);
        $this->assertSame($reply->id, $after->last_assistant_message_id);
        $this->assertSame($before->message_count + 1, $after->message_count);
        // The answer and read markers, the ordering and the preview are exactly as the member left them.
        foreach (['last_message_side', 'last_member_message_id', 'last_logistics_message_id', 'last_message_preview',
            'member_read_message_id', 'logistics_read_message_id', 'logistics_read_by_user_id'] as $column) {
            $this->assertSame($before->{$column}, $after->{$column}, $column.' must not move.');
        }
        $this->assertEquals($before->last_message_at, $after->last_message_at);
        $this->assertSame(EmployeeLogisticsMessage::SIDE_MEMBER, $after->last_message_side);
        $this->assertSame('Awaiting reply', $after->supportStatus());
    }

    public function test_write_reply_is_idempotent_per_intake(): void
    {
        $message = $this->memberSends($this->messagingMember());
        $intake = $this->intakeFor($message);
        $facts = $this->adapter()->facts($intake);

        $first = $this->adapter()->writeReply($intake, $facts, 'Acknowledged.');
        $second = $this->adapter()->writeReply($intake, $facts, 'Acknowledged.');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, EmployeeLogisticsMessage::query()->where('author_side', 'assistant')->count());
        $this->assertSame(2, $this->storedThread($message)->message_count);
    }

    public function test_two_assistant_messages_in_different_threads_do_not_collide_on_a_null_author(): void
    {
        $a = $this->memberSends($this->messagingMember('Ann'));
        $b = $this->memberSends($this->messagingMember('Bo'));

        $this->adapter()->writeReply($this->intakeFor($a), $this->adapter()->facts($this->intakeFor($a)), 'Acknowledged.');
        $this->adapter()->writeReply($this->intakeFor($b), $this->adapter()->facts($this->intakeFor($b)), 'Acknowledged.');

        $this->assertSame(2, EmployeeLogisticsMessage::query()->where('author_side', 'assistant')->count());
    }

    public function test_write_reply_sends_no_mail_and_raises_no_notification(): void
    {
        $message = $this->memberSends($this->messagingMember()); // the member's own send alerts Support Services, as before
        $intake = $this->intakeFor($message);
        Mail::fake();
        Notification::fake();
        Queue::fake();

        $this->adapter()->writeReply($intake, $this->adapter()->facts($intake), 'Acknowledged.');

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_the_thread_stays_in_the_staff_awaiting_queue_and_unread_for_staff(): void
    {
        $admin = $this->logisticsResponder();
        $message = $this->memberSends($this->messagingMember());
        $intake = $this->intakeFor($message);
        $this->adapter()->writeReply($intake, $this->adapter()->facts($intake), 'Acknowledged.');

        $awaiting = $this->service()->logisticsThreads($admin, EmployeeLogisticsMessagingService::FILTER_AWAITING_REPLY)['threads'];
        $unread = $this->service()->logisticsThreads($admin, EmployeeLogisticsMessagingService::FILTER_UNREAD)['threads'];

        $this->assertSame([$message->thread_id], $awaiting->pluck('id')->all());
        $this->assertSame([$message->thread_id], $unread->pluck('id')->all());
        $this->assertSame(1, $this->service()->logisticsAwaitingCount($admin));
        $this->assertSame(1, $this->service()->logisticsUnreadCount($admin));
    }

    public function test_opening_the_thread_as_staff_marks_the_member_message_read_not_the_assistant_one(): void
    {
        $admin = $this->logisticsResponder();
        $message = $this->memberSends($this->messagingMember());
        $intake = $this->intakeFor($message);
        $reply = $this->adapter()->writeReply($intake, $this->adapter()->facts($intake), 'Acknowledged.');

        $this->service()->markReadByLogistics($admin, $message->thread_id, $reply->id);

        $thread = $this->storedThread($message);
        $this->assertSame($message->id, $thread->logistics_read_message_id);
        $this->assertSame(0, $this->service()->logisticsUnreadCount($admin));
        $this->assertSame(1, $this->service()->logisticsAwaitingCount($admin), 'Reading is not answering.');
    }

    public function test_staff_can_still_reply_after_the_assistant_and_the_thread_is_then_answered(): void
    {
        $admin = $this->logisticsResponder();
        $message = $this->memberSends($this->messagingMember());
        $intake = $this->intakeFor($message);
        $this->adapter()->writeReply($intake, $this->adapter()->facts($intake), 'Acknowledged.');

        $staffReply = $this->logisticsReplies($admin, $message, 'Drop it at Station 2 any time.');

        $thread = $this->storedThread($message);
        $this->assertSame(EmployeeLogisticsMessage::SIDE_LOGISTICS, $thread->last_message_side);
        $this->assertSame($staffReply->id, $thread->last_logistics_message_id);
        $this->assertSame('Replied', $thread->supportStatus());
        $this->assertSame(0, $this->service()->logisticsAwaitingCount($admin));
        $this->assertSame(3, $thread->message_count);
    }

    public function test_the_member_sees_the_assistant_message_as_new_and_reading_clears_it(): void
    {
        $member = $this->messagingMember();
        $message = $this->memberSends($member);
        $this->assertSame(0, $this->service()->memberUnreadCount($member));
        $intake = $this->intakeFor($message);
        $reply = $this->adapter()->writeReply($intake, $this->adapter()->facts($intake), 'Acknowledged.');

        $this->assertSame(1, $this->service()->memberUnreadCount($member));
        $this->assertTrue($this->storedThread($message)->isUnreadForMember());

        $this->service()->markReadByMember($member, $message->thread_id, $reply->id);

        $this->assertSame(0, $this->service()->memberUnreadCount($member));
        $this->assertSame($reply->id, $this->storedThread($message)->member_read_message_id);
    }

    public function test_the_member_unread_marker_ignores_a_thread_that_only_has_its_own_messages(): void
    {
        $member = $this->messagingMember();
        $this->memberSends($member);

        $this->assertSame(0, $this->service()->memberUnreadCount($member));
    }

    // ------------------------------------------------------------ rendering

    public function test_the_member_page_shows_the_sender_as_ai_response(): void
    {
        $member = $this->messagingMember();
        $message = $this->memberSends($member, 'Dropped off my gear');
        $intake = $this->intakeFor($message);
        $this->adapter()->writeReply($intake, $this->adapter()->facts($intake), 'Acknowledged.');
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        $this->actingAs($member);

        Livewire::test(MyMessages::class)
            ->call('openThread', $message->thread_id)
            ->assertSee('Support Services')->assertDontSee('AI Response')
            ->assertSee('Acknowledged.')
            ->assertDontSee('emsg-bubble--assistant', false)
            ->assertSee('Sent, awaiting reply');
    }

    public function test_the_staff_page_shows_the_sender_as_ai_response_and_still_awaiting_a_human(): void
    {
        $message = $this->memberSends($this->messagingMember('Ann Jones'), 'Dropped off my gear');
        $intake = $this->intakeFor($message);
        $this->adapter()->writeReply($intake, $this->adapter()->facts($intake), 'Acknowledged.');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->logisticsResponder());

        Livewire::test(EmployeeMessages::class)
            ->assertSee('Awaiting reply')
            ->call('openThread', $message->thread_id)
            ->assertSee('Support Services (automated)')
            ->assertSee('Acknowledged.')
            ->assertSee('Dropped off my gear')
            ->assertSee('Awaiting reply');

        $this->assertSame(EmployeeLogisticsMessage::SIDE_MEMBER, $this->storedThread($message)->last_message_side);
    }

    public function test_an_older_client_does_not_crash_on_the_new_side(): void
    {
        $message = $this->memberSends($this->messagingMember());
        $intake = $this->intakeFor($message);
        $reply = $this->adapter()->writeReply($intake, $this->adapter()->facts($intake), 'Acknowledged.');
        $row = EmployeeLogisticsMessage::query()->findOrFail($reply->id);

        $this->assertFalse($row->isFromLogistics());
        $this->assertTrue($row->isFromAssistant());
        $this->assertNull($row->author, 'No author user: nothing can mistake it for a staff member.');
    }

    // ------------------------------------------------------------ url and backlog

    public function test_the_member_url_opens_the_thread(): void
    {
        $message = $this->memberSends($this->messagingMember());

        $this->assertSame('/employee/messages?thread='.$message->thread_id, $this->adapter()->memberUrl($this->intakeFor($message)));
    }

    public function test_backlog_returns_the_one_unanswered_message_per_open_thread_in_id_order(): void
    {
        $admin = $this->logisticsResponder();
        $ann = $this->messagingMember('Ann');
        $bo = $this->messagingMember('Bo');
        $cy = $this->messagingMember('Cy');
        $di = $this->messagingMember('Di');

        $annFirst = $this->memberSends($ann, 'Ann one');
        $annLast = $this->memberReplies($ann, $annFirst, 'Ann two');   // unanswered burst: only this one
        $boThread = $this->memberSends($bo, 'Bo one');
        $this->logisticsReplies($admin, $boThread);                    // answered by staff: excluded
        $cyThread = $this->memberSends($cy, 'Cy one');
        $cyIntake = $this->intakeFor($cyThread);
        $this->adapter()->writeReply($cyIntake, $this->adapter()->facts($cyIntake), 'Acknowledged.'); // answered by assistant: excluded
        $uniform = $this->startWithReason(EmployeeMessageReason::UNIFORMS);                           // out of scope: excluded
        $diThread = $this->memberSends($di, 'Di one');
        $diLast = $this->memberReplies($di, $diThread, 'Di two');
        $this->logisticsReplies($admin, $diThread);
        $diAgain = $this->memberReplies($di, $diThread, 'Di three'); // answered, then asked again: this one is open

        $all = $this->adapter()->backlogCandidates('employee_logistics_message', 0, 50);

        $this->assertSame([$annLast->id, $diAgain->id], array_map(fn ($c) => $c->sourceId, $all));
        $this->assertSame('employee_logistics_message', $all[0]->sourceType);
        $this->assertSame('received', $all[0]->eventKey);
        $this->assertNotNull($all[0]->submittedAt);
        $this->assertNotContains($uniform->id, array_map(fn ($c) => $c->sourceId, $all));
        $this->assertNotContains($diLast->id, array_map(fn ($c) => $c->sourceId, $all));
    }

    public function test_backlog_pages_by_id_with_a_limit_and_ignores_other_source_types(): void
    {
        $one = $this->memberSends($this->messagingMember('A'));
        $two = $this->memberSends($this->messagingMember('B'));
        $three = $this->memberSends($this->messagingMember('C'));

        $firstPage = $this->adapter()->backlogCandidates('employee_logistics_message', 0, 2);
        $secondPage = $this->adapter()->backlogCandidates('employee_logistics_message', $firstPage[1]->sourceId, 2);

        $this->assertSame([$one->id, $two->id], array_map(fn ($c) => $c->sourceId, $firstPage));
        $this->assertSame([$three->id], array_map(fn ($c) => $c->sourceId, $secondPage));
        $this->assertSame([], $this->adapter()->backlogCandidates('apparatus_issue_message', 0, 10));
        $this->assertSame(['employee_logistics_message'], $this->adapter()->backlogSourceTypes());
        $this->assertSame(['employee_logistics_message'], $this->adapter()->sourceTypes());
        $this->assertSame('support', $this->adapter()->domain());
    }

    public function test_backlog_excludes_a_departed_members_thread_only_through_facts_not_the_query(): void
    {
        // The query is a cheap prefilter; facts() stays the single authority on whether to answer.
        $member = $this->messagingMember();
        $message = $this->memberSends($member);
        $member->employeeProfile->forceFill(['roster_status' => 'departed'])->save();

        $candidates = $this->adapter()->backlogCandidates('employee_logistics_message', 0, 10);

        $this->assertSame([$message->id], array_map(fn ($c) => $c->sourceId, $candidates));
        $this->assertFalse($this->adapter()->facts($this->intakeFor($message))->eligible());
        $this->assertInstanceOf(EmployeeLogisticsThread::class, $this->storedThread($message));
    }
}
