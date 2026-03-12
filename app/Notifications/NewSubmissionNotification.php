<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class NewSubmissionNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $submissionType,
        private string $title,
        private string $body,
        private string $actionUrl = '/admin',
        private string $icon = 'heroicon-o-document-check',
    ) {}

    /**
     * Route to both database (Filament in-app) and web push channels.
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        // Only add WebPush if user has active push subscriptions
        if ($notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * Filament-compatible database notification format.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'icon' => $this->icon,
            'iconColor' => 'info',
            'format' => 'filament',
            'duration' => 'persistent',
            'actions' => [
                [
                    'name' => 'view',
                    'label' => 'View',
                    'url' => $this->actionUrl,
                    'color' => 'primary',
                    'isOutlined' => false,
                ],
            ],
        ];
    }

    /**
     * Web Push notification payload.
     */
    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->tag('mbfd-submission-' . $this->submissionType)
            ->data(['url' => $this->actionUrl]);
    }
}
