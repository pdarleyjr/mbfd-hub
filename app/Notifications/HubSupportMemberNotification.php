<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class HubSupportMemberNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $ticketId, private readonly string $status, private readonly string $message) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->status,
            'body' => $this->message !== '' ? $this->message : 'Your report has an update.',
            'icon' => 'heroicon-o-lifebuoy',
            'iconColor' => 'info',
            'format' => 'filament',
            'duration' => 'persistent',
            'actions' => [[
                'name' => 'view',
                'label' => 'View My Report',
                'url' => '/support/issues/'.$this->ticketId,
                'color' => 'primary',
                'isOutlined' => false,
            ]],
        ];
    }

    public function viaQueues(): array
    {
        return ['database' => 'notifications'];
    }
}
