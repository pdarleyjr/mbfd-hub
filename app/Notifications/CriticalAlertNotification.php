<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;
use NotificationChannels\WebPush\WebPushChannel;

class CriticalAlertNotification extends Notification
{
    use Queueable;

    protected string $title;
    protected string $message;
    protected ?int $alertId;
    protected ?string $url;
    protected string $alertType;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $title, string $message, string $alertType = 'general', ?int $alertId = null, ?string $url = null)
    {
        $this->title = $title;
        $this->message = $message;
        $this->alertType = $alertType;
        $this->alertId = $alertId;
        $this->url = $url;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    /**
     * Get the web push representation of the notification.
     */
    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        $actionUrl = $this->url ?? ($this->alertId ? url('/admin/alerts/' . $this->alertId) : url('/admin'));

        return (new WebPushMessage)
            ->title($this->title)
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->body($this->message)
            ->action('View Alert', 'view_alert')
            ->tag($this->alertType . '-' . ($this->alertId ?? time()))
            ->data([
                'url' => $actionUrl,
                'alertType' => $this->alertType,
                'alertId' => $this->alertId,
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'alertType' => $this->alertType,
            'alertId' => $this->alertId,
            'url' => $this->url,
        ];
    }
}
