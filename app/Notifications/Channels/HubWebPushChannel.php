<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Jobs\DeliverDeferredPush;
use App\Models\HubPushSubscription;
use App\Models\User;
use App\Services\Notifications\PushDeliveryTrace;
use App\Services\Notifications\PushDeviceHealth;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use LogicException;
use Minishlink\WebPush\Subscription;
use NotificationChannels\WebPush\DeclarativeWebPushMessage;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The package's push channel with one change: a device that is waiting out a short
 * back-off (429, 5xx or no answer from its push service) is not skipped and forgotten.
 * The alert goes to the healthy devices now, and to each waiting device as soon as its
 * back-off ends (DeliverDeferredPush), so a busy push service delays a phone but never
 * drops an alert. A device whose push attempt is refused with a retryable status during
 * this very send is handled the same way.
 *
 * Notifications that choose their own devices (pushDevicesFor, the "send a test" button)
 * keep the package behaviour: exactly those devices, back-off ignored, no re-sending.
 *
 * Every attempt (and every skip of a device that is waiting) writes one privacy-preserving log line
 * (PushDeliveryTrace) with a correlation id that follows the alert into its deferred retries.
 */
class HubWebPushChannel extends WebPushChannel
{
    /** Re-send attempts per device before the alert is given up as stale. */
    public const MAX_DEFERRALS = 6;

    /** Build from the package's own channel so its VAPID client and report handler are reused as configured. */
    public static function decorate(WebPushChannel $channel): self
    {
        [$webPush, $reportHandler] = Closure::bind(
            fn (): array => [$this->webPush, $this->reportHandler],
            $channel,
            WebPushChannel::class,
        )();

        return new self($webPush, $reportHandler);
    }

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User) {
            parent::send($notifiable, $notification);

            return;
        }

        $correlationId = PushDeliveryTrace::correlationIdFor($notification);

        if (method_exists($notification, 'pushDevicesFor')) {
            $this->deliverTo($notifiable, $notification, $notifiable->routeNotificationFor('WebPush', $notification), 1, $correlationId);

            return;
        }

        $devices = $notifiable->pushSubscriptions()->get();
        if ($devices->isEmpty()) {
            return;
        }

        $waiting = $devices->filter(fn (HubPushSubscription $device): bool => $device->backoff_until?->isFuture() === true);
        $healthy = $devices->reject(fn (HubPushSubscription $device): bool => $device->backoff_until?->isFuture() === true);

        $retry = $waiting;
        foreach ($waiting as $device) {
            PushDeliveryTrace::record($notification, $notifiable, $device, $correlationId, 1, null, 0);
        }
        if ($healthy->isNotEmpty()) {
            $this->deliverTo($notifiable, $notification, $healthy->values(), 1, $correlationId);
            $retry = $retry->merge($this->refusedRetryably($healthy->modelKeys()));
        }

        foreach ($retry as $device) {
            $this->defer($notifiable, $notification, $device, 1, $correlationId);
        }
    }

    /**
     * Send to exactly these devices now (no back-off handling).
     *
     * @param  Collection<int, HubPushSubscription>  $subscriptions
     * @param  int  $attempt  1 at the original send; n + 1 for the nth deferred retry (trace only).
     */
    public function deliverTo(mixed $notifiable, Notification $notification, Collection $subscriptions, int $attempt = 1, ?string $correlationId = null): void
    {
        if ($subscriptions->isEmpty()) {
            return;
        }

        if (! method_exists($notification, 'toWebPush')) {
            throw new LogicException($notification::class.' cannot be sent over web push: it has no toWebPush().');
        }

        $message = $notification->toWebPush($notifiable, $notification);
        if (! $message instanceof WebPushMessage && ! $message instanceof DeclarativeWebPushMessage) {
            throw new LogicException($notification::class.'::toWebPush() must return a web push message.');
        }

        $payload = json_encode($message->toArray());
        $options = $message->getOptions();
        $correlationId ??= PushDeliveryTrace::correlationIdFor($notification);
        $startedAt = hrtime(true);

        foreach ($subscriptions as $subscription) {
            $this->webPush->queueNotification(new Subscription(
                $subscription->endpoint,
                $subscription->public_key,
                $subscription->auth_token,
                $subscription->content_encoding
            ), $payload, $options);
        }

        // The package's handleReports() is typed to its own subscription model; the Hub's devices are
        // HubPushSubscription, so each report is matched to its device here and handed to the same handler.
        foreach ($this->webPush->flush() as $report) {
            $device = $subscriptions->first(fn (HubPushSubscription $candidate): bool => $candidate->endpoint === $report->getEndpoint());

            if ($device instanceof HubPushSubscription) {
                // Traced before the report is handled: a 404/410 deletes the device, and nothing that
                // goes wrong while recording may hide the attempt. Time is measured to this answer.
                if ($notifiable instanceof User) {
                    PushDeliveryTrace::record($notification, $notifiable, $device, $correlationId, $attempt, $report, (int) round((hrtime(true) - $startedAt) / 1_000_000));
                }
                $this->reportHandler->handleReport($report, $device, $message);
            }
        }
    }

    /** Re-send to one device when its back-off ends. */
    public function defer(User $user, Notification $notification, HubPushSubscription $device, int $attempt, ?string $correlationId = null): void
    {
        $correlationId ??= PushDeliveryTrace::correlationIdFor($notification);

        if ($attempt > self::MAX_DEFERRALS) {
            Log::warning('Push alert given up after repeated retryable refusals', [
                'correlation_id' => $correlationId,
                'user_id' => $user->getKey(),
                'subscription_id' => $device->getKey(),
                'notification' => $notification::class,
            ]);

            return;
        }

        $job = DeliverDeferredPush::dispatch($notification, (int) $user->getKey(), (int) $device->getKey(), $attempt, $correlationId);
        if ($device->backoff_until !== null) {
            $job->delay($device->backoff_until);
        }
    }

    /**
     * Devices that were healthy when this send began and are in back-off now, because their push
     * service answered 429, 5xx or nothing to this very alert.
     *
     * @param  list<int|string>  $ids
     * @return Collection<int, HubPushSubscription>
     */
    private function refusedRetryably(array $ids): Collection
    {
        return HubPushSubscription::query()
            ->whereIn('id', $ids)
            ->tap(fn ($query) => PushDeviceHealth::scopeBackedOff($query))
            ->get();
    }
}
