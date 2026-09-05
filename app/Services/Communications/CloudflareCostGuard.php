<?php

declare(strict_types=1);

namespace App\Services\Communications;

use App\Exceptions\EmailBudgetExhausted;
use App\Models\CloudflareUsageBudget;
use App\Models\OutboundEmail;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class CloudflareCostGuard
{
    public function reserve(OutboundEmail $email, CarbonInterface $at): OutboundEmail
    {
        return DB::transaction(function () use ($email, $at): OutboundEmail {
            /** @var CloudflareUsageBudget|null $budget */
            $budget = CloudflareUsageBudget::query()
                ->where('cycle_start', '<=', $at)
                ->where('cycle_end', '>', $at)
                ->lockForUpdate()
                ->first();

            $maxAge = (int) config('communications.cloudflare.max_reconciliation_age_seconds', 900);
            if ($budget === null
                || $budget->reconciled_at === null
                || $budget->provider_daily_reconciled_at === null
                || $budget->provider_daily_quota === null
                || $budget->provider_daily_used === null
                || $budget->worker_requests_used === null
                || $budget->worker_cpu_ms_used === null
                || CarbonImmutable::parse($budget->reconciled_at)->lt($at->copy()->subSeconds($maxAge))
                || CarbonImmutable::parse($budget->provider_daily_reconciled_at)->lt($at->copy()->subSeconds($maxAge))) {
                throw new EmailBudgetExhausted('Cloudflare usage has not been reconciled for the active cycle.');
            }

            $reservedSinceCycleReconciliation = $this->localReservedOrAcceptedUnits(
                $at,
                true,
                CarbonImmutable::parse($budget->reconciled_at),
            );
            $reservedSinceDailyReconciliation = $this->localReservedOrAcceptedUnits(
                $at,
                true,
                CarbonImmutable::parse($budget->provider_daily_reconciled_at),
            );
            $ceiling = min(
                (int) $budget->hub_safe_ceiling,
                (int) config('communications.cloudflare.safe_email_ceiling', 2850),
            );
            if ((int) $budget->provider_chargeable_used + $reservedSinceCycleReconciliation + (int) $email->chargeable_budget_units > $ceiling) {
                throw new EmailBudgetExhausted('The reconciled Cloudflare email safety ceiling would be exceeded.');
            }
            if ((int) $budget->provider_daily_used + $reservedSinceDailyReconciliation + (int) $email->chargeable_budget_units > (int) $budget->provider_daily_quota) {
                throw new EmailBudgetExhausted('The reconciled Cloudflare daily quota would be exceeded.');
            }
            if ((int) $budget->worker_requests_used >= (int) $budget->worker_request_threshold
                || (int) $budget->worker_cpu_ms_used >= (int) $budget->worker_cpu_ms_threshold) {
                throw new EmailBudgetExhausted('The Cloudflare Worker operating threshold has been reached.');
            }

            $email->forceFill([
                'status' => 'reserved',
                'budget_reserved_at' => $at,
                'budget_released_at' => null,
            ])->save();

            return $email;
        });
    }

    public function releaseBeforeAcceptance(OutboundEmail $email, string $reason, CarbonInterface $at): OutboundEmail
    {
        if ($email->accepted_at !== null) {
            return $email;
        }

        $email->forceFill([
            'status' => 'failed_pre_acceptance',
            'budget_released_at' => $at,
            'failed_at' => $at,
            'failure_reason' => $reason,
        ])->save();

        return $email;
    }

    public function markAccepted(OutboundEmail $email, string $providerMessageId, CarbonInterface $at): OutboundEmail
    {
        $email->forceFill([
            'status' => 'accepted',
            'provider_message_id' => $providerMessageId,
            'submitted_at' => $email->submitted_at ?? $at,
            'accepted_at' => $at,
        ])->save();

        return $email;
    }

    public function localReservedOrAcceptedUnits(
        CarbonInterface $at,
        bool $withinTransaction = false,
        ?CarbonInterface $since = null,
    ): int {
        $query = OutboundEmail::query()
            ->whereNotNull('budget_reserved_at')
            ->whereNull('budget_released_at')
            ->where('budget_reserved_at', '<=', $at);

        if ($since !== null) {
            $query->where('budget_reserved_at', '>=', $since);
        }

        if ($withinTransaction) {
            $query->lockForUpdate();
        }

        if ($withinTransaction) {
            $total = 0;
            foreach ($query->get(['id', 'chargeable_budget_units']) as $outboundEmail) {
                $total += (int) $outboundEmail->chargeable_budget_units;
            }

            return $total;
        }

        return (int) $query->sum('chargeable_budget_units');
    }
}
