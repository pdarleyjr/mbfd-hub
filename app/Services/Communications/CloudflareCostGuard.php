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
            $budgets = CloudflareUsageBudget::query()
                ->where('cycle_start', '<=', $at)
                ->where('cycle_end', '>', $at)
                ->lockForUpdate()
                ->get();
            /** @var CloudflareUsageBudget|null $budget */
            $budget = $budgets->count() === 1 ? $budgets->first() : null;

            $maxAge = (int) config('communications.cloudflare.max_reconciliation_age_seconds', 900);
            $accountId = (string) config('communications.cloudflare.account_id');
            if ($budget === null
                || preg_match('/\A[a-f0-9]{32}\z/i', $accountId) !== 1
                || $budget->provider_account_id !== $accountId
                || $budget->reconciled_at === null
                || $budget->provider_daily_reconciled_at === null
                || $budget->provider_daily_quota === null
                || $budget->provider_daily_used === null
                || ! $this->hasValidCounters($budget)
                || (int) $budget->provider_daily_quota < 1
                || CarbonImmutable::parse($budget->reconciled_at)->gt($at)
                || CarbonImmutable::parse($budget->provider_daily_reconciled_at)->gt($at)
                || CarbonImmutable::parse($budget->reconciled_at)->lt($at->copy()->subSeconds($maxAge))
                || CarbonImmutable::parse($budget->provider_daily_reconciled_at)->lt($at->copy()->subSeconds($maxAge))) {
                throw new EmailBudgetExhausted('Cloudflare usage has not been reconciled for the active cycle.');
            }
            if (CloudflareUsageBudget::query()->where('provider_backoff_until', '>', $at)->exists()) {
                throw new EmailBudgetExhausted('Cloudflare requested a sending pause. Try again after the provider backoff.');
            }

            $email = OutboundEmail::query()->lockForUpdate()->findOrFail($email->getKey());
            if ($email->status !== 'pending' || $email->budget_reserved_at !== null
                || (int) $email->chargeable_budget_units < 1) {
                throw new EmailBudgetExhausted('This outbound message cannot be reserved again.');
            }

            // Provider usage can lag. Deliberately double-count local sends already
            // included in that baseline rather than forget them at refresh time.
            $reservedSinceCycleReconciliation = $this->localReservedOrAcceptedUnits(
                $at,
                true,
                CarbonImmutable::parse($budget->cycle_start),
                true,
            );
            $reservedSinceDailyReconciliation = $this->localReservedOrAcceptedUnits(
                $at,
                true,
                CarbonImmutable::instance($at)->utc()->startOfDay(),
                true,
            );
            $ceiling = min(
                2850,
                (int) $budget->hub_safe_ceiling,
                (int) config('communications.cloudflare.safe_email_ceiling', 2850),
            );
            if ((int) $budget->provider_chargeable_used + $reservedSinceCycleReconciliation + (int) $email->chargeable_budget_units > $ceiling) {
                throw new EmailBudgetExhausted('The reconciled Cloudflare email safety ceiling would be exceeded.');
            }
            if ((int) $budget->provider_daily_used + $reservedSinceDailyReconciliation + (int) $email->chargeable_budget_units > (int) $budget->provider_daily_quota) {
                throw new EmailBudgetExhausted('The reconciled Cloudflare daily quota would be exceeded.');
            }
            $minuteLimit = min(5, (int) config('communications.cloudflare.max_recipient_units_per_minute', 5));
            $minuteUnits = $this->localReservedOrAcceptedUnits($at, true, $at->copy()->subMinute());
            if ($minuteUnits + (int) $email->chargeable_budget_units > $minuteLimit) {
                throw new EmailBudgetExhausted('The Hub email rate limit has been reached. Try again later.');
            }
            // Laravel sends directly to the Email REST API, not through a
            // Worker. Unrelated Worker consumption does not authorize email.

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
        return DB::transaction(function () use ($email, $reason, $at): OutboundEmail {
            $email = OutboundEmail::query()->lockForUpdate()->findOrFail($email->getKey());
            if ($email->accepted_at !== null || $email->submitted_at !== null || $email->status === 'acceptance_unknown') {
                return $email;
            }

            $email->forceFill([
                'status' => 'failed_pre_acceptance',
                'budget_released_at' => $at,
                'failed_at' => $at,
                'failure_reason' => $reason,
            ])->save();

            return $email;
        });
    }

    public function markUncertain(OutboundEmail $email, CarbonInterface $at): OutboundEmail
    {
        return DB::transaction(function () use ($email, $at): OutboundEmail {
            $email = OutboundEmail::query()->lockForUpdate()->findOrFail($email->getKey());
            if ($email->accepted_at === null && $email->budget_reserved_at !== null && $email->budget_released_at === null) {
                $email->forceFill([
                    'status' => 'acceptance_unknown',
                    'failed_at' => $at,
                    'failure_reason' => 'Provider acceptance was not confirmed. Reservation retained; do not automatically retry.',
                ])->save();
            }

            return $email;
        });
    }

    public function deferUntil(CarbonImmutable $until): void
    {
        DB::transaction(function () use ($until): void {
            // Keep the pause across billing boundaries and never shorten an
            // already recorded provider backoff when responses arrive out of order.
            foreach (CloudflareUsageBudget::query()->orderBy('id')->lockForUpdate()->get() as $budget) {
                if ($budget->provider_backoff_until === null || CarbonImmutable::parse($budget->provider_backoff_until)->lt($until)) {
                    $budget->forceFill(['provider_backoff_until' => $until])->save();
                }
            }
        });
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
        bool $carryUnresolved = false,
    ): int {
        $query = OutboundEmail::query()
            ->whereNotNull('budget_reserved_at')
            ->whereNull('budget_released_at');

        // A later-started request can acquire the serialization lock first.
        // Count every committed reservation, including timestamps after this
        // request's captured clock value, or concurrent sends could escape the cap.

        if ($since !== null) {
            $query->where(function ($query) use ($since, $carryUnresolved): void {
                $query->where('budget_reserved_at', '>=', $since);
                if ($carryUnresolved) {
                    // Unresolved requests from an earlier period may be accepted
                    // now. Late confirmed acceptance belongs to this period too.
                    $query->orWhereNull('accepted_at')->orWhere('accepted_at', '>=', $since);
                }
            });
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

    private function hasValidCounters(CloudflareUsageBudget $budget): bool
    {
        // PostgreSQL has no unsigned integer type. Validate the actual stored
        // values instead of assuming the migration's unsigned declaration holds.
        foreach (['provider_chargeable_used', 'provider_daily_used'] as $column) {
            if (filter_var($budget->getRawOriginal($column), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
                return false;
            }
        }

        return true;
    }
}
