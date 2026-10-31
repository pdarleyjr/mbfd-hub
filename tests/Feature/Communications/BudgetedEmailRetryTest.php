<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Jobs\SendBudgetedEmail;
use App\Models\CloudflareUsageBudget;
use App\Models\Employee;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Notifications\Channels\BudgetedMailChannel;
use App\Notifications\EquipmentDefectNotification;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Email handed to BudgetedMailChannel survives a block in the same way for every notification. */
final class BudgetedEmailRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.default', 'database');
        config()->set('communications.cloudflare.account_id', str_repeat('a', 32));
        config()->set('communications.cloudflare.api_token', 'budgeted-email-test-token');
        $now = CarbonImmutable::now();
        CloudflareUsageBudget::query()->create([
            'provider_account_id' => str_repeat('a', 32),
            'cycle_start' => $now->startOfMonth(),
            'cycle_end' => $now->addMonth()->startOfMonth(),
            'provider_chargeable_used' => 0,
            'provider_daily_quota' => 1000,
            'provider_daily_used' => 0,
            'hub_safe_ceiling' => 1000,
            'worker_request_threshold' => 9_000_000,
            'worker_cpu_ms_threshold' => 27_000_000,
            'reconciled_at' => $now,
            'provider_daily_reconciled_at' => $now,
            'worker_requests_used' => 0,
            'worker_cpu_ms_used' => 0,
        ]);
        Http::fake(fn (Request $request) => Http::response([
            'success' => true,
            'result' => ['message_id' => 'budgeted-email-test-message', 'delivered' => $request['to'], 'queued' => [], 'permanent_bounces' => []],
        ]));
    }

    public function test_a_blocked_notification_email_leaves_the_workers_fast_retries_and_is_delivered_after_the_block(): void
    {
        $this->closeBudget();

        Notification::route('mail', 'member@miamibeachfl.gov')->notify(new QueuedTestEmail);
        $this->work();

        self::assertDatabaseCount('failed_jobs', 0);
        self::assertSame('failed_pre_acceptance', OutboundEmail::query()->sole()->status);
        self::assertSame(SendBudgetedEmail::class, json_decode((string) DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR)['displayName']);
        Http::assertNothingSent();

        $this->travel(301)->seconds();
        $this->openBudget();
        $this->work();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['to'] === ['member@miamibeachfl.gov'] && $request['subject'] === 'Queued test email');
        self::assertDatabaseCount('jobs', 0);
        self::assertDatabaseCount('failed_jobs', 0);
    }

    public function test_the_equipment_defect_email_is_not_lost_when_sent_during_a_block(): void
    {
        $this->closeBudget();

        Notification::route('mail', 'admin@miamibeachfl.gov')->notify(new EquipmentDefectNotification('damaged', 'Saw', 'T-1', 'Engine 1', 'Member', '', 'REF-1'));

        self::assertDatabaseCount('jobs', 1);
        $this->travel(301)->seconds();
        $this->openBudget();
        $this->work();

        Http::assertSent(fn (Request $request): bool => $request['to'] === ['admin@miamibeachfl.gov'] && str_contains((string) $request['subject'], 'Equipment damaged'));
    }

    public function test_a_burst_above_the_hub_rate_limit_delivers_every_email(): void
    {
        foreach (range(1, 8) as $number) {
            Notification::route('mail', "member{$number}@miamibeachfl.gov")->notify(new QueuedTestEmail);
        }
        foreach (range(1, 8) as $ignored) {
            $this->work();
        }

        Http::assertSentCount(5);
        self::assertDatabaseCount('jobs', 3);

        $this->travel(62)->seconds();
        foreach (range(1, 3) as $ignored) {
            $this->work();
        }

        Http::assertSentCount(8);
        self::assertDatabaseCount('jobs', 0);
        self::assertDatabaseCount('failed_jobs', 0);
    }

    public function test_a_worker_retry_never_sends_a_second_copy_after_a_lost_response(): void
    {
        $submissions = 0;
        Http::swap(new Factory);
        Http::fake(function () use (&$submissions) {
            $submissions++;
            throw new ConnectionException('Provider response was lost.');
        });

        Notification::route('mail', 'member@miamibeachfl.gov')->notify(new QueuedTestEmail);
        $this->work();
        $this->work();
        $this->work();

        self::assertSame(1, $submissions);
        self::assertSame('acceptance_unknown', OutboundEmail::query()->sole()->status);
        self::assertDatabaseCount('jobs', 0);
    }

    #[DataProvider('temporaryBlocks')]
    public function test_every_temporary_block_waits_its_own_time_at_first_send_and_on_retry_then_delivers_once(string $block, int $seconds): void
    {
        $this->applyBlock($block, true);

        Notification::route('mail', 'member@miamibeachfl.gov')->notify(new QueuedTestEmail);
        $this->work();

        Http::assertNothingSent();
        self::assertGreaterThanOrEqual(now()->addSeconds($seconds - 1)->getTimestamp(), (int) DB::table('jobs')->sole()->available_at);

        // Still blocked when due: the job waits again for the time the block names.
        $this->travel($seconds + 1)->seconds();
        $this->applyBlock($block, true);
        $this->work();

        Http::assertNothingSent();
        self::assertGreaterThanOrEqual(now()->addSeconds($seconds - 1)->getTimestamp(), (int) DB::table('jobs')->sole()->available_at);

        $this->travel($seconds + 1)->seconds();
        $this->applyBlock($block, false);
        $this->work();

        Http::assertSentCount(1);
        self::assertDatabaseCount('jobs', 0);
        self::assertDatabaseCount('failed_jobs', 0);
    }

    /** @return array<string, array{string, int}> */
    public static function temporaryBlocks(): array
    {
        return [
            'per-minute limit' => ['minute', 61],
            'daily quota' => ['daily', 3600],
            'unreconciled budget' => ['unreconciled', 300],
            'provider backoff' => ['backoff', 601],
        ];
    }

    private function applyBlock(string $block, bool $on): void
    {
        $this->openBudget();
        config()->set('communications.cloudflare.max_recipient_units_per_minute', $block === 'minute' && $on ? 0 : 5);
        CloudflareUsageBudget::query()->update([
            'provider_daily_used' => $block === 'daily' && $on ? 1000 : 0,
            'provider_backoff_until' => $block === 'backoff' && $on ? now()->addSeconds(600) : null,
        ]);
        if ($block === 'unreconciled' && $on) {
            $this->closeBudget();
        }
    }

    public function test_a_block_that_waiting_will_not_clear_is_logged_once_and_not_retried(): void
    {
        config()->set('communications.cloudflare.safe_email_ceiling', 0);
        $logged = collect();
        Event::listen(MessageLogged::class, static function (MessageLogged $entry) use ($logged): void {
            $logged->push($entry);
        });

        Notification::route('mail', 'member@miamibeachfl.gov')->notify(new QueuedTestEmail);
        $this->work();

        Http::assertNothingSent();
        self::assertDatabaseCount('jobs', 0);
        self::assertSame('failed_pre_acceptance', OutboundEmail::query()->sole()->status);
        self::assertSame('warning', $logged->firstWhere('message', 'budgeted_email_not_sent')->level);
    }

    public function test_one_refusal_sends_exactly_one_email_end_to_end(): void
    {
        $attempts = 0;
        Http::swap(new Factory);
        Http::fake(function (Request $request) use (&$attempts) {
            $attempts++;

            return $attempts === 1
                ? Http::response(['success' => false], 429, ['Retry-After' => '60'])
                : Http::response(['success' => true, 'result' => ['message_id' => 'after-refusal', 'delivered' => $request['to'], 'queued' => [], 'permanent_bounces' => []]]);
        });
        $member = $this->member('one-refusal@miamibeachfl.gov');

        $member->notify(new QueuedTestEmail);
        $this->work();
        self::assertSame(1, $attempts);
        self::assertSame('failed_pre_acceptance', OutboundEmail::query()->sole()->status);
        self::assertDatabaseCount('jobs', 1);

        // Due now, and again if a worker dies after sending and the queue redelivers.
        $this->travel(61)->seconds();
        $this->work();
        $this->work();

        self::assertSame(2, $attempts, 'One refused request and one accepted request: never a third.');
        self::assertSame(1, OutboundEmail::query()->whereNotNull('accepted_at')->count());
        self::assertSame(['one-refusal@miamibeachfl.gov'], OutboundEmail::query()->whereNotNull('accepted_at')->sole()->to_recipients);
        self::assertDatabaseCount('jobs', 0);
        self::assertDatabaseCount('failed_jobs', 0);
    }

    public function test_a_waiting_email_is_stopped_if_the_member_no_longer_wants_it(): void
    {
        $this->closeBudget();
        $member = $this->member('stopped@miamibeachfl.gov');

        $member->notify(new QueuedTestEmail);
        $this->work();
        self::assertDatabaseCount('jobs', 1);

        config()->set('test.email_still_wanted', false);
        $this->travel(301)->seconds();
        $this->openBudget();
        $this->work();

        Http::assertNothingSent();
        self::assertDatabaseCount('jobs', 0);
        self::assertDatabaseCount('failed_jobs', 0);
    }

    public function test_a_waiting_email_is_dropped_if_the_member_was_deleted(): void
    {
        $this->closeBudget();
        $member = $this->member('deleted@miamibeachfl.gov');

        $member->notify(new QueuedTestEmail);
        $this->work();
        self::assertDatabaseCount('jobs', 1);

        // The app refuses to delete accounts, so this stands in for a row removed some other way.
        User::withoutEvents(fn () => $member->forceDelete());
        $this->travel(301)->seconds();
        $this->openBudget();
        $this->work();

        Http::assertNothingSent();
        self::assertDatabaseCount('jobs', 0);
        self::assertDatabaseCount('failed_jobs', 0);
    }

    public function test_a_member_without_a_city_email_is_skipped_without_any_retry(): void
    {
        $this->closeBudget();
        $member = User::factory()->create(['employee_profile_id' => null]);

        $member->notify(new QueuedTestEmail);
        $this->work();

        self::assertDatabaseCount('outbound_emails', 0);
        self::assertDatabaseCount('jobs', 0);
        self::assertDatabaseCount('failed_jobs', 0);
    }

    public function test_queued_jobs_over_the_send_rate_wait_without_adding_failed_rows_to_the_sent_list(): void
    {
        foreach (range(1, 6) as $number) {
            dispatch(new SendBudgetedEmail(["member{$number}@miamibeachfl.gov"], 'Subject', 'Body', null, 'budgeted_test', (string) $number));
        }
        foreach (range(1, 6) as $ignored) {
            $this->work();
        }

        Http::assertSentCount(5);
        self::assertDatabaseCount('outbound_emails', 5);
        self::assertDatabaseCount('jobs', 1);
    }

    public function test_a_job_that_fails_records_only_safe_fields_in_the_log(): void
    {
        $logged = collect();
        Event::listen(MessageLogged::class, static function (MessageLogged $entry) use ($logged): void {
            $logged->push($entry);
        });
        config()->set('communications.cloudflare.safe_email_ceiling', 0);

        dispatch(new SendBudgetedEmail(['private.member@miamibeachfl.gov'], 'Private subject', 'Private body', null, 'budgeted_test', '7'));
        $this->work();

        $entry = $logged->firstWhere('message', 'budgeted_email_abandoned');
        self::assertSame('error', $entry->level);
        self::assertSame(['source_type' => 'budgeted_test', 'source_id' => '7'], array_intersect_key($entry->context, ['source_type' => 1, 'source_id' => 1]));
        $text = (string) json_encode($logged->map(static fn (MessageLogged $item): array => [$item->message, $item->context])->all(), JSON_THROW_ON_ERROR);
        foreach (['private.member', 'Private subject', 'Private body'] as $private) {
            self::assertStringNotContainsString($private, $text);
        }
    }

    private function member(string $cityEmail): User
    {
        $employeeId = 'RETRY-'.bin2hex(random_bytes(4));
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Retry Test Member',
            'roster_status' => 'active',
            'city_email' => $cityEmail,
            'password' => 'not-used-in-this-test',
        ]);

        return User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employeeId, 'email' => $cityEmail]);
    }

    private function closeBudget(): void
    {
        CloudflareUsageBudget::query()->update(['reconciled_at' => null, 'provider_daily_reconciled_at' => null]);
    }

    private function openBudget(): void
    {
        CloudflareUsageBudget::query()->update(['reconciled_at' => now(), 'provider_daily_reconciled_at' => now()]);
    }

    /** Run one job the way the production worker does, with its --tries=3 default. */
    private function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'notifications', '--tries' => 3, '--sleep' => 0])
            ->assertSuccessful();
    }
}

final class QueuedTestEmail extends BaseNotification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [BudgetedMailChannel::class];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return (bool) config('test.email_still_wanted', true);
    }

    /** @return array{subject:string,text:string} */
    public function toBudgetedEmail(object $notifiable): array
    {
        return ['subject' => 'Queued test email', 'text' => 'Queued test body'];
    }
}
