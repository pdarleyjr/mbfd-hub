<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\EvaluatesDelivery;
use App\Services\Notifications\PushPrivacy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Tells the member who submitted a station request that someone else marked it resolved. It is an update on
 * something the member submitted ("Updates on things I submitted"): the in-app copy is always kept, and push
 * (generic text, no names) only when the member chose it. Never email.
 */
final class StationRequestMemberResolvedNotification extends Notification implements ShouldQueue
{
    use EvaluatesDelivery;
    use Queueable;

    public function __construct(
        private readonly string $requestNumber,
        private readonly string $requestTitle,
        private readonly string $resolverName,
        private readonly string $outcomeLabel,
        private readonly ?string $url,
        private readonly ?string $eventKey = null,
    ) {}

    protected function deliveryCategory(): string
    {
        return User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES;
    }

    protected function deliveryEventKey(): ?string
    {
        return $this->eventKey;
    }

    /** @return list<string> */
    protected function deliveryChannelMask(): array
    {
        return ['database', 'webpush'];
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->evaluatedChannels($notifiable);
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $payload = [
            'title' => "{$this->requestNumber} marked resolved",
            'body' => "{$this->resolverName} marked \"{$this->requestTitle}\" resolved: {$this->outcomeLabel}.",
            'icon' => 'heroicon-o-check-circle',
            'iconColor' => 'success',
            'format' => 'filament',
            'duration' => 'persistent',
            'actions' => [],
            'provenance' => $this->deliveryProvenance($notifiable),
        ];

        if ($this->url !== null) {
            $payload['actions'][] = [
                'name' => 'view',
                'label' => 'View',
                'url' => $this->deliveryUrl($notifiable, $this->url),
                'color' => 'primary',
                'isOutlined' => false,
            ];
        }

        return $payload;
    }

    /** A person's name stays behind sign-in: the lock screen only says the request has an update. */
    public function toWebPush(object $notifiable, mixed $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Station request updated')
            ->body(PushPrivacy::messageBody($this->requestNumber))
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->tag('mbfd-station-request-'.$this->requestNumber)
            ->data(['url' => $this->deliveryUrl($notifiable, $this->url ?? '/employee')]);
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return ['database' => 'notifications', WebPushChannel::class => 'notifications'];
    }
}
