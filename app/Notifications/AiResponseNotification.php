<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\EvaluatesDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The bell entry for an AI Response on something the member submitted ("Updates on things I submitted").
 * In-app only: the channel mask drops push and email even when the member has them turned on.
 */
final class AiResponseNotification extends Notification implements ShouldQueue
{
    use EvaluatesDelivery;
    use Queueable;

    public function __construct(private readonly string $reply, private readonly ?string $url) {}

    protected function deliveryCategory(): string
    {
        return User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES;
    }

    /** @return list<string> */
    protected function deliveryChannelMask(): array
    {
        return ['database'];
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->evaluatedChannels($notifiable);
    }

    public function toDatabase(object $notifiable): array
    {
        $payload = [
            'title' => (string) config('ai_automation.sender_label', 'Support Services'),
            'body' => $this->reply,
            'icon' => 'heroicon-o-chat-bubble-left-ellipsis',
            'iconColor' => 'info',
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

    public function viaQueues(): array
    {
        return ['database' => 'notifications'];
    }
}
