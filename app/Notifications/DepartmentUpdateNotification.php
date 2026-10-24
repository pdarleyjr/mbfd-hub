<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\DepartmentUpdatePriority;
use App\Models\DepartmentUpdate;
use App\Models\User;
use App\Notifications\Concerns\EvaluatesDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

final class DepartmentUpdateNotification extends Notification implements ShouldQueue
{
    use EvaluatesDelivery;
    use Queueable;

    public function __construct(
        private readonly int $updateId,
        private readonly string $title,
        private readonly string $excerpt,
        private readonly DepartmentUpdatePriority $priority,
        private readonly bool $sendInApp,
        private readonly bool $sendWebPush,
    ) {
        $this->onQueue('notifications');
    }

    public static function fromUpdate(DepartmentUpdate $update): self
    {
        return new self(
            updateId: $update->id,
            title: $update->title,
            excerpt: $update->excerpt(180),
            priority: $update->priority,
            sendInApp: $update->send_in_app,
            sendWebPush: $update->send_web_push,
        );
    }

    protected function deliveryCategory(): string
    {
        return User::NOTIFICATION_PREFERENCE_DEPARTMENT_UPDATES;
    }

    protected function deliveryEventKey(): string
    {
        return 'department_updates:update:'.$this->updateId.':published';
    }

    /**
     * The publisher decides which channels this notice may use.
     *
     * @return list<string>
     */
    protected function deliveryChannelMask(): array
    {
        return array_values(array_filter([
            $this->sendInApp ? 'database' : null,
            $this->sendWebPush ? 'webpush' : null,
        ]));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->evaluatedChannels($notifiable);
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->excerpt,
            'icon' => 'heroicon-o-megaphone',
            'iconColor' => $this->priority->color(),
            'format' => 'filament',
            'duration' => 'persistent',
            'actions' => [[
                'name' => 'view',
                'label' => 'View update',
                'url' => $this->deliveryUrl($notifiable, route('updates.show', $this->updateId)),
                'color' => $this->priority->color(),
                'isOutlined' => false,
            ]],
            'provenance' => $this->deliveryProvenance($notifiable),
        ];
    }

    public function toWebPush(object $notifiable, mixed $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->excerpt)
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->tag('department-update-'.$this->updateId)
            ->data(['url' => $this->deliveryUrl($notifiable, route('updates.show', $this->updateId))]);
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return [
            'database' => 'notifications',
            WebPushChannel::class => 'notifications',
        ];
    }
}
