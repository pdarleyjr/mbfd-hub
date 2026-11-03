<?php

declare(strict_types=1);

namespace App\Notifications\Ems;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\Channels\BudgetedMailChannel;
use App\Notifications\Concerns\EvaluatesDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use Throwable;

/**
 * The member's copy of what they took at the supply room screen (docs/ems/supply-room-kiosk.md section 4). A member
 * with a Hub account gets it under "Updates on things I submitted"; a member without one gets it at the city email
 * on their roster record.
 */
class KioskWithdrawalReceipt extends Notification implements ShouldQueue
{
    use EvaluatesDelivery;
    use Queueable;

    /** @param array<string, mixed> $summary see KioskNotifier::summary() */
    public function __construct(
        public readonly array $summary,
        private readonly string $eventKey,
    ) {}

    protected function deliveryCategory(): ?string
    {
        return User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES;
    }

    protected function deliveryEventKey(): ?string
    {
        return $this->eventKey;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof Employee) {
            return filled($notifiable->city_email) ? [BudgetedMailChannel::class] : [];
        }

        return $this->evaluatedChannels($notifiable);
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($notifiable instanceof Employee) {
            return $channel === BudgetedMailChannel::class && filled($notifiable->city_email);
        }
        if (! $notifiable instanceof User) {
            return false;
        }
        $fresh = User::query()->find($notifiable->getKey());

        return $fresh instanceof User && ($this->evaluateDelivery($fresh)?->allows($channel) ?? false);
    }

    public function title(): string
    {
        return 'You took supplies from the EMS supply room ('.$this->summary['number'].')';
    }

    public function body(): string
    {
        return KioskWithdrawalNotice::lines($this->summary);
    }

    /** @return array{subject: string, text: string, html: string} */
    public function toBudgetedEmail(object $notifiable): array
    {
        $intro = 'This is your copy of what you took at the EMS supply room screen on '.$this->summary['taken_at']
            .($this->summary['unit_label'] ? ' for '.$this->summary['unit_label'] : '').'. Captain 5 has a copy too.';

        return [
            'subject' => 'Your EMS supply room withdrawal '.$this->summary['number'],
            'text' => $intro."\n\n".$this->body()."\n\nIf something is wrong, tell Captain 5.",
            'html' => '<h1>Your EMS supply room withdrawal</h1><p>'.e($intro).'</p>'.KioskWithdrawalNotice::table([$this->summary], false)
                .'<p>If something is wrong, tell Captain 5.</p>',
        ];
    }

    /** Filament-compatible in-app notification. */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->body(),
            'icon' => 'heroicon-o-shopping-bag',
            'iconColor' => 'success',
            'format' => 'filament',
            'duration' => 'persistent',
            'provenance' => $this->deliveryProvenance($notifiable),
        ];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Supply room withdrawal '.$this->summary['number'])
            ->body($this->body())
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->tag('mbfd-ems-kiosk-'.substr(sha1($this->eventKey), 0, 12));
    }

    public function viaQueues(): array
    {
        return ['database' => 'notifications', WebPushChannel::class => 'notifications', BudgetedMailChannel::class => 'notifications'];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Supply room screen receipt delivery failed', ['event_key' => $this->eventKey, 'exception' => $exception->getMessage()]);
    }
}
