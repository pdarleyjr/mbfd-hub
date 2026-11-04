<?php

declare(strict_types=1);

namespace App\Jobs\AiAutomation;

use App\Models\AiAutomation\AiIntakeEvent;
use App\Services\AiAutomation\IntakeProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One live AI Response. Retries are driven by the ledger row (attempts, next_attempt_at), not the queue,
 * so a crashed or OFF-cancelled job is simply inert when it runs.
 */
final class ProcessAiIntake implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 75;

    public function __construct(public readonly int $intakeId) {}

    public static function dispatchFor(AiIntakeEvent $intake, int $delaySeconds = 0): void
    {
        $job = self::dispatch((int) $intake->getKey())
            ->onQueue((string) config('ai_automation.queue.live', 'ai-automation'));

        $connection = config('ai_automation.queue.connection');
        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }
        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }
    }

    public function handle(IntakeProcessor $processor): void
    {
        $state = $processor->process($this->intakeId);

        if ($state === AiIntakeEvent::STATE_RETRY) {
            $intake = AiIntakeEvent::query()->find($this->intakeId);
            if ($intake !== null) {
                self::dispatchFor($intake, (int) config('ai_automation.processing.retry_delay_seconds', 60));
            }
        }
    }
}
