<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class TestPushNotification extends Notification
{
    public function via($notifiable)
    {
        return [WebPushChannel::class];
    }

    public function toWebPush($notifiable, $notification)
    {
        return (new WebPushMessage)
            ->title('MBFD Hub Test Notification')
            ->body('This is a test notification from MBFD Hub. Push notifications are working correctly!')
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->data(['url' => '/']);
    }
}
