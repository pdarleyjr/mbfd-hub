<?php

declare(strict_types=1);

namespace App\Notifications\Ems;

use App\Notifications\Channels\BudgetedMailChannel;
use App\Notifications\Concerns\EvaluatesDelivery;
use App\Services\Notifications\NotificationCategoryRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use Throwable;

/** The supply room screen has not checked in for 15 minutes. Sent once to the EMS supply alert list until it is back. */
class KioskScreenOfflineNotification extends Notification implements ShouldQueue
{
    use EvaluatesDelivery;
    use Queueable;

    public function __construct(
        public readonly string $screenName,
        public readonly string $lastSeen,
        private readonly string $eventKey,
    ) {}

    protected function deliveryCategory(): ?string
    {
        return NotificationCategoryRegistry::EMS_LOW_STOCK;
    }

    protected function deliveryEventKey(): ?string
    {
        return $this->eventKey;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->evaluatedChannels($notifiable);
    }

    public function title(): string
    {
        return "{$this->screenName} is offline";
    }

    public function body(): string
    {
        return "The EMS supply room screen has not checked in since {$this->lastSeen}. Members cannot take supplies on it until it is back. Check its power and Wi-Fi.";
    }

    /** @return array{subject: string, text: string, html: string} */
    public function toBudgetedEmail(object $notifiable): array
    {
        return ['subject' => $this->title(), 'text' => $this->body(), 'html' => '<h1>'.e($this->title()).'</h1><p>'.e($this->body()).'</p>'];
    }

    /** Filament-compatible in-app notification. */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title(), 'body' => $this->body(), 'icon' => 'heroicon-o-signal-slash', 'iconColor' => 'warning',
            'format' => 'filament', 'duration' => 'persistent', 'provenance' => $this->deliveryProvenance($notifiable),
        ];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)->title($this->title())->body($this->body())
            ->icon('/images/mbfd-logo.png')->badge('/images/mbfd-logo.png')
            ->tag('mbfd-ems-kiosk-offline-'.substr(sha1($this->eventKey), 0, 12));
    }

    public function viaQueues(): array
    {
        return ['database' => 'notifications', WebPushChannel::class => 'notifications', BudgetedMailChannel::class => 'notifications'];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Supply room screen offline alert delivery failed', ['event_key' => $this->eventKey, 'exception' => $exception->getMessage()]);
    }
}
