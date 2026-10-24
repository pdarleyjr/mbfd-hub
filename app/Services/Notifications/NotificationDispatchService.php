<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Jobs\FanOutNotification;
use App\Models\User;
use App\Models\UserNotificationSubscription;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Throwable;

/**
 * Sends one notification to the people who are eligible for its category.
 *
 * Recipients are never taken from saved preferences. When an event key is
 * given, each person is told about that event once (retries and double
 * triggers are collapsed); events that can legitimately repeat pass no key,
 * or a key that names the repeat (assignment, revision, status change).
 *
 * Workflow code calls dispatch(): it queues the fan-out after the surrounding
 * transaction commits, and nothing that goes wrong there can fail or roll back
 * the write that triggered it. send() is the synchronous form the queued job
 * runs (and commands that already run in the background use).
 */
final class NotificationDispatchService
{
    private const IDEMPOTENCY_TTL_HOURS = 24;

    public function __construct(private readonly NotificationRecipientEvaluator $evaluator) {}

    /**
     * Fire-and-forget for workflow hooks. Runs after the open transaction commits, on the 'notifications'
     * queue, and reports (never throws) any failure, so a notification problem cannot touch the write.
     *
     * @param  iterable<User>|null  $candidates  Who to evaluate; null evaluates the category's own audience.
     */
    public function dispatch(string $categoryKey, Notification $notification, ?iterable $candidates = null, ?string $eventKey = null, ?int $exceptUserId = null): void
    {
        try {
            $ids = $candidates === null ? null : collect($candidates)
                ->map(fn (User $user): int => (int) $user->getKey())
                ->unique()
                ->values()
                ->all();
            $job = new FanOutNotification($categoryKey, $notification, $ids, $eventKey, $exceptUserId);

            DB::afterCommit(static function () use ($job): void {
                try {
                    dispatch($job);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  list<int>|null  $candidateIds
     * @return Collection<int, User>
     */
    public function sendToIds(string $categoryKey, Notification $notification, ?array $candidateIds, ?string $eventKey = null, ?int $exceptUserId = null): Collection
    {
        $candidates = $candidateIds === null ? null : User::query()->whereIn('id', $candidateIds)->get();

        return $this->send($categoryKey, $notification, $candidates, $eventKey, $exceptUserId);
    }

    /**
     * @param  iterable<User>|null  $candidates  Who to evaluate; null evaluates the category's own audience.
     * @return Collection<int, User> The people the notification was handed to.
     */
    public function send(string $categoryKey, Notification $notification, ?iterable $candidates = null, ?string $eventKey = null, ?int $exceptUserId = null): Collection
    {
        $recipients = $this->evaluator->recipients($categoryKey, $candidates)
            ->reject(fn (User $user): bool => $exceptUserId !== null && (int) $user->getKey() === $exceptUserId)
            ->values();

        // Support Services reviewers get their default personnel-request choices recorded the first time they are told.
        if (in_array($categoryKey, [User::NOTIFICATION_PREFERENCE_UNIFORM_REQUESTS, User::NOTIFICATION_PREFERENCE_PERSONNEL_EQUIPMENT_REQUESTS], true)) {
            foreach ($recipients as $recipient) {
                UserNotificationSubscription::ensurePersonnelRequestsForUser($recipient);
            }
        }

        $claimed = [];
        if ($eventKey !== null) {
            $recipients = $recipients->filter(function (User $user) use ($categoryKey, $eventKey, &$claimed): bool {
                $key = self::claimKey($categoryKey, $eventKey, (int) $user->getKey());
                if (! Cache::add($key, true, now()->addHours(self::IDEMPOTENCY_TTL_HOURS))) {
                    return false;
                }
                $claimed[] = $key;

                return true;
            })->values();
        }

        if ($recipients->isEmpty()) {
            return $recipients;
        }

        try {
            NotificationFacade::send($recipients, $notification);
        } catch (Throwable $exception) {
            foreach ($claimed as $key) {
                Cache::forget($key);
            }

            throw $exception;
        }

        return $recipients;
    }

    /**
     * Hand one alert to one person when they are eligible for the category right now (for scheduled commands that
     * keep their own per-person cooldowns). Returns whether the alert was handed over.
     */
    public function notifyUser(string $categoryKey, User $user, Notification $notification): bool
    {
        if ($this->evaluator->recipients($categoryKey, [$user])->isEmpty()) {
            return false;
        }

        $user->notify($notification);

        return true;
    }

    /** Stable idempotency key: category, subject and event. */
    public static function eventKey(string $categoryKey, string $subjectType, int|string $subjectId, string $event): string
    {
        return $categoryKey.':'.$subjectType.':'.$subjectId.':'.$event;
    }

    private static function claimKey(string $categoryKey, string $eventKey, int $userId): string
    {
        return 'notification-event:'.sha1($categoryKey.'|'.$eventKey).':'.$userId;
    }
}
