<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ApparatusServiceTicketEmployeeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $ticketNumber,
        private readonly string $unit,
        private readonly string $status,
        private readonly ?string $publicNote,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $statusLabel = str($this->status)->replace('_', ' ')->title()->toString();

        return [
            'title' => "{$this->ticketNumber} updated",
            'body' => trim("{$this->unit}: {$statusLabel}. ".($this->publicNote ?? '')),
            'icon' => 'heroicon-o-wrench-screwdriver',
            'iconColor' => $this->status === 'completed' ? 'success' : 'info',
            'format' => 'filament',
            'duration' => 'persistent',
            'actions' => [],
        ];
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return ['database' => 'notifications'];
    }
}
