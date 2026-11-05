<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

use App\Jobs\AiAutomation\ProcessAiIntake;
use App\Models\AiAutomation\AiIntakeEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only call domain code makes: "this submission was saved". It never throws and never slows the
 * member's save. When the master switch is OFF it does nothing at all, so submissions made while OFF are
 * only reachable later through the explicit backlog button.
 */
final class AiIntake
{
    public function __construct(
        private readonly AiAutomationSwitch $switch,
        private readonly DomainRegistry $domains,
    ) {}

    public static function record(string $sourceType, int $sourceId, string $eventKey = 'received'): void
    {
        try {
            app(self::class)->enqueue($sourceType, $sourceId, $eventKey);
        } catch (Throwable $exception) {
            Log::warning('AI automation intake could not be recorded.', [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'error' => $exception::class,
            ]);
        }
    }

    public function enqueue(string $sourceType, int $sourceId, string $eventKey = 'received'): void
    {
        // Runs after the domain transaction commits (immediately when there is none).
        DB::afterCommit(function () use ($sourceType, $sourceId, $eventKey): void {
            try {
                $intake = $this->createLiveRow($sourceType, $sourceId, $eventKey);
                if ($intake !== null) {
                    ProcessAiIntake::dispatchFor($intake);
                }
            } catch (Throwable $exception) {
                Log::warning('AI automation intake could not be queued.', [
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'error' => $exception::class,
                ]);
            }
        });
    }

    private function createLiveRow(string $sourceType, int $sourceId, string $eventKey): ?AiIntakeEvent
    {
        $adapter = $this->domains->forSourceType($sourceType);
        if ($adapter === null || ! $this->domains->domainEnabled($adapter->domain())) {
            return null;
        }
        $epoch = $this->switch->currentEpochIfOn();
        if ($epoch === null) {
            return null;
        }

        $inserted = AiIntakeEvent::query()->insertOrIgnore([
            'domain' => $adapter->domain(),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'event_key' => $eventKey,
            'origin' => AiIntakeEvent::ORIGIN_LIVE,
            'epoch' => $epoch,
            'state' => AiIntakeEvent::STATE_QUEUED,
            'needs_human' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return null; // already recorded by an earlier hook, replay or backlog pass
        }

        return AiIntakeEvent::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('event_key', $eventKey)
            ->first();
    }
}
