<?php

namespace App\Notifications;

use App\Notifications\Concerns\EvaluatesDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class CriticalAlertNotification extends Notification
{
    use EvaluatesDelivery;
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
        return $this->evaluatedChannels($notifiable);
    }

    protected function deliveryCategory(): string
    {
        return 'system_alerts';
    }

    /**
     * This notification only has a push representation; the in-app copy is a separate Filament notice.
     *
     * @return list<string>
     */
    protected function deliveryChannelMask(): array
    {
        return ['webpush'];
    }

    /**
     * Get the web push representation of the notification.
     */
    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        $actionUrl = $this->url ?? ($this->alertId ? url('/admin/alerts/'.$this->alertId) : url('/admin'));

        return (new WebPushMessage)
            ->title($this->title)
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->body($this->message)
            ->action('View Alert', 'view_alert')
            ->tag($this->alertType.'-'.($this->alertId ?? time()))
            ->data([
                'url' => $this->deliveryUrl($notifiable, $actionUrl),
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
