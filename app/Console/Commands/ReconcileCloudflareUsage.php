<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CloudflareUsageBudget;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ReconcileCloudflareUsage extends Command
{
    protected $signature = 'mbfd:cloudflare-usage-reconcile
        {cycle-start : Exact billing-cycle start in ISO-8601}
        {cycle-end : Exact billing-cycle end in ISO-8601}
        {provider-chargeable : Current Email Service chargeable-destination usage}
        {--verified-destinations=0 : Current verified-destination usage}
        {--provider-daily-quota= : Current account-specific daily sending quota}
        {--provider-daily-used= : Current daily sending usage}
        {--worker-requests= : Current billing-cycle Worker requests}
        {--worker-cpu-ms= : Current billing-cycle Worker CPU milliseconds}';

    protected $description = 'Persist an externally verified Cloudflare billing snapshot that gates Hub email';

    public function handle(): int
    {
        $chargeable = max(0, (int) $this->argument('provider-chargeable'));
        $ceiling = (int) config('communications.cloudflare.safe_email_ceiling', 2850);
        $workerRequests = (string) $this->option('worker-requests');
        $workerCpu = (string) $this->option('worker-cpu-ms');
        $dailyQuota = (string) $this->option('provider-daily-quota');
        $dailyUsed = (string) $this->option('provider-daily-used');
        $requestThreshold = (int) config('communications.cloudflare.worker_request_threshold', 9000000);
        $cpuThreshold = (int) config('communications.cloudflare.worker_cpu_ms_threshold', 27000000);

        if ($dailyQuota === '' || $dailyUsed === '' || $workerRequests === '' || $workerCpu === '') {
            $this->error('Daily quota and Worker request/CPU usage are required. No sending budget was opened.');

            return self::FAILURE;
        }

        if ($chargeable >= $ceiling
            || (int) $dailyUsed >= (int) $dailyQuota
            || (int) $workerRequests >= $requestThreshold
            || (int) $workerCpu >= $cpuThreshold) {
            $this->error('The supplied snapshot is at or above a safety threshold. No sending budget was opened.');

            return self::FAILURE;
        }

        CloudflareUsageBudget::query()->updateOrCreate([
            'cycle_start' => CarbonImmutable::parse((string) $this->argument('cycle-start')),
            'cycle_end' => CarbonImmutable::parse((string) $this->argument('cycle-end')),
        ], [
            'provider_chargeable_used' => $chargeable,
            'provider_verified_destination_used' => max(0, (int) $this->option('verified-destinations')),
            'provider_daily_quota' => max(0, (int) $dailyQuota),
            'provider_daily_used' => max(0, (int) $dailyUsed),
            'hub_safe_ceiling' => $ceiling,
            'worker_requests_used' => max(0, (int) $workerRequests),
            'worker_cpu_ms_used' => max(0, (int) $workerCpu),
            'worker_request_threshold' => $requestThreshold,
            'worker_cpu_ms_threshold' => $cpuThreshold,
            'reconciled_at' => now(),
            'provider_daily_reconciled_at' => now(),
        ]);

        $this->info('Cloudflare usage snapshot recorded. No email was sent and no Cloudflare configuration was changed.');

        return self::SUCCESS;
    }
}
