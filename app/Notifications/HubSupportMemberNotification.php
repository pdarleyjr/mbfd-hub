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
 * Tells the reporter how their website/app problem report is going: an update on something the member
 * submitted ("Updates on things I submitted"). The in-app copy is always kept; push (generic text, no reply)
 * only when the member chose it. These replies never went to email, and still do not.
 */
final class HubSupportMemberNotification extends Notification implements ShouldQueue
{
    use EvaluatesDelivery;
    use Queueable;

    public function __construct(private readonly int $ticketId, private readonly string $status, private readonly string $message) {}

    protected function deliveryCategory(): string
    {
        return User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES;
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
            'provenance' => $this->deliveryProvenance($notifiable),
        ];
    }

    /** The reply is the support team's words: the lock screen only says there is one. */
    public function toWebPush(object $notifiable, mixed $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Problem report updated')
            ->body(PushPrivacy::messageBody())
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->tag('mbfd-support-report-'.$this->ticketId)
            ->data(['url' => $this->deliveryUrl($notifiable, '/support/issues/'.$this->ticketId)]);
    }

    public function viaQueues(): array
    {
        return ['database' => 'notifications', WebPushChannel::class => 'notifications'];
    }
}
