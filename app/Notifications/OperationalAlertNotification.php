<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Concerns\EvaluatesDelivery;
use App\Services\Notifications\NotificationCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * An in-app operational alert (a project that is overdue, equipment about to expire, a to-do assigned to you)
 * for a registry category. Who may receive it is the evaluator's answer for that category now, both when it
 * is queued and when the worker delivers it, and the delivery records why ("provenance") like every other
 * alert. In-app only.
 */
final class OperationalAlertNotification extends Notification implements ShouldQueue
{
    use EvaluatesDelivery;
    use Queueable;

    public function __construct(
        private readonly string $category,
        private readonly string $title,
        private readonly string $body,
        private readonly string $url,
        private readonly string $icon = 'heroicon-o-bell',
        private readonly string $iconColor = 'info',
        private readonly string $actionLabel = 'View',
        private readonly ?string $eventKey = null,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    protected function deliveryCategory(): string
    {
        return $this->category;
    }

    protected function deliveryEventKey(): ?string
    {
        return $this->eventKey;
    }

    /**
     * In-app only, whatever the category offers: this alert has no push or email form, and its categories may
     * default those channels on for the other alerts they carry.
     *
     * @return list<string>
     */
    protected function deliveryChannelMask(): array
    {
        return [NotificationCategory::CHANNEL_DATABASE];
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->evaluatedChannels($notifiable);
    }

    /** @return array<string, mixed> Filament-compatible database notification. */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'icon' => $this->icon,
            'iconColor' => $this->iconColor,
            'format' => 'filament',
            'duration' => 'persistent',
            'actions' => [[
                'name' => 'view',
                'label' => $this->actionLabel,
                'url' => $this->deliveryUrl($notifiable, $this->url),
                'color' => 'primary',
                'isOutlined' => false,
            ]],
            'provenance' => $this->deliveryProvenance($notifiable),
        ];
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return ['database' => 'notifications'];
    }
}
