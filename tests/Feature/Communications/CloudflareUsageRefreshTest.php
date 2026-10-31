<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Exceptions\CloudflareUsageRefreshFailed;
use App\Exceptions\EmailBudgetExhausted;
use App\Models\CloudflareUsageBudget;
use App\Models\OutboundEmail;
use App\Services\Communications\CloudflareCostGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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

    public function test_provider_failure_never_prints_provider_text_and_keeps_the_last_good_time(): void
    {
        $this->fakeProvider();
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertSuccessful();
        $goodAt = CloudflareUsageBudget::query()->sole()->reconciled_at;
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['success' => false, 'errors' => [['message' => 'isolated-secret']]], 403)]);
        $this->travel(5)->minutes();
        $this->artisan('mbfd:cloudflare-usage-refresh')->doesntExpectOutputToContain('isolated-secret')->assertFailed();
        self::assertTrue(CloudflareUsageBudget::query()->sole()->reconciled_at->equalTo($goodAt), 'A failed poll must never look like a fresh one.');
    }

    #[DataProvider('providerCalls')]
    public function test_a_rejected_provider_call_is_logged_by_name_and_status_without_secrets(string $call): void
    {
        $logged = $this->captureLog();
        $this->fakeProvider(overrides: ['*/'.$call => Http::response(
            ['success' => false, 'errors' => [['message' => 'isolated-secret provider-body-marker']]], 403,
        )]);

        $this->artisan('mbfd:cloudflare-usage-refresh')->assertFailed();

        $entry = $logged->firstWhere('message', 'cloudflare_usage_refresh_failed');
        self::assertSame('warning', $entry->level);
        self::assertSame($call, $entry->context['provider_call']);
        self::assertSame(403, $entry->context['http_status']);
        self::assertSame(CloudflareUsageRefreshFailed::class, $entry->context['exception_class']);
        $this->assertLogHoldsNoSecrets($logged);
    }

    public static function providerCalls(): array
    {
        return [['subscriptions'], ['billable-usage'], ['email/sending/limits']];
    }

    public function test_a_request_that_never_completes_is_logged_by_its_exception_class_without_secrets(): void
    {
        $logged = $this->captureLog();
        $this->fakeProvider(overrides: ['*/billable-usage' => static function (): never {
            throw new ConnectionException('cURL error for https://api.cloudflare.com/client/v4/accounts/'.self::ACCOUNT.'/billable-usage Bearer isolated-secret');
        }]);

        $this->artisan('mbfd:cloudflare-usage-refresh')->assertFailed();

        $entry = $logged->firstWhere('message', 'cloudflare_usage_refresh_failed');
        self::assertSame('billable-usage', $entry->context['provider_call']);
        self::assertNull($entry->context['http_status']);
        self::assertSame(ConnectionException::class, $entry->context['exception_class']);
        $this->assertLogHoldsNoSecrets($logged);
    }

    public function test_a_report_that_answers_but_fails_checks_logs_the_reason_with_its_status(): void
    {
        $logged = $this->captureLog();
        $this->fakeProvider(overrides: ['*/subscriptions' => Http::response(
            ['success' => true, 'errors' => [], 'result' => [], 'result_info' => ['total_pages' => 2]],
        )]);

        $this->artisan('mbfd:cloudflare-usage-refresh')->assertFailed();

        $entry = $logged->firstWhere('message', 'cloudflare_usage_refresh_failed');
        self::assertSame('subscriptions', $entry->context['provider_call']);
        self::assertSame(200, $entry->context['http_status']);
        self::assertSame('Cloudflare returned only part of the report.', $entry->context['reason']);
    }

    public function test_a_report_rejected_after_all_three_calls_logs_a_fixed_reason(): void
    {
        $logged = $this->captureLog();
        $rows = $this->rows();
        $rows[0]['BillingAccountId'] = str_repeat('b', 32);
        $this->fakeProvider($rows);

        $this->artisan('mbfd:cloudflare-usage-refresh')->assertFailed();

        $entry = $logged->firstWhere('message', 'cloudflare_usage_refresh_failed');
        self::assertNull($entry->context['provider_call']);
        self::assertNull($entry->context['http_status']);
        self::assertSame('Billing report account mismatch.', $entry->context['reason']);
    }

    public function test_an_unexpected_exception_logs_only_its_class_because_its_message_is_not_ours(): void
    {
        $logged = $this->captureLog();
        $this->fakeProvider();
        Event::listen('eloquent.creating: '.CloudflareUsageBudget::class, static function (): never {
            throw new RuntimeException('Query failed for isolated-secret on https://api.cloudflare.com provider-body-marker');
        });

        $this->artisan('mbfd:cloudflare-usage-refresh')->assertFailed();

        $entry = $logged->firstWhere('message', 'cloudflare_usage_refresh_failed');
        self::assertSame(RuntimeException::class, $entry->context['exception_class']);
        self::assertNull($entry->context['reason']);
        $this->assertLogHoldsNoSecrets($logged);
    }

    public function test_an_overlapping_refresh_logs_its_reason_and_leaves_sending_open(): void
    {
        $this->fakeProvider();
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertSuccessful();
        $logged = $this->captureLog();
        Cache::lock('cloudflare-usage-refresh', 120)->get();

        $this->artisan('mbfd:cloudflare-usage-refresh')->assertFailed();

        $entry = $logged->firstWhere('message', 'cloudflare_usage_refresh_failed');
        self::assertSame('A Cloudflare usage refresh is already in progress.', $entry->context['reason']);
        self::assertNotNull(CloudflareUsageBudget::query()->sole()->reconciled_at);
    }

    public function test_a_rejected_report_still_closes_sending_at_once(): void
    {
        $this->fakeProvider();
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertSuccessful();
        $logged = $this->captureLog();
        $rows = $this->rows();
        $rows[0]['BillingAccountId'] = str_repeat('b', 32);
        $this->fakeProvider($rows);

        $this->artisan('mbfd:cloudflare-usage-refresh')->assertFailed();

        $budget = CloudflareUsageBudget::query()->sole();
        self::assertNull($budget->reconciled_at);
        self::assertNull($budget->provider_daily_reconciled_at);
        self::assertSame('Billing report account mismatch.', $logged->firstWhere('message', 'cloudflare_usage_refresh_failed')->context['reason']);
    }

    public function test_an_unexpected_exception_still_closes_sending_at_once(): void
    {
        $this->fakeProvider();
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertSuccessful();
        $budget = CloudflareUsageBudget::query()->sole();
        $failed = false;
        Event::listen('eloquent.saving: '.CloudflareUsageBudget::class, static function () use (&$failed): void {
            if (! $failed) {
                $failed = true;
                throw new RuntimeException('Unexpected database failure');
            }
        });

        $this->artisan('mbfd:cloudflare-usage-refresh')->assertFailed();

        self::assertNull($budget->fresh()->reconciled_at);
    }

    #[DataProvider('unusableAnswers')]
    public function test_a_provider_that_gives_no_usable_answer_leaves_sending_to_the_age_limit(int $status): void
    {
        $this->fakeProvider();
        $this->artisan('mbfd:cloudflare-usage-refresh')->assertSuccessful();
        $this->fakeProvider(overrides: ['*/email/sending/limits' => Http::response([], $status)]);
        $this->travel(5)->minutes();

        $this->artisan('mbfd:cloudflare-usage-refresh')->assertFailed();

        $budget = CloudflareUsageBudget::query()->sole();
        self::assertNotNull($budget->reconciled_at);
        self::assertNotNull($budget->provider_daily_reconciled_at);
        $email = OutboundEmail::query()->create(['provider' => 'cloudflare', 'source_type' => 'test', 'from_address' => 'info@mbfdhub.com',
            'to_recipients' => ['one@example.test'], 'subject' => 'Age limit test', 'recipient_count' => 1, 'chargeable_budget_units' => 1, 'status' => 'pending']);
        $guard = app(CloudflareCostGuard::class);
        self::assertSame('reserved', $guard->reserve($email, now())->status, 'One failed poll must not stop email.');

        $this->travel(601)->seconds();
        $this->expectException(EmailBudgetExhausted::class);
        $guard->reserve(OutboundEmail::query()->create(['provider' => 'cloudflare', 'source_type' => 'test', 'from_address' => 'info@mbfdhub.com',
            'to_recipients' => ['two@example.test'], 'subject' => 'Age limit test', 'recipient_count' => 1, 'chargeable_budget_units' => 1, 'status' => 'pending']), now());
    }

    public static function unusableAnswers(): array
    {
        return ['forbidden' => [403], 'rate limited' => [429], 'server error' => [500], 'unavailable' => [503]];
    }

    public function test_a_passing_provider_fault_is_retried_within_the_same_refresh(): void
    {
        $logged = $this->captureLog();
        $calls = 0;
        $this->fakeProvider(overrides: ['*/email/sending/limits' => function () use (&$calls) {
            $calls++;

            return $calls === 1 ? Http::response([], 503) : Http::response(['success' => true, 'errors' => [], 'result' => [
                'quota' => ['value' => 1000, 'unit' => 'day'], 'usage' => ['sent' => 0, 'over_quota' => false, 'resets_at' => null],
            ]]);
        }]);

        $this->artisan('mbfd:cloudflare-usage-refresh')->assertSuccessful();

        self::assertSame(2, $calls);
        self::assertNull($logged->firstWhere('message', 'cloudflare_usage_refresh_failed'));
    }

    public function test_a_refused_request_is_not_retried_within_the_same_refresh(): void
    {
        $calls = 0;
        $this->fakeProvider(overrides: ['*/subscriptions' => function () use (&$calls) {
            $calls++;

            return Http::response([], 403);
        }]);

        $this->artisan('mbfd:cloudflare-usage-refresh')->assertFailed();

        self::assertSame(1, $calls);
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

    /** @return Collection<int,MessageLogged> */
    private function captureLog(): Collection
    {
        $logged = new Collection;
        Event::listen(MessageLogged::class, static function (MessageLogged $entry) use ($logged): void {
            $logged->push($entry);
        });

        return $logged;
    }

    /** @param Collection<int,MessageLogged> $logged */
    private function assertLogHoldsNoSecrets(Collection $logged): void
    {
        $text = (string) json_encode($logged->map(static fn (MessageLogged $entry): array => [$entry->message, $entry->context])->all(), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('cloudflare_usage_refresh_failed', $text);
        foreach (['isolated-secret', self::ACCOUNT, 'api.cloudflare.com', 'provider-body-marker', 'Bearer'] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
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
