<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class StationSupplyRequestStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $stationId,
        private readonly string $status,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $label = str($this->status)->replace('_', ' ')->title()->toString();

        return [
            'title' => 'Station supply request '.$label,
            'body' => 'Your station supply request is now '.$label.'.',
            'icon' => 'heroicon-o-archive-box',
            'iconColor' => $this->status === 'replenished' ? 'success' : 'info',
            'format' => 'filament',
            'duration' => 'persistent',
            'actions' => [[
                'name' => 'view',
                'label' => 'View Station',
                'url' => '/daily/stations/'.$this->stationId,
                'color' => 'primary',
                'isOutlined' => false,
            ]],
        ];
    }
}
