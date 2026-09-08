<?php

declare(strict_types=1);

namespace App\Services\Communications;

use App\Models\CloudflareUsageBudget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class CloudflareUsageRefresher
{
    private const METRICS = [
        'Email Service - Emails Sent (First 3,000 emails included)' => ['Email', 'provider_chargeable_used'],
        'Workers Standard Requests (first 10M are included)' => ['Workers', 'worker_requests_used'],
        'Workers CPU ms (first 30M are included)' => ['Workers', 'worker_cpu_ms_used'],
    ];

    public function refresh(): CloudflareUsageBudget
    {
        $lock = Cache::lock('cloudflare-usage-refresh', 120);
        if (! $lock->get()) {
            throw new RuntimeException('A Cloudflare usage refresh is already in progress.');
        }
        try {
            return $this->fetchAndPersist();
        } catch (Throwable) {
            // A failed poll must not leave an earlier, lower snapshot open.
            DB::transaction(function (): void {
                foreach (CloudflareUsageBudget::query()->where('cycle_end', '>', now())->lockForUpdate()->get() as $budget) {
                    $budget->forceFill(['reconciled_at' => null, 'provider_daily_reconciled_at' => null])->save();
                }
            });
            // Never expose token-bearing HTTP exceptions or raw provider bodies.
            throw new RuntimeException('Cloudflare usage could not be verified. Sending is closed until a complete refresh succeeds.');
        } finally {
            $lock->release();
        }
    }

    private function fetchAndPersist(): CloudflareUsageBudget
    {
        $account = (string) config('communications.cloudflare.account_id');
        $token = (string) config('communications.cloudflare.api_token');
        if (preg_match('/\A[a-f0-9]{32}\z/i', $account) !== 1 || $token === '') {
            throw new RuntimeException('Cloudflare usage credentials are not configured.');
        }
        $subscriptions = $this->get($account, $token, 'subscriptions');
        $rows = $this->get($account, $token, 'billable-usage');
        $limits = $this->get($account, $token, 'email/sending/limits');
        $at = CarbonImmutable::now()->utc();
        $active = [];
        foreach ($subscriptions as $subscription) {
            if (! is_array($subscription) || data_get($subscription, 'rate_plan.id') !== 'workers_paid') {
                continue;
            }
            $start = $this->date($subscription['current_period_start'] ?? null);
            $end = $this->date($subscription['current_period_end'] ?? null);
            if ($start->lte($at) && $end->gt($at) && ($subscription['state'] ?? null) === 'Paid'
                && data_get($subscription, 'rate_plan.scope') === 'account'
                && data_get($subscription, 'rate_plan.currency') === 'USD') {
                $active[] = [$start, $end];
            }
        }
        if (count($active) !== 1) {
            throw new RuntimeException('The active paid billing cycle is ambiguous.');
        }
        [$start, $end] = $active[0];
        if (! array_is_list($rows) || ! array_is_list($subscriptions)) {
            throw new RuntimeException('Incomplete Cloudflare report.');
        }
        $quota = $this->count(data_get($limits, 'quota.value'));
        $dailyUsed = $this->count(data_get($limits, 'usage.sent'));
        if (data_get($limits, 'quota.unit') !== 'day' || $quota < 1
            || ! is_bool(data_get($limits, 'usage.over_quota'))
            || data_get($limits, 'usage.over_quota') !== ($dailyUsed >= $quota)) {
            throw new RuntimeException('The daily quota response is inconsistent.');
        }
        $totals = [];
        $seen = [];
        $measured = null;
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['BillingAccountId'] ?? null) !== $account) {
                throw new RuntimeException('Billing report account mismatch.');
            }
            if (! $this->date($row['BillingPeriodStart'] ?? null)->equalTo($start)) {
                continue;
            }
            $periodStart = $this->date($row['ChargePeriodStart'] ?? null);
            $periodEnd = $this->date($row['ChargePeriodEnd'] ?? null);
            if ($periodStart->lt($start) || $periodStart->gte($periodEnd) || $periodEnd->gt($at) || $periodEnd->gt($end)) {
                throw new RuntimeException('Invalid billing measurement period.');
            }
            $measured = $measured === null || $periodEnd->gt($measured) ? $periodEnd : $measured;
            $metric = self::METRICS[$row['ServiceName'] ?? ''] ?? null;
            if ($metric === null) {
                if (($row['ServiceFamilyName'] ?? null) === 'Email') {
                    throw new RuntimeException('An unrecognized Email billing metric requires review.');
                }

                continue;
            }
            // This legacy endpoint uses an empty unit for these exact count
            // metrics. The exact service names identify emails, requests and ms.
            if (($row['ServiceFamilyName'] ?? null) !== $metric[0] || ($row['ConsumedUnit'] ?? null) !== ''
                || ($row['ChargeCategory'] ?? null) !== 'Usage' || ! is_string($row['SubscriptionId'] ?? null)
                || $row['SubscriptionId'] === '') {
                throw new RuntimeException('Unexpected billing metric schema.');
            }
            $key = $metric[1].'|'.$row['SubscriptionId'].'|'.$periodStart->toIso8601String().'|'.$periodEnd->toIso8601String();
            if (isset($seen[$key])) {
                throw new RuntimeException('Duplicate billing metric.');
            }
            $seen[$key] = true;
            $quantity = $this->count($row['ConsumedQuantity'] ?? null);
            $prior = $totals[$metric[1]] ?? 0;
            if ($quantity > PHP_INT_MAX - $prior) {
                throw new RuntimeException('Billing count overflow.');
            }
            $totals[$metric[1]] = $prior + $quantity;
        }

        return DB::transaction(function () use ($start, $end, $at, $account, $quota, $dailyUsed, $totals, $measured): CloudflareUsageBudget {
            $budget = CloudflareUsageBudget::query()->where('cycle_start', $start)->where('cycle_end', $end)->lockForUpdate()->first();
            $baseline = $totals['provider_chargeable_used'] ?? null;
            $source = 'billable_usage';
            $soleSender = config('communications.cloudflare.sole_sender', false) === true;
            if ($baseline === null && $soleSender && $start->equalTo($at->startOfDay())) {
                // Only on the exact first UTC billing day does realtime daily
                // usage also prove total usage since the billing-cycle start.
                $baseline = $dailyUsed;
                $source = 'realtime_first_cycle_day';
            } elseif ($baseline === null && $soleSender && $budget !== null && $budget->provider_account_id === $account) {
                // A sparse report is NOT proof of zero. Preserve an established
                // baseline; all sole-sender Hub reservations remain counted.
                $baseline = (int) $budget->provider_chargeable_used;
                $source = 'retained_authoritative_baseline';
            }
            if ($baseline === null) {
                throw new RuntimeException('No authoritative current-cycle email baseline is available.');
            }
            if (! $soleSender && ($measured === null || $measured->lt($at->subHours(48)))) {
                throw new RuntimeException('The billing report is stale.');
            }
            if ($budget !== null && $budget->provider_account_id !== null && $budget->provider_account_id !== $account) {
                throw new RuntimeException('The existing budget belongs to a different account.');
            }
            $budget ??= new CloudflareUsageBudget(['cycle_start' => $start, 'cycle_end' => $end]);
            $budget->fill([
                'provider_account_id' => $account,
                'provider_usage_source' => $source,
                // Poll time is not measurement time: legacy billing data updates
                // daily. This watermark is the latest reported usage period end,
                // NOT proof that every product is measured through that instant.
                'provider_billing_measured_through' => $measured,
                'provider_chargeable_used' => max($baseline, (int) $budget->provider_chargeable_used),
                'provider_daily_quota' => $quota, 'provider_daily_used' => $dailyUsed,
                'hub_safe_ceiling' => min(2850, (int) config('communications.cloudflare.safe_email_ceiling', 2850)),
                'worker_requests_used' => $totals['worker_requests_used'] ?? null,
                'worker_cpu_ms_used' => $totals['worker_cpu_ms_used'] ?? null,
                'reconciled_at' => $at, 'provider_daily_reconciled_at' => $at,
            ])->save();

            return $budget;
        });
    }

    /** @return array<mixed> */
    private function get(string $account, string $token, string $path): array
    {
        $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20)
            ->get('https://api.cloudflare.com/client/v4/accounts/'.$account.'/'.$path);
        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ($body['success'] ?? null) !== true
            || ($body['errors'] ?? null) !== [] || ! is_array($body['result'] ?? null)
            || (int) data_get($body, 'result_info.total_pages', 1) > 1
            || (isset($body['result_info']['total_count']) && $body['result_info']['total_count'] !== count($body['result']))) {
            throw new RuntimeException('Incomplete Cloudflare response.');
        }

        return $body['result'];
    }

    private function count(mixed $value): int
    {
        if (! is_int($value) || $value < 0) {
            throw new RuntimeException('Provider usage must be an exact non-negative integer.');
        }

        return $value;
    }

    private function date(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value) !== 1) {
            throw new RuntimeException('A provider UTC timestamp is missing.');
        }
        $date = CarbonImmutable::parse($value);
        if ($date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new RuntimeException('A provider UTC timestamp is invalid.');
        }

        return $date;
    }
}
