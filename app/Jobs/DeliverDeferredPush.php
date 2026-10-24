<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Notifications\Channels\HubWebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Re-sends one alert to one push device that was waiting out a back-off (see HubWebPushChannel).
 *
 * The member is evaluated again first, so a role revoked, a push preference switched off or a
 * device removed (sign-out) while the alert waited stops it. If the device is still in back-off
 * (or is refused again with a retryable status) the alert waits for the next window, a bounded
 * number of times; the in-app copy was delivered long before this job ran.
 */
final class DeliverDeferredPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly Notification $notification,
        public readonly int $userId,
        public readonly int $subscriptionId,
        public readonly int $attempt = 1,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $user = User::query()->find($this->userId);
        $device = $user instanceof User ? $user->pushSubscriptions()->find($this->subscriptionId) : null;
        if (! $user instanceof User || $device === null) {
            return;
        }

        if (method_exists($this->notification, 'shouldSend') && ! $this->notification->shouldSend($user, WebPushChannel::class)) {
            return;
        }

        // The container already hands out the Hub channel (NotificationServiceProvider); decorating keeps the
        // job correct even if something rebinds the package channel.
        $channel = HubWebPushChannel::decorate(app(WebPushChannel::class));

        if ($device->backoff_until?->isFuture() === true) {
            // The sync driver has no "later": the job ran at once, so waiting again would only recurse.
            if (! $this->job instanceof SyncJob) {
                $channel->defer($user, $this->notification, $device, $this->attempt + 1);
            }

            return;
        }

        $channel->deliverTo($user, $this->notification, $user->pushSubscriptions()->whereKey($device->getKey())->get());

        $device->refresh();
        if ($device->backoff_until?->isFuture() === true && ! $this->job instanceof SyncJob) {
            $channel->defer($user, $this->notification, $device, $this->attempt + 1);
        }
    }
}
