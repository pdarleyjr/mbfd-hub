<?php

declare(strict_types=1);

namespace App\Console\Commands\AiAutomation;

use App\Jobs\AiAutomation\ProcessAiBacklogBatch;
use App\Jobs\AiAutomation\ProcessAiIntake;
use App\Models\AiAutomation\AiBacklogRun;
use App\Models\AiAutomation\AiIntakeEvent;
use App\Services\AiAutomation\AiAutomationSwitch;
use Illuminate\Console\Command;

/**
 * Safety net only: re-queues retries, rows whose job was lost and stalled backlog runs. Never sends
 * anything itself and does nothing at all while the master switch is OFF.
 */
final class SweepAiAutomation extends Command
{
    protected $signature = 'ai-automation:sweep';

    protected $description = 'Re-queue stalled AI Response work (no-op while AI automations are OFF).';

    public function handle(AiAutomationSwitch $switch): int
    {
        if (! $switch->isOn()) {
            return self::SUCCESS;
        }

        $epoch = $switch->epoch();

        // Anything left open from an earlier epoch is inert; close it out.
        AiIntakeEvent::query()
            ->whereIn('state', AiIntakeEvent::OPEN_STATES)
            ->where('epoch', '!=', $epoch)
            ->update(['state' => AiIntakeEvent::STATE_CANCELLED, 'last_error' => 'stale_epoch', 'updated_at' => now()]);

        $due = AiIntakeEvent::query()
            ->where('epoch', $epoch)
            ->where(function ($query): void {
                $query->where(function ($retry): void {
                    $retry->where('state', AiIntakeEvent::STATE_RETRY)->where('next_attempt_at', '<=', now());
                })->orWhere(function ($lost): void {
                    $lost->where('state', AiIntakeEvent::STATE_QUEUED)->where('updated_at', '<', now()->subMinutes(5));
                })->orWhere(function ($stuck): void {
                    $stuck->where('state', AiIntakeEvent::STATE_CLAIMED)->where('lease_expires_at', '<', now());
                });
            })
            ->orderBy('id')
            ->limit(100)
            ->get();

        foreach ($due as $intake) {
            ProcessAiIntake::dispatchFor($intake);
        }

        $stalled = AiBacklogRun::query()
            ->where('state', AiBacklogRun::STATE_RUNNING)
            ->where('epoch', $epoch)
            ->where('updated_at', '<', now()->subMinutes(10))
            ->get();
        foreach ($stalled as $run) {
            ProcessAiBacklogBatch::dispatchFor($run, $epoch);
        }

        if ($due->isNotEmpty() || $stalled->isNotEmpty()) {
            $this->info("Re-queued {$due->count()} responses and {$stalled->count()} backlog runs.");
        }

        return self::SUCCESS;
    }
}
