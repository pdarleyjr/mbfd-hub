<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Jobs\AiAutomation\ProcessAiBacklogBatch;
use App\Jobs\AiAutomation\ProcessAiIntake;
use App\Models\AiAutomation\AiAutomationSwitchEvent;
use App\Models\AiAutomation\AiBacklogRun;
use App\Models\AiAutomation\AiIntakeEvent;
use App\Models\User;
use App\Services\AiAutomation\AiAutomationSwitch;
use App\Services\AiAutomation\AiIntake;
use App\Services\AiAutomation\BacklogService;
use App\Services\AiAutomation\IntakeFacts;
use App\Services\AiAutomation\IntakeProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AiAutomation\Fakes\FakeDomainAdapter;
use Tests\TestCase;

final class AiAutomationEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeDomainAdapter::reset();
        config(['ai_automation.adapters' => [FakeDomainAdapter::class]]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        FakeDomainAdapter::reset();
        parent::tearDown();
    }

    public function test_the_switch_starts_off_and_recording_does_nothing_while_off(): void
    {
        Queue::fake();

        $this->assertFalse(app(AiAutomationSwitch::class)->isOn());
        AiIntake::record('fake_message', 1);

        $this->assertSame(0, AiIntakeEvent::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_an_unknown_source_type_is_never_recorded(): void
    {
        Queue::fake();
        $this->turnOn();

        AiIntake::record('ems_inventory_request', 7);
        AiIntake::record('daily_roster', 7);

        $this->assertSame(0, AiIntakeEvent::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_recording_while_on_creates_one_ledger_row_and_one_job_even_on_replay(): void
    {
        Queue::fake();
        $this->turnOn();

        AiIntake::record('fake_message', 5);
        AiIntake::record('fake_message', 5);

        $this->assertSame(1, AiIntakeEvent::query()->count());
        Queue::assertPushed(ProcessAiIntake::class, 1);
        Queue::assertPushedOn('ai-automation', ProcessAiIntake::class);
    }

    public function test_recording_waits_for_the_domain_transaction_to_commit(): void
    {
        Queue::fake();
        $this->turnOn();

        try {
            DB::transaction(function (): void {
                AiIntake::record('fake_message', 9);

                throw new \RuntimeException('member save failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, AiIntakeEvent::query()->count());
    }

    public function test_a_live_reply_is_written_once_with_an_in_app_notification_and_no_email(): void
    {
        $member = User::factory()->create(['account_status' => \App\Enums\AccountStatus::Active]);
        FakeDomainAdapter::$requesterUserId = $member->id;
        $this->turnOn();

        AiIntake::record('fake_message', 11); // sync queue in tests runs the job now

        $intake = AiIntakeEvent::query()->sole();
        $this->assertSame(AiIntakeEvent::STATE_SENT, $intake->state);
        $this->assertSame('Acknowledged.', $intake->reply_text);
        $this->assertSame('receipt', $intake->template_key);
        $this->assertCount(1, FakeDomainAdapter::$writes);

        // Running it again is a no-op.
        app(IntakeProcessor::class)->process($intake->id);
        $this->assertCount(1, FakeDomainAdapter::$writes);

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $member->id)->count());
        $payload = json_decode((string) DB::table('notifications')->value('data'), true);
        $this->assertSame('Support Services', $payload['title']);
        $this->assertSame('Acknowledged.', $payload['body']);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_off_before_the_job_runs_cancels_it_and_on_again_does_not_revive_it(): void
    {
        Queue::fake();
        $this->turnOn();
        AiIntake::record('fake_message', 21);
        $intake = AiIntakeEvent::query()->sole();

        $result = app(AiAutomationSwitch::class)->turnOff(null, 'console');
        $this->assertSame(1, $result['suppressed']);

        $this->turnOn();
        app(IntakeProcessor::class)->process($intake->id);

        $this->assertSame(AiIntakeEvent::STATE_CANCELLED, $intake->refresh()->state);
        $this->assertSame([], FakeDomainAdapter::$writes);
    }

    public function test_a_job_queued_in_an_older_epoch_cannot_post_after_off_then_on(): void
    {
        Queue::fake();
        $this->turnOn();
        AiIntake::record('fake_message', 22);
        $intake = AiIntakeEvent::query()->sole();

        // Simulate a row that escaped the OFF sweep (e.g. claimed by a worker at that instant).
        app(AiAutomationSwitch::class)->turnOff(null);
        $this->turnOn();
        $intake->forceFill(['state' => AiIntakeEvent::STATE_QUEUED])->save();

        app(IntakeProcessor::class)->process($intake->id);

        $this->assertSame(AiIntakeEvent::STATE_CANCELLED, $intake->refresh()->state);
        $this->assertSame([], FakeDomainAdapter::$writes);
    }

    public function test_off_in_the_middle_of_processing_prevents_the_write(): void
    {
        Queue::fake();
        $this->turnOn();
        AiIntake::record('fake_message', 23);
        $intake = AiIntakeEvent::query()->sole();

        // OFF lands after the reply was composed but before the locked write.
        $calls = 0;
        FakeDomainAdapter::$onFacts = function () use (&$calls): void {
            if (++$calls === 1) {
                app(AiAutomationSwitch::class)->turnOff(null);
            }
        };

        app(IntakeProcessor::class)->process($intake->id);

        $this->assertSame(AiIntakeEvent::STATE_CANCELLED, $intake->refresh()->state);
        $this->assertSame([], FakeDomainAdapter::$writes);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_hard_disable_on_the_server_beats_the_admin_switch(): void
    {
        Queue::fake();
        $this->turnOn();
        config(['ai_automation.hard_disabled' => true]);

        AiIntake::record('fake_message', 24);

        $this->assertFalse(app(AiAutomationSwitch::class)->isOn());
        $this->assertSame(0, AiIntakeEvent::query()->count());
    }

    public function test_already_answered_and_ineligible_submissions_are_skipped_without_a_write(): void
    {
        Queue::fake();
        $this->turnOn();
        FakeDomainAdapter::$facts[31] = new IntakeFacts('support', 'support_message', null, [], 'hi', null, true);
        FakeDomainAdapter::$facts[32] = new IntakeFacts('support', 'support_message', null, [], 'hi', null, false, 'archived');
        AiIntake::record('fake_message', 31);
        AiIntake::record('fake_message', 32);

        foreach (AiIntakeEvent::query()->get() as $intake) {
            app(IntakeProcessor::class)->process($intake->id);
        }

        $states = AiIntakeEvent::query()->orderBy('source_id')->pluck('reason_codes', 'source_id')->all();
        $this->assertSame(['already_answered'], $states[31]);
        $this->assertSame(['archived'], $states[32]);
        $this->assertSame([], FakeDomainAdapter::$writes);
    }

    public function test_a_failed_write_retries_and_then_gives_up_without_duplicates(): void
    {
        Queue::fake();
        $this->turnOn();
        config(['ai_automation.processing.retry_delay_seconds' => 0]);
        FakeDomainAdapter::$failWrites = 5;
        AiIntake::record('fake_message', 41);
        $intake = AiIntakeEvent::query()->sole();

        $processor = app(IntakeProcessor::class);
        $this->assertSame(AiIntakeEvent::STATE_RETRY, $processor->process($intake->id));
        $this->assertSame(AiIntakeEvent::STATE_RETRY, $processor->process($intake->id));
        $this->assertSame(AiIntakeEvent::STATE_FAILED, $processor->process($intake->id));
        $this->assertSame(AiIntakeEvent::STATE_FAILED, $processor->process($intake->id));
        $this->assertSame([], FakeDomainAdapter::$writes);
        $this->assertSame('RuntimeException', $intake->refresh()->last_error);
    }

    public function test_switch_changes_are_audited_with_the_actor(): void
    {
        $admin = User::factory()->create(['name' => 'Chief Example']);
        $switch = app(AiAutomationSwitch::class);

        $switch->turnOn($admin);
        $switch->turnOn($admin); // no change, no second event
        $switch->turnOff($admin);

        $events = AiAutomationSwitchEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertTrue($events[0]->to_enabled);
        $this->assertFalse($events[1]->to_enabled);
        $this->assertSame('Chief Example', $events[1]->actor_name);
        $this->assertSame(3, $switch->epoch());
    }

    public function test_console_switch_turns_everything_off(): void
    {
        $this->turnOn();

        Artisan::call('ai-automation:switch', ['state' => 'off']);

        $this->assertFalse(app(AiAutomationSwitch::class)->isOn());
        $this->assertSame('console', AiAutomationSwitchEvent::query()->latest('id')->value('source'));
    }

    public function test_sweeper_does_nothing_while_off_and_requeues_retries_while_on(): void
    {
        Queue::fake();
        $this->turnOn();
        AiIntake::record('fake_message', 51);
        Queue::assertPushed(ProcessAiIntake::class, 1);
        AiIntakeEvent::query()->update(['state' => AiIntakeEvent::STATE_RETRY, 'next_attempt_at' => now()->subMinute()]);

        Artisan::call('ai-automation:sweep');
        Queue::assertPushed(ProcessAiIntake::class, 2);

        app(AiAutomationSwitch::class)->turnOff(null);
        Artisan::call('ai-automation:sweep');
        Queue::assertPushed(ProcessAiIntake::class, 2);
    }

    public function test_backlog_preview_writes_nothing_and_counts_what_would_be_sent(): void
    {
        FakeDomainAdapter::$backlog = [101, 102, 103];
        FakeDomainAdapter::$facts[103] = new IntakeFacts('support', 'support_message', null, [], 'x', null, false, 'archived');

        $run = app(BacklogService::class)->preview(null);

        $this->assertSame(AiBacklogRun::STATE_PREVIEWED, $run->state);
        $this->assertSame(3, $run->preview['candidates']);
        $this->assertSame(2, $run->preview['would_send']);
        $this->assertSame(['archived' => 1], $run->preview['excluded_reasons']);
        $this->assertSame(['Acknowledged.' => 2], $run->preview['replies']);
        $this->assertSame(0, AiIntakeEvent::query()->count());
        $this->assertSame([], FakeDomainAdapter::$writes);
    }

    public function test_backlog_needs_the_switch_on_and_processes_in_bounded_batches_without_notifications(): void
    {
        Queue::fake();
        $member = User::factory()->create();
        FakeDomainAdapter::$requesterUserId = $member->id;
        FakeDomainAdapter::$backlog = range(201, 225);
        config(['ai_automation.backlog.batch_size' => 10]);
        $backlog = app(BacklogService::class);
        $run = $backlog->preview(null);

        $this->assertFalse($backlog->start($run), 'backlog must not start while OFF');

        $this->turnOn();
        $this->assertTrue($backlog->start($run->refresh()));
        Queue::assertPushedOn('ai-backlog', ProcessAiBacklogBatch::class);

        $epoch = app(AiAutomationSwitch::class)->epoch();
        $this->assertTrue($backlog->processBatch($run->id, $epoch));
        $this->assertCount(10, FakeDomainAdapter::$writes);
        $this->assertTrue($backlog->processBatch($run->id, $epoch));
        $this->assertFalse($backlog->processBatch($run->id, $epoch));
        $this->assertCount(25, FakeDomainAdapter::$writes);

        $run->refresh();
        $this->assertSame(AiBacklogRun::STATE_COMPLETED, $run->state);
        $this->assertSame(25, $run->sent_count);
        $this->assertSame(25, AiIntakeEvent::query()->where('origin', 'backlog')->where('state', 'sent')->count());
        $this->assertSame(0, DB::table('notifications')->count(), 'historical replies never ping members');
        Mail::assertNothingSent();
    }

    public function test_off_pauses_the_backlog_and_on_alone_does_not_resume_it(): void
    {
        Queue::fake();
        FakeDomainAdapter::$backlog = range(301, 330);
        config(['ai_automation.backlog.batch_size' => 10]);
        $this->turnOn();
        $backlog = app(BacklogService::class);
        $run = $backlog->preview(null);
        $backlog->start($run);
        $epoch = app(AiAutomationSwitch::class)->epoch();
        $backlog->processBatch($run->id, $epoch);

        app(AiAutomationSwitch::class)->turnOff(null);
        $this->assertSame(AiBacklogRun::STATE_PAUSED, $run->refresh()->state);
        $this->assertFalse($backlog->processBatch($run->id, $epoch));

        $this->turnOn();
        $this->assertFalse($backlog->processBatch($run->id, $epoch), 'the old epoch job stays inert');
        $this->assertFalse($backlog->processBatch($run->id, app(AiAutomationSwitch::class)->epoch()), 'paused runs wait for the button');
        $this->assertCount(10, FakeDomainAdapter::$writes);

        // The explicit button resumes from the cursor with no duplicates.
        $this->assertTrue($backlog->start($run->refresh()));
        $newEpoch = app(AiAutomationSwitch::class)->epoch();
        while ($backlog->processBatch($run->id, $newEpoch)) {
        }
        $this->assertCount(30, FakeDomainAdapter::$writes);
        $this->assertCount(30, array_unique(array_column(FakeDomainAdapter::$writes, 'source_id')));
    }

    public function test_the_admin_preview_is_queued_and_replaces_an_unstarted_preview(): void
    {
        Queue::fake();
        FakeDomainAdapter::$backlog = [501, 502];
        $backlog = app(BacklogService::class);
        $first = $backlog->preview(null);

        $run = $backlog->requestPreview(null);

        $this->assertSame(AiBacklogRun::STATE_PREVIEWING, $run->state);
        $this->assertSame(AiBacklogRun::STATE_CANCELLED, $first->refresh()->state);
        Queue::assertPushedOn('ai-backlog', \App\Jobs\AiAutomation\PreviewAiBacklog::class);
        $this->assertFalse($backlog->start($run), 'a preview still counting cannot be started');

        $backlog->fillPreview($run);
        $this->assertSame(2, $run->refresh()->preview['would_send']);
        $this->assertSame([], FakeDomainAdapter::$writes);
    }

    public function test_the_optional_age_limit_excludes_old_backlog_items(): void
    {
        config(['ai_automation.backlog.max_age_days' => 30]);
        FakeDomainAdapter::$backlog = [601, 602];
        FakeDomainAdapter::$facts[601] = new IntakeFacts('support', 'support_message', null, [], 'x', submittedAt: now()->subDays(45));
        FakeDomainAdapter::$facts[602] = new IntakeFacts('support', 'support_message', null, [], 'x', submittedAt: now()->subDays(5));

        $run = app(BacklogService::class)->preview(null);

        $this->assertSame(1, $run->preview['would_send']);
        $this->assertSame(['older_than_backlog_limit' => 1], $run->preview['excluded_reasons']);
    }

    public function test_no_bell_is_sent_when_the_switch_went_off_after_the_reply(): void
    {
        $member = User::factory()->create(['account_status' => \App\Enums\AccountStatus::Active]);
        $this->turnOn();
        $intake = AiIntakeEvent::query()->create([
            'domain' => 'support', 'source_type' => 'fake_message', 'source_id' => 701, 'event_key' => 'received',
            'origin' => 'live', 'epoch' => app(AiAutomationSwitch::class)->epoch(), 'state' => 'sent', 'reply_text' => 'Acknowledged.',
        ]);
        $facts = new IntakeFacts('support', 'support_message', $member->id, [], 'x');
        Queue::fake();

        app(AiAutomationSwitch::class)->turnOff(null);
        app(\App\Services\AiAutomation\AiReplyNotifier::class)->notify($intake, new FakeDomainAdapter, $facts);

        Queue::assertNotPushed(\App\Jobs\FanOutNotification::class);
    }

    public function test_backlog_never_answers_a_submission_the_live_path_already_handled(): void
    {
        Queue::fake();
        $this->turnOn();
        AiIntake::record('fake_message', 401);
        app(IntakeProcessor::class)->process(AiIntakeEvent::query()->sole()->id);
        FakeDomainAdapter::$backlog = [401, 402];

        $backlog = app(BacklogService::class);
        $run = $backlog->preview(null);
        $this->assertSame(1, $run->preview['already_in_ledger']);
        $backlog->start($run);
        $backlog->processBatch($run->id, app(AiAutomationSwitch::class)->epoch());

        $this->assertSame([401, 402], array_column(FakeDomainAdapter::$writes, 'source_id'));
    }

    private function turnOn(): void
    {
        app(AiAutomationSwitch::class)->turnOn(null, 'console');
    }
}
