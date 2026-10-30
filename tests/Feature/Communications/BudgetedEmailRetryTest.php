<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Jobs\SendBudgetedEmail;
use App\Models\CloudflareUsageBudget;
use App\Models\OutboundEmail;
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

        Http::assertSent(fn (Request $request): bool => $request['to'] === ['admin@miamibeachfl.gov'] && str_contains((string) $request['subject'], 'Equipment DAMAGED'));
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

    public function test_a_block_that_waiting_will_not_clear_is_not_retried_forever(): void
    {
        config()->set('communications.cloudflare.safe_email_ceiling', 0);

        Notification::route('mail', 'member@miamibeachfl.gov')->notify(new QueuedTestEmail);
        $this->work();
        $this->work();
        $this->work();

        Http::assertNothingSent();
        self::assertDatabaseCount('jobs', 0);
        self::assertDatabaseCount('failed_jobs', 1);
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

    /** @return array{subject:string,text:string} */
    public function toBudgetedEmail(object $notifiable): array
    {
        return ['subject' => 'Queued test email', 'text' => 'Queued test body'];
    }
}
