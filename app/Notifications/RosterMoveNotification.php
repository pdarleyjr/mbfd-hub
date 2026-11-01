<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Concerns\EvaluatesDelivery;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Notifications\PushPrivacy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * TeleStaff changed the rest of this member's shift. Push and in-app only, each of which the member can
 * turn off. The lock screen gets the title, which names a unit and a time; the detail stays behind sign-in.
 */
final class RosterMoveNotification extends Notification implements ShouldQueue
{
    use EvaluatesDelivery;
    use Queueable;

    private const URL = '/daily-roster';

    /** @param  int|null  $shiftEndsAt  Unix time the duty day ends: a push still undelivered by then is dropped, not shown to someone off duty. */
    public function __construct(
        private readonly string $title,
        private readonly string $body,
        private readonly string $eventKey,
        private readonly ?int $shiftEndsAt = null,
    ) {
        $this->onQueue('notifications');
    }

    protected function deliveryCategory(): string
    {
        return NotificationCategoryRegistry::ROSTER_MOVES;
    }

    protected function deliveryEventKey(): string
    {
        return $this->eventKey;
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
            'icon' => 'heroicon-o-arrows-right-left',
            'iconColor' => 'warning',
            'format' => 'filament',
            'duration' => 'persistent',
            'actions' => [[
                'name' => 'view',
                'label' => 'View roster',
                'url' => $this->deliveryUrl($notifiable, self::URL),
                'color' => 'primary',
                'isOutlined' => false,
            ]],
            'provenance' => $this->deliveryProvenance($notifiable),
        ];
    }

    public function toWebPush(object $notifiable, mixed $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body(PushPrivacy::detailBody())
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->tag('mbfd-roster-move-'.substr(sha1($this->eventKey), 0, 12))
            ->data(['url' => $this->deliveryUrl($notifiable, self::URL)])
            ->options($this->pushOptions());
    }

    /** @return array<string, int|string> A move is worth waking a phone for, and worthless once the shift is over. */
    private function pushOptions(): array
    {
        return ['urgency' => 'high'] + ($this->shiftEndsAt === null ? [] : ['TTL' => max(0, $this->shiftEndsAt - now()->getTimestamp())]);
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
