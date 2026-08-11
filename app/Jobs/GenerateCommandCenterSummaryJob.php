<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\CloudflareAIService;
use App\Services\CommandCenterAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Regenerates the Command Center AI brief in the background — dispatched by
 * CommandCenterAiService::ensureFresh() ONLY when the operational-data
 * fingerprint changed. Running async keeps the dashboard responsive and only
 * loads the local LLM (qwen3.6) when there is genuinely new info to summarize.
 */
class GenerateCommandCenterSummaryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 180; // allow for a cold model load + generation

    public int $backoff = 15;

    public function __construct(public string $expectedFingerprint) {}

    public function handle(CommandCenterAiService $cc): void
    {
        try {
            $metrics = $cc->gatherMetrics();
            $fp = $cc->fingerprint($metrics);

            // Another job may have already produced this exact state.
            $cached = $cc->cachedSummary();
            if ($cached && ($cached['fp'] ?? null) === $fp) {
                return;
            }

            $ai = app(CloudflareAIService::class)->generateAdminBulletSummary($metrics);

            Cache::put(
                CommandCenterAiService::CACHE_KEY,
                ['fp' => $fp, 'summary' => $ai, 'at' => now()->toISOString()],
                CommandCenterAiService::CACHE_TTL,
            );
        } catch (\Throwable $e) {
            // Leave the previous (stale) summary in place; just log.
            Log::warning('[CommandCenter] AI brief generation failed', ['error' => $e->getMessage()]);
        } finally {
            Cache::forget(CommandCenterAiService::PENDING_KEY);
        }
    }
}
