<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Model;

use App\Models\AiAutomation\AiAutomationSetting;
use App\Models\AiAutomation\AiModelCall;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hard spend ceiling for Workers AI. A call is reserved (and its worst-case cost booked) BEFORE any request
 * leaves the server, under a lock on the master-switch row so concurrent workers cannot both slip under a
 * cap. Anything that cannot be read or is misconfigured refuses the call: this guard fails closed.
 *
 * Money is kept in micro-USD. USD per million tokens equals micro-USD per token, so cost is simply
 * tokens x price.
 */
final class AiBudgetGuard
{
    /** Input allowance used for the worst-case estimate when the caller does not know the prompt size. */
    public const DEFAULT_INPUT_ALLOWANCE_TOKENS = 4000;

    /** Hard ceiling on the output tokens ever requested, whatever the config says. */
    public const OUTPUT_TOKEN_CEILING = 2000;

    /**
     * Book a call. Returns the reserved ledger row, or null when a cap is reached or the guard cannot be sure.
     */
    public function reserve(string $model, ?int $estimatedInputTokens = null): ?AiModelCall
    {
        try {
            return DB::transaction(function () use ($model, $estimatedInputTokens): ?AiModelCall {
                // Serializes reservations on Postgres. SQLite (tests) ignores the lock; it is single-writer anyway.
                $setting = AiAutomationSetting::query()->orderBy('id')->lockForUpdate()->first();
                if ($setting === null) {
                    return null;
                }

                $dailyLimit = (int) config('ai_automation.model.daily_call_limit', 0);
                $budgetMicro = $this->budgetMicroUsd();
                if ($dailyLimit < 1 || $budgetMicro < 1) {
                    return null;
                }
                // Fail closed: a model with no listed price cannot be metered, so it is never called.
                if ($this->priceFor($model) === null) {
                    return null;
                }

                $now = $this->now();
                $today = $now->toDateString();

                // Every attempt counts, including failures, so a failing endpoint cannot be hammered past the cap.
                $callsToday = AiModelCall::query()->whereDate('call_date', $today)->count();
                if ($callsToday >= $dailyLimit) {
                    return null;
                }

                $inputTokens = max(0, $estimatedInputTokens ?? self::DEFAULT_INPUT_ALLOWANCE_TOKENS);
                $estimate = $this->costMicroUsd($inputTokens, $this->maxOutputTokens(), $model);

                $spent = (int) AiModelCall::query()
                    ->whereDate('call_date', '>=', $now->startOfMonth()->toDateString())
                    ->whereDate('call_date', '<=', $now->endOfMonth()->toDateString())
                    ->sum('cost_micro_usd');
                if ($spent + $estimate > $budgetMicro) {
                    return null;
                }

                return AiModelCall::query()->create([
                    'call_date' => $today,
                    'model' => mb_substr($model, 0, 120),
                    'state' => AiModelCall::STATE_RESERVED,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => 0,
                    'cost_micro_usd' => $estimate,
                ]);
            });
        } catch (Throwable $e) {
            Log::error('ai_automation.budget_guard_unavailable', ['exception' => $e::class]);

            return null;
        }
    }

    /** Settle a reserved call with the real token counts. Replaces the worst-case estimate with actual cost. */
    public function complete(AiModelCall $call, int $inputTokens, int $outputTokens, int $latencyMs): AiModelCall
    {
        $inputTokens = max(0, $inputTokens);
        $outputTokens = max(0, $outputTokens);

        $call->forceFill([
            'state' => AiModelCall::STATE_COMPLETED,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_micro_usd' => $this->costMicroUsd($inputTokens, $outputTokens, (string) $call->model),
            'latency_ms' => max(0, $latencyMs),
            'error_class' => null,
        ])->save();

        return $call;
    }

    /**
     * Close a reserved call as failed. Pass $mayHaveBeenBilled when the request reached the provider and may
     * have been processed (timeout, 5xx, unusable 200): the worst-case estimate then stays booked against the
     * month. Refusals and client errors (auth, 4xx, 429) were never processed and release the estimate.
     */
    public function fail(AiModelCall $call, string $errorClass, ?int $latencyMs = null, bool $mayHaveBeenBilled = false): AiModelCall
    {
        $call->forceFill([
            'state' => AiModelCall::STATE_FAILED,
            'error_class' => mb_substr($errorClass, 0, 80),
            'latency_ms' => $latencyMs !== null ? max(0, $latencyMs) : null,
            'cost_micro_usd' => $mayHaveBeenBilled ? (int) $call->cost_micro_usd : 0,
        ])->save();

        return $call;
    }

    /** @return array{today_calls: int, daily_limit: int, month_cost_usd: float, monthly_budget_usd: float} */
    public function usageSummary(): array
    {
        $now = $this->now();

        $spentMicro = (int) AiModelCall::query()
            ->whereDate('call_date', '>=', $now->startOfMonth()->toDateString())
            ->whereDate('call_date', '<=', $now->endOfMonth()->toDateString())
            ->sum('cost_micro_usd');

        return [
            'today_calls' => AiModelCall::query()->whereDate('call_date', $now->toDateString())->count(),
            'daily_limit' => (int) config('ai_automation.model.daily_call_limit', 0),
            'month_cost_usd' => round($spentMicro / 1_000_000, 4),
            'monthly_budget_usd' => (float) config('ai_automation.model.monthly_budget_usd', 0),
        ];
    }

    /** Output tokens the client may request: the configured cap, never above the hard ceiling. */
    public function maxOutputTokens(): int
    {
        $configured = (int) config('ai_automation.model.max_output_tokens', 300);

        return max(1, min($configured, self::OUTPUT_TOKEN_CEILING));
    }

    /**
     * USD per million tokens for a model, from config('ai_automation.model.prices'). Null when the model is not
     * listed (or is listed with a negative or non-numeric price): such a model is refused, never guessed at.
     *
     * @return array{in: float, out: float}|null
     */
    public function priceFor(?string $model = null): ?array
    {
        $model ??= (string) config('ai_automation.model.model', '');
        $entry = config('ai_automation.model.prices', [])[$model] ?? null;
        if (! is_array($entry) || ! is_numeric($entry['in'] ?? null) || ! is_numeric($entry['out'] ?? null)) {
            return null;
        }
        if ((float) $entry['in'] < 0 || (float) $entry['out'] < 0) {
            return null;
        }

        return ['in' => (float) $entry['in'], 'out' => (float) $entry['out']];
    }

    /**
     * Whole micro-USD, rounded up so the meter never undercounts. Priced by the given model (default: the
     * configured one); an unpriced model costs 0 here because it can never be called.
     */
    public function costMicroUsd(int $inputTokens, int $outputTokens, ?string $model = null): int
    {
        $price = $this->priceFor($model);
        if ($price === null) {
            return 0;
        }

        // round() first so float noise (72.00000000000001) never bumps the ceiling by a whole micro-dollar.
        return (int) ceil(round(max(0, $inputTokens) * $price['in'] + max(0, $outputTokens) * $price['out'], 6));
    }

    /** Rough token count (4 bytes per token) used when the provider returns no usage. */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

    private function budgetMicroUsd(): int
    {
        return (int) round(max(0.0, (float) config('ai_automation.model.monthly_budget_usd', 0)) * 1_000_000);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('ai_automation.timezone', 'America/New_York'));
    }
}
