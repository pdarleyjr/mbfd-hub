<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use Throwable;

class NewSubmissionNotification extends Notification implements ShouldQueue
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
        $pushSubscriptionCount = $notifiable->pushSubscriptions()->count();

        Log::info('Preparing submission notification delivery', [
            'notification' => static::class,
            'submission_type' => $this->submissionType,
            'notifiable_id' => $notifiable->id ?? null,
            'notifiable_email' => $notifiable->email ?? null,
            'push_subscription_count' => $pushSubscriptionCount,
        ]);

        // Only add WebPush if user has active push subscriptions
        if ($pushSubscriptionCount > 0) {
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
        Log::info('Building WebPush payload for submission notification', [
            'notification' => static::class,
            'submission_type' => $this->submissionType,
            'notifiable_id' => $notifiable->id ?? null,
            'notifiable_email' => $notifiable->email ?? null,
            'action_url' => $this->actionUrl,
            'vapid_subject' => config('webpush.vapid.subject'),
            'vapid_public_key_present' => filled(config('webpush.vapid.public_key')),
            'vapid_private_key_present' => filled(config('webpush.vapid.private_key')),
        ]);

        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->tag('mbfd-submission-' . $this->submissionType)
            ->data(['url' => $this->actionUrl]);
    }

    public function viaQueues(): array
    {
        return [
            'database' => 'notifications',
            WebPushChannel::class => 'notifications',
        ];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Submission notification delivery failed', [
            'notification' => static::class,
            'submission_type' => $this->submissionType,
            'title' => $this->title,
            'action_url' => $this->actionUrl,
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'vapid_subject' => config('webpush.vapid.subject'),
            'vapid_public_key_present' => filled(config('webpush.vapid.public_key')),
            'vapid_private_key_present' => filled(config('webpush.vapid.private_key')),
        ]);
    }
}
