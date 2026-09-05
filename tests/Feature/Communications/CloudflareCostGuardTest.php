<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Exceptions\EmailBudgetExhausted;
use App\Models\CloudflareUsageBudget;
use App\Models\OutboundEmail;
use App\Services\Communications\CloudflareCostGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CloudflareCostGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservations_cannot_cross_the_safe_ceiling(): void
    {
        config()->set('communications.cloudflare.safe_email_ceiling', 3);
        $cycleStart = CarbonImmutable::parse('2026-09-01T00:00:00Z');
        $cycleEnd = CarbonImmutable::parse('2026-10-01T00:00:00Z');
        CloudflareUsageBudget::query()->create([
            'cycle_start' => $cycleStart,
            'cycle_end' => $cycleEnd,
            'provider_chargeable_used' => 1,
            'provider_daily_quota' => 100,
            'provider_daily_used' => 0,
            'hub_safe_ceiling' => 3,
            'worker_request_threshold' => 9000000,
            'worker_cpu_ms_threshold' => 27000000,
            'worker_requests_used' => 0,
            'worker_cpu_ms_used' => 0,
            'reconciled_at' => $cycleStart->addDay(),
            'provider_daily_reconciled_at' => $cycleStart->addDay(),
        ]);

        $first = OutboundEmail::query()->create([
            'provider' => 'cloudflare',
            'source_type' => 'test',
            'from_address' => 'info@mbfdhub.com',
            'to_recipients' => ['one@example.test', 'two@example.test'],
            'subject' => 'Cost guard test',
            'recipient_count' => 2,
            'chargeable_budget_units' => 2,
            'status' => 'pending',
        ]);
        app(CloudflareCostGuard::class)->reserve($first, $cycleStart->addDay());
        self::assertSame('reserved', $first->fresh()->status);

        $blocked = OutboundEmail::query()->create([
            'provider' => 'cloudflare',
            'source_type' => 'test',
            'from_address' => 'info@mbfdhub.com',
            'to_recipients' => ['three@example.test'],
            'subject' => 'Blocked test',
            'recipient_count' => 1,
            'chargeable_budget_units' => 1,
            'status' => 'pending',
        ]);

        $this->expectException(EmailBudgetExhausted::class);
        app(CloudflareCostGuard::class)->reserve($blocked, $cycleStart->addDay());
    }

    public function test_pre_acceptance_failure_releases_but_provider_acceptance_remains_counted(): void
    {
        $now = CarbonImmutable::parse('2026-09-04T12:00:00Z');
        CloudflareUsageBudget::query()->create([
            'cycle_start' => $now->startOfMonth(),
            'cycle_end' => $now->addMonth()->startOfMonth(),
            'provider_chargeable_used' => 0,
            'provider_daily_quota' => 100,
            'provider_daily_used' => 0,
            'hub_safe_ceiling' => 2850,
            'worker_request_threshold' => 9000000,
            'worker_cpu_ms_threshold' => 27000000,
            'reconciled_at' => $now,
            'provider_daily_reconciled_at' => $now,
            'worker_requests_used' => 0,
            'worker_cpu_ms_used' => 0,
        ]);
        $email = OutboundEmail::query()->create([
            'provider' => 'cloudflare',
            'source_type' => 'test',
            'from_address' => 'info@mbfdhub.com',
            'to_recipients' => ['one@example.test'],
            'subject' => 'Lifecycle test',
            'recipient_count' => 1,
            'chargeable_budget_units' => 1,
            'status' => 'pending',
        ]);
        $guard = app(CloudflareCostGuard::class);
        $guard->reserve($email, $now);
        $guard->releaseBeforeAcceptance($email, 'provider rejected', $now);
        self::assertNotNull($email->fresh()->budget_released_at);
        self::assertSame(0, $guard->localReservedOrAcceptedUnits($now));

        $accepted = $email->replicate(['status', 'budget_reserved_at', 'budget_released_at']);
        $accepted->status = 'pending';
        $accepted->save();
        $guard->reserve($accepted, $now);
        $guard->markAccepted($accepted, 'provider-message-id', $now);
        self::assertSame(1, $guard->localReservedOrAcceptedUnits($now));
    }

    public function test_dispatcher_uses_structured_api_and_records_provider_delivery_without_exposing_token(): void
    {
        $now = CarbonImmutable::parse('2026-09-04T12:00:00Z');
        CarbonImmutable::setTestNow($now);
        config()->set('communications.cloudflare.api_token', 'test-token-not-persisted');
        config()->set('communications.cloudflare.account_id', str_repeat('a', 32));
        CloudflareUsageBudget::query()->create([
            'cycle_start' => $now->startOfMonth(),
            'cycle_end' => $now->addMonth()->startOfMonth(),
            'provider_chargeable_used' => 0,
            'provider_daily_quota' => 100,
            'provider_daily_used' => 0,
            'hub_safe_ceiling' => 2850,
            'worker_request_threshold' => 9000000,
            'worker_cpu_ms_threshold' => 27000000,
            'reconciled_at' => $now,
            'provider_daily_reconciled_at' => $now,
            'worker_requests_used' => 0,
            'worker_cpu_ms_used' => 0,
        ]);
        Http::fake([
            'https://api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => [
                    'message_id' => 'cloudflare-message-id',
                    'delivered' => ['recipient@example.test'],
                    'queued' => [],
                    'permanent_bounces' => [],
                    'suppressed_recipients' => [],
                ],
            ]),
        ]);

        $email = app(\App\Services\Communications\CloudflareEmailDispatcher::class)->send(
            to: ['recipient@example.test'],
            subject: 'Dispatcher contract',
            text: 'Safe body',
            html: null,
            sourceType: 'test',
            attachments: [[
                'filename' => 'operations.txt',
                'type' => 'text/plain',
                'content' => base64_encode('safe attachment'),
            ]],
        );

        self::assertSame('delivered', $email->status);
        self::assertSame('cloudflare-message-id', $email->provider_message_id);
        self::assertSame([[
            'filename' => 'operations.txt',
            'type' => 'text/plain',
            'size' => 15,
        ]], $email->attachment_metadata);
        self::assertStringNotContainsString('test-token-not-persisted', json_encode($email->toArray(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(base64_encode('safe attachment'), json_encode($email->toArray(), JSON_THROW_ON_ERROR));
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-token-not-persisted')
            && $request['from'] === 'info@mbfdhub.com'
            && $request['attachments'][0]['disposition'] === 'attachment'
            && $request['attachments'][0]['content'] === base64_encode('safe attachment'));
        CarbonImmutable::setTestNow();
    }

    public function test_oversized_attachment_is_rejected_before_ledger_or_provider_request(): void
    {
        config()->set('communications.cloudflare.api_token', 'test-token');
        config()->set('communications.cloudflare.account_id', str_repeat('a', 32));
        config()->set('communications.cloudflare.max_attachment_bytes', 2);
        Http::fake();

        try {
            app(\App\Services\Communications\CloudflareEmailDispatcher::class)->send(
                to: ['recipient@example.test'],
                subject: 'Oversized attachment',
                text: 'Safe body',
                html: null,
                sourceType: 'test',
                attachments: [[
                    'filename' => 'too-large.txt',
                    'type' => 'text/plain',
                    'content' => base64_encode('three bytes'),
                ]],
            );
            self::fail('The oversized attachment should have been rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('The outbound attachment limit was exceeded.', $exception->getMessage());
        }

        $this->assertDatabaseCount('outbound_emails', 0);
        Http::assertNothingSent();
    }

    public function test_stale_provider_reconciliation_blocks_before_any_request(): void
    {
        $now = CarbonImmutable::parse('2026-09-04T12:00:00Z');
        config()->set('communications.cloudflare.max_reconciliation_age_seconds', 60);
        $this->createBudget($now, [
            'reconciled_at' => $now->subSeconds(61),
            'provider_daily_reconciled_at' => $now,
        ]);
        Http::fake();

        $this->expectException(EmailBudgetExhausted::class);
        app(CloudflareCostGuard::class)->reserve($this->pendingEmail(), $now);
        Http::assertNothingSent();
    }

    /** @param array<string, int|null> $overrides */
    #[DataProvider('providerThresholdProvider')]
    public function test_daily_and_worker_provider_thresholds_fail_closed(array $overrides): void
    {
        $now = CarbonImmutable::parse('2026-09-04T12:00:00Z');
        $this->createBudget($now, $overrides);

        $this->expectException(EmailBudgetExhausted::class);
        app(CloudflareCostGuard::class)->reserve($this->pendingEmail(), $now);
    }

    /** @return array<string, array{array<string, int|null>}> */
    public static function providerThresholdProvider(): array
    {
        return [
            'missing daily quota' => [['provider_daily_quota' => null]],
            'daily quota reached' => [['provider_daily_quota' => 5, 'provider_daily_used' => 5]],
            'worker requests reached' => [['worker_requests_used' => 9_000_000]],
            'worker cpu reached' => [['worker_cpu_ms_used' => 27_000_000]],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function createBudget(CarbonImmutable $now, array $overrides = []): CloudflareUsageBudget
    {
        return CloudflareUsageBudget::query()->create(array_merge([
            'cycle_start' => $now->startOfMonth(),
            'cycle_end' => $now->addMonth()->startOfMonth(),
            'provider_chargeable_used' => 0,
            'provider_daily_quota' => 100,
            'provider_daily_used' => 0,
            'hub_safe_ceiling' => 2850,
            'worker_request_threshold' => 9_000_000,
            'worker_cpu_ms_threshold' => 27_000_000,
            'reconciled_at' => $now,
            'provider_daily_reconciled_at' => $now,
            'worker_requests_used' => 0,
            'worker_cpu_ms_used' => 0,
        ], $overrides));
    }

    private function pendingEmail(): OutboundEmail
    {
        return OutboundEmail::query()->create([
            'provider' => 'cloudflare',
            'source_type' => 'test',
            'from_address' => 'info@mbfdhub.com',
            'to_recipients' => ['one@example.test'],
            'subject' => 'Guard test',
            'recipient_count' => 1,
            'chargeable_budget_units' => 1,
            'status' => 'pending',
        ]);
    }
}
