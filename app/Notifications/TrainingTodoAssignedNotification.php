<?php

namespace App\Notifications;

use App\Notifications\Concerns\EvaluatesDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class TrainingTodoAssignedNotification extends Notification implements ShouldQueue
{
    use EvaluatesDelivery;
    use Queueable;

    public function __construct(
        private readonly int $todoId,
        private readonly string $title,
        private readonly string $priority,
        private readonly string $actionUrl,
    ) {}

    protected function deliveryCategory(): string
    {
        return 'training_assignments';
    }

    protected function deliveryEventKey(): string
    {
        return 'training_assignments:todo:'.$this->todoId.':assigned';
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->evaluatedChannels($notifiable);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'New Training Todo',
            'body' => $this->body(),
            'icon' => 'heroicon-o-clipboard-document-list',
            'iconColor' => $this->iconColor(),
            'format' => 'filament',
            'duration' => 'persistent',
            'actions' => [
                [
                    'name' => 'view',
                    'label' => 'View',
                    'url' => $this->deliveryUrl($notifiable, $this->actionUrl),
                    'color' => 'primary',
                    'isOutlined' => false,
                ],
            ],
            'provenance' => $this->deliveryProvenance($notifiable),
        ];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('New Training Todo')
            ->body($this->body())
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->tag('training-todo-'.$this->todoId)
            ->data(['url' => $this->deliveryUrl($notifiable, $this->actionUrl)]);
    }

    public function viaQueues(): array
    {
        return [
            'database' => 'notifications',
            WebPushChannel::class => 'notifications',
        ];
    }

    private function body(): string
    {
        return sprintf('[%s] %s', ucfirst($this->priority), $this->title);
    }

    private function iconColor(): string
    {
        return match ($this->priority) {
            'urgent' => 'danger',
            'high' => 'warning',
            'medium' => 'info',
            default => 'gray',
        };
    }
}
