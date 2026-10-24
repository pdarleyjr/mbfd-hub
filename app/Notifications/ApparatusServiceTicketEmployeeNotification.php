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
 * Tells the member who asked for an apparatus service ticket how it is going. It is an update on something
 * the member submitted, so the evaluator decides it as "Updates on things I submitted": the in-app copy is
 * always kept, and push (generic text, no ticket note) only when the member chose it. Email is not offered:
 * these updates never went to email.
 */
class ApparatusServiceTicketEmployeeNotification extends Notification implements ShouldQueue
{
    use EvaluatesDelivery;
    use Queueable;

    public function __construct(
        private readonly string $ticketNumber,
        private readonly string $unit,
        private readonly string $status,
        private readonly ?string $publicNote,
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
        $statusLabel = str($this->status)->replace('_', ' ')->title()->toString();

        return [
            'title' => "{$this->ticketNumber} updated",
            'body' => trim("{$this->unit}: {$statusLabel}. ".($this->publicNote ?? '')),
            'icon' => 'heroicon-o-wrench-screwdriver',
            'iconColor' => $this->status === 'completed' ? 'success' : 'info',
            'format' => 'filament',
            'duration' => 'persistent',
            'actions' => [],
            'provenance' => $this->deliveryProvenance($notifiable),
        ];
    }

    /** The ticket's public note is the staff's words: the lock screen only says there is an update. */
    public function toWebPush(object $notifiable, mixed $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Service ticket updated')
            ->body(PushPrivacy::messageBody($this->unit))
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->tag('mbfd-service-ticket-'.$this->ticketNumber)
            ->data(['url' => $this->deliveryUrl($notifiable, '/employee')]);
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return ['database' => 'notifications', WebPushChannel::class => 'notifications'];
    }
}
