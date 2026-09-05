<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\DepartmentUpdatePriority;
use App\Models\DepartmentUpdate;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

final class DepartmentUpdateNotification extends Notification implements ShouldQueue
{
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

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }

        $subscription = $notifiable->notificationSubscriptions()
            ->where('event_key', User::NOTIFICATION_PREFERENCE_DEPARTMENT_UPDATES)
            ->first();
        if ($subscription === null) {
            return [];
        }

        $channels = [];

        if ($this->sendInApp && $subscription->database_enabled) {
            $channels[] = 'database';
        }

        if ($this->sendWebPush && $subscription->webpush_enabled && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
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
                'url' => route('updates.show', $this->updateId),
                'color' => $this->priority->color(),
                'isOutlined' => false,
            ]],
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
            ->data(['url' => route('updates.show', $this->updateId)]);
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
