<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Jobs\FanOutNotification;
use App\Models\NotificationDispatchRetry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Holds alerts the queue would not take, in the database, and offers them again every minute. The queue is Redis, so
 * it can be down while the database is up; without this, an alert raised during an outage would be gone. An event key
 * collapses a repeat when the alert is handed over (NotificationDispatchService), so offering one twice is safe.
 */
final class NotificationDispatchRetries
{
    /** Long enough that a write-ahead row is only retried when the normal hand-over after commit did not happen. */
    public const WRITE_AHEAD_GRACE_SECONDS = 120;

    private const RETRY_DELAY_SECONDS = 60;

    /** An alert older than this is news to nobody. Reported, then dropped. */
    private const MAX_AGE_HOURS = 24;

    private const BATCH = 100;

    /** @return int|null The row, or null when it could not be recorded. That is reported and never fails the caller's write. */
    public function hold(FanOutNotification $job, int $delaySeconds = 0): ?int
    {
        try {
            // Its own savepoint: on PostgreSQL a failed insert would otherwise abort the caller's whole transaction.
            return DB::transaction(static fn (): int => (int) NotificationDispatchRetry::query()->create([
                'payload' => serialize(clone $job),
                'attempts' => 0,
                'available_at' => CarbonImmutable::now()->addSeconds($delaySeconds),
            ])->getKey());
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function release(int $id): void
    {
        try {
            NotificationDispatchRetry::query()->whereKey($id)->delete();
        } catch (Throwable $exception) {
            // The row stays and is offered once more; its event key collapses the repeat.
            report($exception);
        }
    }

    /** @return int How many were handed to the queue */
    public function retryDue(): int
    {
        $now = CarbonImmutable::now();
        $due = NotificationDispatchRetry::query()->where('available_at', '<=', $now)->orderBy('id')->limit(self::BATCH)->get();

        $sent = 0;
        foreach ($due as $row) {
            if ($row->created_at !== null && $row->created_at->lessThan($now->subHours(self::MAX_AGE_HOURS))) {
                report(new RuntimeException('A notification could not be queued for 24 hours and was dropped.'));
                $row->delete();

                continue;
            }

            $job = $this->jobFrom($row);
            if ($job === null) {
                $row->delete();

                continue;
            }

            try {
                dispatch($job);
            } catch (Throwable $exception) {
                report($exception);
                $row->forceFill(['attempts' => $row->attempts + 1, 'available_at' => $now->addSeconds(self::RETRY_DELAY_SECONDS)])->save();

                return $sent; // The queue is still down: the rest would only fail the same way.
            }

            $row->delete();
            $sent++;
        }

        return $sent;
    }

    /** A row that cannot be read back (the app key changed, the class was removed) can never be sent. */
    private function jobFrom(NotificationDispatchRetry $row): ?FanOutNotification
    {
        try {
            $job = unserialize($row->payload);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! $job instanceof FanOutNotification) {
            report(new RuntimeException('A held notification could not be read back and was dropped.'));

            return null;
        }

        return $job;
    }
}
