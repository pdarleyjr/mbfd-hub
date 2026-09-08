<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Exceptions\EmailBudgetExhausted;
use App\Models\CloudflareUsageBudget;
use App\Models\OutboundEmail;
use App\Services\Communications\CloudflareCostGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CloudflareUsageRefreshTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-08T12:00:00Z'));
        config()->set('communications.cloudflare.account_id', self::ACCOUNT);
        config()->set('communications.cloudflare.api_token', 'isolated-secret');
        Http::preventStrayRequests();
    }

    public function test_refresh_records_exact_provider_cycle_and_totals_without_sending_email(): void
    {
        $this->fakeProvider();
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertSuccessful();
        $budget = CloudflareUsageBudget::query()->sole();
        self::assertSame('2026-09-04', $budget->cycle_start->format('Y-m-d'));
        self::assertSame('2026-10-04', $budget->cycle_end->format('Y-m-d'));
        self::assertSame(2, (int) $budget->provider_chargeable_used);
        self::assertSame(38839, (int) $budget->worker_requests_used);
        self::assertSame(352468, (int) $budget->worker_cpu_ms_used);
        self::assertSame(0, (int) $budget->provider_daily_used);
        self::assertSame(1000, (int) $budget->provider_daily_quota);
        self::assertSame(self::ACCOUNT, $budget->provider_account_id);
        self::assertSame('2026-09-08 00:00:00', $budget->provider_billing_measured_through->format('Y-m-d H:i:s'));
        self::assertTrue($budget->reconciled_at->equalTo(now()));
        Http::assertSent(fn ($request): bool => $request->method() === 'GET' && str_ends_with($request->url(), '/email/sending/limits'));
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }

    #[DataProvider('invalidReports')]
    public function test_invalid_report_closes_existing_fresh_budget(string $mutation): void
    {
        $this->fakeProvider();
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertSuccessful();
        $rows = $this->rows();
        switch ($mutation) {
            case 'missing email': unset($rows[0]);
                break;
            case 'wrong account': $rows[0]['BillingAccountId'] = str_repeat('b', 32);
                break;
            case 'negative': $rows[0]['ConsumedQuantity'] = -1;
                break;
            case 'fraction': $rows[0]['ConsumedQuantity'] = 2.5;
                break;
            case 'string': $rows[0]['ConsumedQuantity'] = '2';
                break;
            case 'unknown email meter': $rows[0]['ServiceName'] = 'Unrecognized email usage';
                break;
            case 'wrong unit': $rows[0]['ConsumedUnit'] = 'Dollars';
                break;
            case 'duplicate': $rows[] = $rows[0];
                break;
            case 'future': $rows[0]['ChargePeriodEnd'] = '2026-09-09T00:00:00Z';
                break;
            case 'stale report': foreach ($rows as &$row) {
                $row['ChargePeriodEnd'] = '2026-09-05T00:00:00Z';
            } unset($row);
                break;
        }
        $this->fakeProvider(array_values($rows));
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertFailed();
        self::assertNull(CloudflareUsageBudget::query()->sole()->reconciled_at);
    }

    public static function invalidReports(): array
    {
        return array_map(fn (string $case): array => [$case], ['missing email', 'wrong account', 'negative', 'fraction', 'string', 'unknown email meter', 'wrong unit', 'duplicate', 'future', 'stale report']);
    }

    public function test_provider_failure_does_not_leave_an_old_allowing_snapshot_fresh(): void
    {
        $this->fakeProvider();
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertSuccessful();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['success' => false, 'errors' => [['message' => 'isolated-secret']]], 403)]);
        $this->artisan('mbfd:cloudflare-usage-refresh')->doesntExpectOutputToContain('isolated-secret')->assertFailed();
        self::assertNull(CloudflareUsageBudget::query()->sole()->reconciled_at);
    }

    public function test_a_delayed_lower_report_cannot_reduce_current_cycle_baseline(): void
    {
        $this->fakeProvider();
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertSuccessful();
        $rows = $this->rows();
        $rows[0]['ConsumedQuantity'] = 1;
        $this->fakeProvider($rows);
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertSuccessful();
        self::assertSame(2, (int) CloudflareUsageBudget::query()->sole()->provider_chargeable_used);
    }

    public function test_next_month_uses_authoritative_subscription_bounds_and_never_infers_empty_cycle_zero(): void
    {
        $this->fakeProvider();
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-10-04T12:00:00Z'));
        $this->fakeProvider([], '2026-10-04T00:00:00Z', '2026-11-04T00:00:00Z');
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertFailed();
        self::assertSame(1, CloudflareUsageBudget::query()->count());
        $rows = $this->rows();
        foreach ($rows as &$row) {
            $row['BillingPeriodStart'] = '2026-10-04T00:00:00Z';
            $row['ChargePeriodStart'] = '2026-10-04T00:00:00Z';
            $row['ChargePeriodEnd'] = '2026-10-05T00:00:00Z';
            $row['ConsumedQuantity'] = 0;
        }
        unset($row);
        $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
        $this->fakeProvider($rows, '2026-10-04T00:00:00Z', '2026-11-04T00:00:00Z');
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertSuccessful();
        self::assertSame(2, CloudflareUsageBudget::query()->count());
        self::assertSame(0, (int) CloudflareUsageBudget::query()->orderByDesc('cycle_start')->firstOrFail()->provider_chargeable_used);
    }

    public function test_first_billing_day_uses_realtime_daily_usage_then_retains_that_baseline_for_sparse_reports(): void
    {
        config()->set('communications.cloudflare.sole_sender', true);
        $this->travelTo(CarbonImmutable::parse('2026-10-04T12:00:00Z'));
        $this->fakeProvider([], '2026-10-04T00:00:00Z', '2026-11-04T00:00:00Z', 7);
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertSuccessful();
        $budget = CloudflareUsageBudget::query()->sole();
        self::assertSame(7, (int) $budget->provider_chargeable_used);
        self::assertSame('realtime_first_cycle_day', $budget->provider_usage_source);
        self::assertNull($budget->provider_billing_measured_through);
        self::assertNull($budget->worker_requests_used);
        self::assertNull($budget->worker_cpu_ms_used);
        $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
        $this->fakeProvider([], '2026-10-04T00:00:00Z', '2026-11-04T00:00:00Z', 1);
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertSuccessful();
        $budget->refresh();
        self::assertSame(7, (int) $budget->provider_chargeable_used);
        self::assertSame(1, (int) $budget->provider_daily_used);
        self::assertSame('retained_authoritative_baseline', $budget->provider_usage_source);
    }

    public function test_first_day_fallback_requires_utc_midnight_cycle_start(): void
    {
        config()->set('communications.cloudflare.sole_sender', true);
        $this->travelTo(CarbonImmutable::parse('2026-10-04T12:00:00Z'));
        $this->fakeProvider([], '2026-10-04T04:00:00Z', '2026-11-04T04:00:00Z');
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertFailed();
        self::assertSame(0, CloudflareUsageBudget::query()->count());
    }

    public function test_missing_cycle_history_cannot_bootstrap_from_daily_usage_after_first_day(): void
    {
        config()->set('communications.cloudflare.sole_sender', true);
        $this->fakeProvider([]);
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertFailed();
        self::assertSame(0, CloudflareUsageBudget::query()->count());
    }

    public function test_worker_usage_is_nullable_observability_and_does_not_prevent_email_budget_refresh(): void
    {
        $this->fakeProvider([$this->rows()[0]]);
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertSuccessful();
        self::assertNull(CloudflareUsageBudget::query()->sole()->worker_requests_used);
    }

    public function test_rollover_resets_accepted_history_but_retains_unresolved_prior_cycle_units(): void
    {
        config()->set('communications.cloudflare.sole_sender', true);
        config()->set('communications.cloudflare.safe_email_ceiling', 2);
        $this->travelTo(CarbonImmutable::parse('2026-10-04T12:00:00Z'));
        $this->fakeProvider([], '2026-10-04T00:00:00Z', '2026-11-04T00:00:00Z');
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertSuccessful();
        $base = ['provider' => 'cloudflare', 'source_type' => 'test', 'from_address' => 'info@mbfdhub.com',
            'to_recipients' => ['one@example.test'], 'subject' => 'Isolated rollover test', 'recipient_count' => 1,
            'chargeable_budget_units' => 1, 'status' => 'pending'];
        OutboundEmail::query()->create(array_merge($base, ['status' => 'accepted', 'chargeable_budget_units' => 2850,
            'budget_reserved_at' => now()->subDay(), 'accepted_at' => now()->subDay()]));
        OutboundEmail::query()->create(array_merge($base, ['status' => 'acceptance_unknown', 'budget_reserved_at' => now()->subDay(), 'submitted_at' => now()->subDay()]));
        $guard = app(CloudflareCostGuard::class);
        self::assertSame('reserved', $guard->reserve(OutboundEmail::query()->create($base), now())->status);
        $this->expectException(EmailBudgetExhausted::class);
        $guard->reserve(OutboundEmail::query()->create($base), now());
    }

    #[DataProvider('invalidEnvelopes')]
    public function test_ambiguous_or_incomplete_provider_envelopes_never_open_budget(string $case): void
    {
        $subscription = ['state' => 'Paid', 'current_period_start' => '2026-09-04T00:00:00Z',
            'current_period_end' => '2026-10-04T00:00:00Z', 'rate_plan' => ['id' => 'workers_paid', 'scope' => 'account', 'currency' => 'USD']];
        $path = '*/subscriptions';
        $body = ['success' => true, 'errors' => [], 'result' => [$subscription]];
        if ($case === 'ambiguous subscriptions') {
            $body['result'][] = $subscription;
        }
        if ($case === 'unpaid') {
            $body['result'][0]['state'] = 'Unpaid';
        }
        if ($case === 'no paid cycle') {
            $body['result'] = [];
        }
        if ($case === 'missing success') {
            unset($body['success']);
        }
        if ($case === 'provider error') {
            $body['errors'] = [['message' => 'test provider error']];
        }
        if ($case === 'pagination') {
            $path = '*/billable-usage';
            $body['result'] = $this->rows();
            $body['result_info'] = ['total_pages' => 2];
        }
        if ($case === 'missing rows') {
            $path = '*/billable-usage';
            $body['result'] = $this->rows();
            $body['result_info'] = ['total_count' => 4];
        }
        if ($case === 'daily quota unit') {
            $path = '*/email/sending/limits';
            $body['result'] = ['quota' => ['value' => 1000, 'unit' => 'month'], 'usage' => ['sent' => 0, 'over_quota' => false]];
        }
        if ($case === 'daily unknown') {
            $path = '*/email/sending/limits';
            $body['result'] = ['quota' => ['value' => 1000, 'unit' => 'day'], 'usage' => ['sent' => null, 'over_quota' => false]];
        }
        $this->fakeProvider(overrides: [$path => Http::response($body)]);
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertFailed();
        self::assertSame(0, CloudflareUsageBudget::query()->count());
    }

    public static function invalidEnvelopes(): array
    {
        return array_map(fn (string $case): array => [$case], ['ambiguous subscriptions', 'unpaid', 'no paid cycle', 'missing success', 'provider error', 'pagination', 'missing rows', 'daily quota unit', 'daily unknown']);
    }

    private function fakeProvider(?array $rows = null, string $start = '2026-09-04T00:00:00Z', string $end = '2026-10-04T00:00:00Z', int $sent = 0, array $overrides = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(array_replace([
            '*/subscriptions' => Http::response(['success' => true, 'errors' => [], 'result' => [[
                'state' => 'Paid', 'current_period_start' => $start, 'current_period_end' => $end,
                'rate_plan' => ['id' => 'workers_paid', 'scope' => 'account', 'currency' => 'USD'],
            ]]]),
            '*/billable-usage' => Http::response(['success' => true, 'errors' => [], 'result' => $rows ?? $this->rows()]),
            '*/email/sending/limits' => Http::response(['success' => true, 'errors' => [], 'result' => [
                'quota' => ['value' => 1000, 'unit' => 'day'], 'usage' => ['sent' => $sent, 'over_quota' => $sent >= 1000, 'resets_at' => null],
            ]]),
        ], $overrides));
    }

    private function rows(): array
    {
        $rows = [];
        foreach ([['Email', 'Email Service - Emails Sent (First 3,000 emails included)', 2], ['Workers', 'Workers Standard Requests (first 10M are included)', 38839], ['Workers', 'Workers CPU ms (first 30M are included)', 352468]] as [$family, $service, $quantity]) {
            $rows[] = ['BillingAccountId' => self::ACCOUNT, 'ServiceFamilyName' => $family, 'ServiceName' => $service,
                'ChargeCategory' => 'Usage', 'ConsumedUnit' => '', 'ConsumedQuantity' => $quantity,
                'BillingPeriodStart' => '2026-09-04T00:00:00Z', 'ChargePeriodStart' => '2026-09-07T00:00:00Z',
                'ChargePeriodEnd' => '2026-09-08T00:00:00Z', 'SubscriptionId' => 'test-subscription'];
        }

        return $rows;
    }
}
