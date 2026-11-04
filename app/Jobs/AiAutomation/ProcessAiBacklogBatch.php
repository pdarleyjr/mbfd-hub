<?php

declare(strict_types=1);

namespace App\Jobs\AiAutomation;

use App\Models\AiAutomation\AiBacklogRun;
use App\Services\AiAutomation\BacklogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** One small, throttled backlog batch. Chains itself until done, paused, or the switch epoch changes. */
final class ProcessAiBacklogBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 75;

    public function __construct(
        public readonly int $runId,
        public readonly int $epoch,
    ) {}

    public static function dispatchFor(AiBacklogRun $run, int $epoch, int $delaySeconds = 0): void
    {
        $job = self::dispatch((int) $run->getKey(), $epoch)
            ->onQueue((string) config('ai_automation.queue.backlog', 'ai-backlog'));

        $connection = config('ai_automation.queue.connection');
        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }
        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }
    }

    public function handle(BacklogService $backlog): void
    {
        if ($backlog->processBatch($this->runId, $this->epoch)) {
            $run = AiBacklogRun::query()->find($this->runId);
            if ($run !== null) {
                self::dispatchFor($run, $this->epoch, (int) config('ai_automation.backlog.batch_delay_seconds', 20));
            }
        }
    }
}
