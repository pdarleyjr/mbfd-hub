<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Exceptions\EmailBudgetExhausted;
use App\Models\CloudflareUsageBudget;
use App\Models\OutboundEmail;
use App\Services\Communications\CloudflareCostGuard;
use App\Services\Communications\CloudflareEmailDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CloudflareHardLimitTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2026-09-08T12:00:00Z');
        $this->travelTo($this->now);
        config()->set('communications.cloudflare.api_token', 'isolated-test-token');
        config()->set('communications.cloudflare.account_id', str_repeat('a', 32));
        Http::preventStrayRequests();
    }

    public function test_monthly_ceiling_cannot_be_raised_above_2850_by_configuration(): void
    {
        config()->set('communications.cloudflare.safe_email_ceiling', 9000);
        $this->budget(['hub_safe_ceiling' => 9000, 'provider_chargeable_used' => 2850]);
        $this->assertBlocked($this->email());
    }

    public function test_ambiguous_overlapping_billing_cycles_fail_closed(): void
    {
        $this->budget();
        $this->budget(['cycle_start' => $this->now->subDay(), 'cycle_end' => $this->now->addMonth()]);
        $this->assertBlocked($this->email());
    }

    public function test_future_provider_reconciliation_timestamp_is_not_treated_as_fresh(): void
    {
        $this->budget(['reconciled_at' => $this->now->addHour()]);
        $this->assertBlocked($this->email());
    }

    public function test_negative_provider_usage_cannot_create_extra_capacity(): void
    {
        $this->budget(['provider_chargeable_used' => -10]);
        $this->assertBlocked($this->email());
    }

    public function test_a_snapshot_from_another_provider_account_cannot_authorize_sending(): void
    {
        $this->budget(['provider_account_id' => str_repeat('b', 32)]);
        $this->assertBlocked($this->email());
    }

    public function test_unbound_legacy_snapshot_cannot_authorize_sending(): void
    {
        $this->budget(['provider_account_id' => null]);
        $this->assertBlocked($this->email());
    }

    public function test_manual_reconciliation_rejects_non_numeric_or_negative_usage_without_writing(): void
    {
        foreach (['not-a-number', '-1'] as $usage) {
            $this->artisan('mbfd:cloudflare-usage-reconcile', [
                'cycle-start' => '2026-09-01T00:00:00Z', 'cycle-end' => '2026-10-01T00:00:00Z',
                'provider-chargeable' => $usage, '--provider-daily-quota' => '1000',
                '--provider-daily-used' => '0', '--worker-requests' => '0', '--worker-cpu-ms' => '0',
            ])->assertFailed();
        }
        self::assertSame(0, CloudflareUsageBudget::query()->count());
    }

    public function test_reconciliation_at_quota_closes_a_previously_open_budget(): void
    {
        $this->budget();
        $this->artisan('mbfd:cloudflare-usage-reconcile', [
            'cycle-start' => '2026-09-01T00:00:00Z', 'cycle-end' => '2026-10-01T00:00:00Z',
            'provider-chargeable' => '0', '--provider-daily-quota' => '1',
            '--provider-daily-used' => '1', '--worker-requests' => '0', '--worker-cpu-ms' => '0',
        ])->assertFailed();
        $this->assertBlocked($this->email());
    }

    #[DataProvider('retryAfterHeaders')]
    public function test_provider_retry_after_blocks_subsequent_requests_without_automatic_retry(string $format): void
    {
        $this->budget();
        $attempts = 0;
        $header = $format === 'date' ? $this->now->addSeconds(120)->format(DATE_RFC7231) : '120';
        Http::fake(function () use (&$attempts, $header) {
            $attempts++;

            return $attempts === 1
                ? Http::response(['success' => false], 429, ['Retry-After' => $header])
                : Http::response(['success' => true, 'result' => ['message_id' => 'test-later-message', 'queued' => ['recipient@example.test']]]);
        });
        try {
            $this->send();
            self::fail('Rate limiting must be surfaced.');
        } catch (\Illuminate\Http\Client\RequestException) {
            self::assertSame(1, $attempts);
        }
        try {
            $this->send();
            self::fail('The provider backoff must block a subsequent request.');
        } catch (EmailBudgetExhausted) {
            self::assertSame(1, $attempts);
        }
        $this->travel(121)->seconds();
        self::assertSame('queued', $this->send()->status);
        self::assertSame(2, $attempts);
    }

    /** @return array<string, array{string}> */
    public static function retryAfterHeaders(): array
    {
        return ['delay seconds' => ['seconds'], 'HTTP date' => ['date']];
    }

    public function test_provider_backoff_survives_a_billing_cycle_boundary(): void
    {
        $this->budget();
        $this->budget([
            'cycle_start' => $this->now->startOfMonth()->subMonth(),
            'cycle_end' => $this->now->startOfMonth(),
            'provider_backoff_until' => $this->now->addMinutes(2),
        ]);
        $this->assertBlocked($this->email());
    }

    public function test_out_of_order_provider_responses_cannot_shorten_existing_backoff(): void
    {
        $this->budget();
        $guard = app(CloudflareCostGuard::class);
        $guard->deferUntil($this->now->addMinutes(2));
        $guard->deferUntil($this->now->addSeconds(30));
        self::assertTrue(CloudflareUsageBudget::query()->sole()->provider_backoff_until->equalTo($this->now->addMinutes(2)));
    }

    public function test_refresh_does_not_forget_accepted_units_earlier_in_the_billing_cycle(): void
    {
        $this->budget(['provider_chargeable_used' => 2849]);
        $this->email(['budget_reserved_at' => $this->now->subDay(), 'accepted_at' => $this->now->subDay(), 'status' => 'accepted']);
        $this->assertBlocked($this->email());
    }

    public function test_daily_refresh_does_not_forget_earlier_accepted_units_today(): void
    {
        $this->budget(['provider_daily_quota' => 1]);
        $this->email(['budget_reserved_at' => $this->now->subHour(), 'accepted_at' => $this->now->subHour(), 'status' => 'accepted']);
        $this->assertBlocked($this->email());
    }

    public function test_unknown_prior_cycle_attempts_are_carried_into_the_new_cycle(): void
    {
        $this->budget(['provider_chargeable_used' => 2849]);
        $this->email(['budget_reserved_at' => $this->now->subMonth(), 'submitted_at' => $this->now->subMonth(), 'status' => 'acceptance_unknown']);
        $this->assertBlocked($this->email());
    }

    public function test_reservation_crossing_midnight_remains_counted_in_the_new_day(): void
    {
        $this->budget(['provider_daily_quota' => 1]);
        $this->email(['budget_reserved_at' => $this->now->subDay(), 'status' => 'reserved']);
        $this->assertBlocked($this->email());
    }

    public function test_late_acceptance_counts_in_the_period_of_acceptance(): void
    {
        $this->budget(['provider_daily_quota' => 1]);
        $this->email(['budget_reserved_at' => $this->now->subDay(), 'accepted_at' => $this->now->subHour(), 'status' => 'accepted']);
        $this->assertBlocked($this->email());
    }

    public function test_definitively_accepted_previous_cycle_traffic_does_not_consume_new_cycle(): void
    {
        $this->budget(['provider_chargeable_used' => 2849]);
        $this->email(['budget_reserved_at' => $this->now->subMonth(), 'accepted_at' => $this->now->subMonth(), 'status' => 'delivered']);
        $email = $this->email();
        app(CloudflareCostGuard::class)->reserve($email, $this->now);
        self::assertSame('reserved', $email->fresh()->status);
    }

    public function test_minute_limit_is_atomic_recipient_units_and_cannot_be_raised_above_five(): void
    {
        config()->set('communications.cloudflare.max_recipient_units_per_minute', 100);
        $this->budget();
        $guard = app(CloudflareCostGuard::class);
        $guard->reserve($this->email(['recipient_count' => 3, 'chargeable_budget_units' => 3]), $this->now);
        $guard->reserve($this->email(['recipient_count' => 2, 'chargeable_budget_units' => 2]), $this->now);
        $this->assertBlocked($this->email());
    }

    public function test_a_new_minute_reopens_rate_capacity_but_not_monthly_accounting(): void
    {
        $this->budget(['reconciled_at' => $this->now->subSeconds(61), 'provider_daily_reconciled_at' => $this->now->subSeconds(61)]);
        $guard = app(CloudflareCostGuard::class);
        $guard->reserve($this->email(['recipient_count' => 5, 'chargeable_budget_units' => 5]), $this->now->subSeconds(61));
        $email = $this->email();
        $guard->reserve($email, $this->now);
        self::assertSame(6, $guard->localReservedOrAcceptedUnits($this->now));
    }

    public function test_the_same_ledger_record_cannot_be_reserved_twice(): void
    {
        $this->budget();
        $email = $this->email();
        app(CloudflareCostGuard::class)->reserve($email, $this->now);
        $this->assertBlocked($email);
    }

    public function test_earlier_request_cannot_ignore_a_later_request_that_committed_before_its_lock(): void
    {
        $this->budget(['provider_chargeable_used' => 2849]);
        $this->email(['budget_reserved_at' => $this->now->addSecond(), 'status' => 'reserved']);
        $this->assertBlocked($this->email());
    }

    public function test_minute_guard_counts_later_request_timestamps_already_committed(): void
    {
        $this->budget();
        $this->email([
            'budget_reserved_at' => $this->now->addSecond(), 'status' => 'reserved',
            'recipient_count' => 5, 'chargeable_budget_units' => 5,
        ]);
        $this->assertBlocked($this->email());
    }

    public function test_a_stale_model_cannot_release_a_provider_accepted_reservation(): void
    {
        $this->budget();
        $email = $this->email();
        $guard = app(CloudflareCostGuard::class);
        $guard->reserve($email, $this->now);
        $stale = $email->fresh();
        $guard->markAccepted($email, 'test-provider-id', $this->now);
        $guard->releaseBeforeAcceptance($stale, 'stale failure', $this->now);
        self::assertNull($email->fresh()->budget_released_at);
    }

    public function test_submitted_reservation_cannot_be_released_without_definitive_evidence(): void
    {
        $this->budget();
        $email = $this->email(['budget_reserved_at' => $this->now, 'submitted_at' => $this->now, 'status' => 'submitted']);
        app(CloudflareCostGuard::class)->releaseBeforeAcceptance($email, 'ambiguous failure', $this->now);
        self::assertNull($email->fresh()->budget_released_at);
    }

    #[DataProvider('uncertainResponses')]
    public function test_uncertain_provider_outcomes_stay_reserved_and_are_never_retried(string $response): void
    {
        config()->set('communications.cloudflare.safe_email_ceiling', 1);
        $this->budget();
        $attempts = 0;
        Http::fake(function () use ($response, &$attempts) {
            $attempts++;
            if ($response === 'timeout') {
                throw new ConnectionException('isolated timeout');
            }

            return Http::response(['success' => $response === 'malformed'], $response === 'malformed' ? 200 : (int) $response);
        });
        try {
            $this->send();
            self::fail('The uncertain provider outcome must not be reported as accepted.');
        } catch (\Illuminate\Http\Client\HttpClientException) {
            // The provider may have accepted the message before this failure.
        }
        $email = OutboundEmail::query()->sole();
        self::assertSame('acceptance_unknown', $email->status);
        self::assertNull($email->budget_released_at);
        self::assertSame(1, $attempts);
        try {
            $this->send();
            self::fail('The held reservation must block another attempt at the monthly limit.');
        } catch (EmailBudgetExhausted) {
            self::assertSame(1, $attempts);
        }
    }

    /** @return array<string, array{string}> */
    public static function uncertainResponses(): array
    {
        return ['connection timeout' => ['timeout'], 'server failure' => ['503'], 'rate limited' => ['429'], 'missing message ID' => ['malformed']];
    }

    private function send(): OutboundEmail
    {
        return app(CloudflareEmailDispatcher::class)->send(['recipient@example.test'], 'Isolated test', 'Test only', null, 'test');
    }

    private function assertBlocked(OutboundEmail $email): void
    {
        try {
            app(CloudflareCostGuard::class)->reserve($email, $this->now);
            self::fail('The reservation must be blocked.');
        } catch (EmailBudgetExhausted) {
            self::assertTrue(true);
        }
    }

    private function budget(array $overrides = []): void
    {
        CloudflareUsageBudget::query()->create(array_merge([
            'provider_account_id' => str_repeat('a', 32),
            'cycle_start' => $this->now->startOfMonth(), 'cycle_end' => $this->now->addMonth()->startOfMonth(),
            'provider_chargeable_used' => 0, 'provider_daily_quota' => 10000, 'provider_daily_used' => 0,
            'hub_safe_ceiling' => 2850, 'worker_requests_used' => 0, 'worker_cpu_ms_used' => 0,
            'worker_request_threshold' => 9000000, 'worker_cpu_ms_threshold' => 27000000,
            'reconciled_at' => $this->now, 'provider_daily_reconciled_at' => $this->now,
        ], $overrides));
    }

    private function email(array $overrides = []): OutboundEmail
    {
        return OutboundEmail::query()->create(array_merge([
            'provider' => 'cloudflare', 'source_type' => 'test', 'from_address' => 'info@mbfdhub.com',
            'to_recipients' => ['recipient@example.test'], 'subject' => 'Isolated test',
            'recipient_count' => 1, 'chargeable_budget_units' => 1, 'status' => 'pending',
        ], $overrides));
    }
}
