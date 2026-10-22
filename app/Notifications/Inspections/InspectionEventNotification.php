<?php

declare(strict_types=1);

namespace App\Notifications\Inspections;

use App\Models\User;
use App\Notifications\Channels\BudgetedMailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use Throwable;

/**
 * One queued notification for every daily-inspection workflow event.
 *
 * The in-app (Filament bell) copy is always delivered: these are accountability
 * messages about a member's own checkout or an issue they follow. Web push and
 * the city email follow the recipient's existing `user_notification_subscriptions`
 * row, so no new preference store or vendor is introduced.
 *
 * Only member-safe text ever reaches this class. Internal notes are never
 * passed in by the dispatcher.
 */
final class InspectionEventNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly ?string $eventKey = null,
    ) {
        $this->onQueue('notifications');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }

        $subscription = $notifiable->notificationSubscriptions()
            ->where('event_key', $this->preferenceKey())
            ->first();

        $channels = ['database'];
        if ($subscription?->webpush_enabled && $notifiable->pushSubscriptions()->count() > 0) {
            $channels[] = WebPushChannel::class;
        }
        if ($subscription?->email_enabled && filled($notifiable->employeeProfile?->city_email)) {
            $channels[] = BudgetedMailChannel::class;
        }

        return $channels;
    }

    /** @return array<string, mixed> Filament-compatible database notification. */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'icon' => InspectionNotificationKind::icon($this->kind),
            'iconColor' => InspectionNotificationKind::color($this->kind),
            'format' => 'filament',
            'duration' => 'persistent',
            'actions' => [
                [
                    'name' => 'view',
                    'label' => 'View',
                    'url' => $this->url,
                    'color' => 'primary',
                    'isOutlined' => false,
                ],
            ],
            // Not rendered by Filament; lets support trace a bell entry to its ledger row.
            'inspection_event' => ['kind' => $this->kind, 'event_key' => $this->eventKey],
        ];
    }

    /** @return array{subject: string, text: string, html: string} */
    public function toBudgetedEmail(object $notifiable): array
    {
        $url = url($this->url);

        return [
            'subject' => $this->title,
            'text' => $this->body."\n\nView in MBFD Hub: {$url}",
            'html' => '<h1>'.e($this->title).'</h1><p>'.e($this->body).'</p><p><a href="'.e($url).'">View in MBFD Hub</a></p>',
        ];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->tag('mbfd-inspection-'.$this->kind)
            ->data(['url' => $this->url]);
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return [
            'database' => 'notifications',
            WebPushChannel::class => 'notifications',
            BudgetedMailChannel::class => 'notifications',
        ];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Inspection notification delivery failed', [
            'kind' => $this->kind,
            'event_key' => $this->eventKey,
            'exception' => $exception->getMessage(),
        ]);
    }

    private function preferenceKey(): string
    {
        return InspectionNotificationKind::isForReviewers($this->kind)
            ? (string) User::preferenceKeyForSubmissionType('apparatus_inspection')
            : (string) User::preferenceKeyForSubmissionType('member_request_update');
    }
}
