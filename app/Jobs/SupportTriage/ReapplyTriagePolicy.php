<?php

declare(strict_types=1);

namespace App\Jobs\SupportTriage;

use App\Services\SupportTriage\TriageProjector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Re-checks every open record under the current equipment catalog (after an administrator applies a change).
 * Staff overrides are kept and nothing is sent to anyone. Safe to run twice.
 */
final class ReapplyTriagePolicy implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    // Inside the ai-automation-worker --timeout (80 s) and Redis retry_after (90 s). Re-running is harmless: it only recomputes.
    public int $timeout = 75;

    public function __construct(public readonly int $chunk = 200)
    {
        $this->onQueue((string) config('ai_automation.queue.backlog', 'ai-backlog'));
        $connection = config('ai_automation.queue.connection');
        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }
    }

    public function handle(TriageProjector $projector): void
    {
        $projector->reapply($this->chunk);
    }
}
