<?php

declare(strict_types=1);

namespace Tests\Feature\Deliverability;

use App\Models\OutboundEmail;
use App\Models\OutboundEmailDeliveryEvent;
use App\Models\OutboundEmailReconciliationCheckpoint;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** mbfd:email-delivery-reconcile: the command, its exit codes, and its place in the schedule. */
final class DeliveryReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2026-10-30T12:00:00Z');
        $this->travelTo($this->now);
        config([
            'communications.delivery.zone_id' => str_repeat('b', 32),
            'communications.delivery.analytics_token' => 'isolated-read-credential',
            'communications.delivery.overlap_minutes' => 1440,
        ]);
        Http::preventStrayRequests();
    }

    public function test_it_is_scheduled_every_five_minutes_on_one_server_without_overlap_and_only_when_enabled(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(static fn ($event): bool => str_contains((string) $event->command, 'mbfd:email-delivery-reconcile'));

        self::assertNotNull($event);
        self::assertSame('*/5 * * * *', $event->expression);
        self::assertTrue($event->withoutOverlapping);
        self::assertSame(10, $event->expiresAt, 'a crashed run must not hold the lock for the default 24 hours');
        self::assertTrue($event->onOneServer);

        config(['communications.delivery.enabled' => false]);
        self::assertFalse($event->filtersPass($this->app), 'switched off by default: no analytics calls');
        config(['communications.delivery.enabled' => true]);
        self::assertTrue($event->filtersPass($this->app));
    }

    public function test_a_successful_run_records_events_prints_a_summary_and_advances_the_checkpoint(): void
    {
        $email = $this->email();
        Http::fake(fn (Request $request) => Http::response($this->graphql(
            CarbonImmutable::parse($request['variables']['end'])->gte($this->now->subMinute()) ? [$this->event()] : [],
        )));

        $this->artisan('mbfd:email-delivery-reconcile')
            ->expectsOutputToContain('Delivery events recorded: 1')
            ->assertExitCode(0);

        self::assertSame('delivered', $email->fresh()->status);
        self::assertTrue(OutboundEmailReconciliationCheckpoint::query()->sole()->completed_through->equalTo($this->now));
        self::assertNull(OutboundEmailReconciliationCheckpoint::query()->sole()->last_error);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.cloudflare.com/client/v4/graphql'
            && $request->hasHeader('Authorization', 'Bearer isolated-read-credential'));
    }

    public function test_running_twice_does_not_duplicate_events(): void
    {
        $this->email();
        Http::fake(fn (Request $request) => Http::response($this->graphql(
            CarbonImmutable::parse($request['variables']['end'])->gte($this->now->subMinute()) ? [$this->event()] : [],
        )));

        $this->artisan('mbfd:email-delivery-reconcile')->assertExitCode(0);
        $this->artisan('mbfd:email-delivery-reconcile')->expectsOutputToContain('Delivery events recorded: 0')->assertExitCode(0);

        self::assertSame(1, OutboundEmailDeliveryEvent::query()->count());
    }

    public function test_backfill_reads_the_retained_thirty_one_day_window(): void
    {
        Http::fake(fn () => Http::response($this->graphql([])));

        $this->artisan('mbfd:email-delivery-reconcile', ['--backfill' => true])->assertExitCode(0);

        Http::assertSent(fn (Request $request): bool => $request['variables']['start'] === $this->now->subDays(31)->addMinute()->toIso8601ZuluString());
        Http::assertSentCount(31);
    }

    /** @return array<string, array{string, string}> */
    public static function missingConfiguration(): array
    {
        return [
            'no zone' => ['communications.delivery.zone_id', ''],
            'malformed zone' => ['communications.delivery.zone_id', 'not-a-zone'],
            'no token' => ['communications.delivery.analytics_token', ''],
        ];
    }

    #[DataProvider('missingConfiguration')]
    public function test_missing_analytics_configuration_fails_without_any_request(string $key, string $value): void
    {
        config([$key => $value]);

        $this->artisan('mbfd:email-delivery-reconcile')
            ->expectsOutputToContain('requires the sending zone and a scoped Analytics Read token')
            ->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertDatabaseCount('outbound_email_reconciliation_checkpoints', 0);
    }

    public function test_a_provider_failure_exits_nonzero_keeps_the_checkpoint_and_leaks_nothing(): void
    {
        $checkpoint = OutboundEmailReconciliationCheckpoint::query()->create(['zone_id' => str_repeat('b', 32), 'completed_through' => $this->now->subHour()]);
        Http::fake(['api.cloudflare.com/client/v4/graphql' => Http::response(['errors' => [['message' => 'secret provider text with a token']]], 200)]);

        $this->artisan('mbfd:email-delivery-reconcile')
            ->expectsOutputToContain('checkpoint preserved')
            ->doesntExpectOutputToContain('secret provider text')
            ->assertExitCode(1);

        $checkpoint->refresh();
        self::assertTrue($checkpoint->completed_through->equalTo($this->now->subHour()));
        self::assertSame('Delivery analytics reconciliation failed; checkpoint preserved.', $checkpoint->last_error);
        self::assertTrue($checkpoint->attempted_at->equalTo($this->now));
    }

    /** @return array<string, array{int}> */
    public static function httpFailures(): array
    {
        return ['unauthorised' => [401], 'forbidden' => [403], 'throttled' => [429], 'server error' => [503]];
    }

    #[DataProvider('httpFailures')]
    public function test_http_failures_are_reported_the_same_safe_way(int $status): void
    {
        OutboundEmailReconciliationCheckpoint::query()->create(['zone_id' => str_repeat('b', 32), 'completed_through' => $this->now->subHour()]);
        Http::fake(['api.cloudflare.com/client/v4/graphql' => Http::response(['errors' => [['message' => 'secret provider text']]], $status)]);

        $this->artisan('mbfd:email-delivery-reconcile')->expectsOutputToContain('checkpoint preserved')->assertExitCode(1);

        self::assertTrue(OutboundEmailReconciliationCheckpoint::query()->sole()->completed_through->equalTo($this->now->subHour()));
    }

    public function test_a_second_run_while_one_is_active_is_refused(): void
    {
        $lock = \Illuminate\Support\Facades\Cache::lock('cloudflare-email-delivery:'.str_repeat('b', 32), 600);
        self::assertTrue($lock->get());
        Http::fake();

        $this->artisan('mbfd:email-delivery-reconcile')->expectsOutputToContain('already running')->assertExitCode(1);

        Http::assertNothingSent();
        $lock->release();
    }

    private function email(): OutboundEmail
    {
        return OutboundEmail::query()->forceCreate([
            'provider' => 'cloudflare', 'provider_message_id' => 'provider-id', 'source_type' => 'admin_compose',
            'from_address' => 'info@mbfdhub.com', 'to_recipients' => ['member@example.test'], 'subject' => 'Test',
            'recipient_count' => 1, 'chargeable_budget_units' => 1, 'status' => 'queued', 'accepted_at' => $this->now,
            'created_at' => $this->now->subHour(),
        ]);
    }

    /** @param list<array<string, mixed>> $events */
    private function graphql(array $events): array
    {
        return ['data' => ['viewer' => ['zones' => [['emailSendingAdaptive' => $events]]]]];
    }

    /** @param array<string, mixed> $overrides */
    private function event(array $overrides = []): array
    {
        return array_merge([
            'messageId' => 'provider-id', 'envelopeTo' => 'member@example.test', 'status' => 'delivered',
            'eventType' => 'newEmail', 'datetime' => $this->now->subMinute()->toIso8601ZuluString(), 'sendingDomain' => 'mbfdhub.com',
        ], $overrides);
    }
}
