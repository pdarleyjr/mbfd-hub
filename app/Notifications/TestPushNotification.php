<?php

namespace App\Notifications;

use App\Models\HubPushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "Send a test notification": goes only to the requesting member's own push
 * devices (all of them, or just the endpoints named), ignoring back-off so a
 * member can check a device on purpose. It carries no category or preference.
 */
class TestPushNotification extends Notification
{
    /** @param  list<string>|null  $endpoints  Limit the test to these of the member's own devices. */
    public function __construct(private readonly ?array $endpoints = null) {}

    public function via($notifiable)
    {
        return [WebPushChannel::class];
    }

    /**
     * The member's own devices, never anyone else's.
     *
     * @return Collection<int, HubPushSubscription>
     */
    public function pushDevicesFor(User $user): Collection
    {
        return $user->pushSubscriptions()
            ->when($this->endpoints !== null, fn ($query) => $query->whereIn('endpoint', $this->endpoints))
            ->get();
    }

    public function toWebPush($notifiable, $notification)
    {
        return (new WebPushMessage)
            ->title('MBFD Hub test notification')
            ->body('This device can receive MBFD Hub alerts.')
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->tag('mbfd-test-notification')
            ->data(['url' => '/']);
    }
}
