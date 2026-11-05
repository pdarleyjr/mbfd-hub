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

/** The read-only backlog count. Sends nothing; runs whether AI automations are ON or OFF. */
final class PreviewAiBacklog implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 75;

    public function __construct(public readonly int $runId) {}

    public static function dispatchFor(AiBacklogRun $run): void
    {
        $job = self::dispatch((int) $run->getKey())
            ->onQueue((string) config('ai_automation.queue.backlog', 'ai-backlog'));

        $connection = config('ai_automation.queue.connection');
        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }
    }

    public function handle(BacklogService $backlog): void
    {
        $run = AiBacklogRun::query()->find($this->runId);
        if ($run !== null) {
            $backlog->fillPreview($run);
        }
    }
}
