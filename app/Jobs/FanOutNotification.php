<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Works out who a category's alert goes to (live eligibility, one person at a time) and hands it to each
 * of them, off the request that triggered it. A workflow write never waits for, or fails because of,
 * this: the write has already committed when it is queued (NotificationDispatchService::dispatch).
 */
final class FanOutNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 60];

    /** @param  list<int>|null  $candidateIds  Who to evaluate; null evaluates the category's own audience. */
    public function __construct(
        public readonly string $categoryKey,
        public readonly Notification $notification,
        public readonly ?array $candidateIds = null,
        public readonly ?string $eventKey = null,
        public readonly ?int $exceptUserId = null,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(NotificationDispatchService $dispatcher): void
    {
        $dispatcher->sendToIds($this->categoryKey, $this->notification, $this->candidateIds, $this->eventKey, $this->exceptUserId);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Notification fan-out failed', [
            'category' => $this->categoryKey,
            'notification' => $this->notification::class,
            'event_key' => $this->eventKey,
            'exception' => $exception->getMessage(),
        ]);
    }
}
