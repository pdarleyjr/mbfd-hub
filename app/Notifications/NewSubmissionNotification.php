<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\BudgetedMailChannel;
use App\Notifications\Concerns\EvaluatesDelivery;
use App\Services\Notifications\PushPrivacy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use Throwable;

/**
 * An operational submission or request alert. Who gets it, and over which
 * channels, is decided only by the notification evaluator (current eligibility,
 * then the member's saved choices); a saved choice never makes someone eligible.
 */
class NewSubmissionNotification extends Notification implements ShouldQueue
{
    use EvaluatesDelivery;
    use Queueable;

    /**
     * @param  string|null  $eventKey  Idempotency key (category + subject + event) recorded as provenance.
     * @param  bool  $messageBearing  The text carries a person's words, name or a reason: the lock-screen push is generic
     *                                (the in-app copy and the email keep the full text).
     * @param  string|null  $pushSubject  The unit or request number the generic push names.
     */
    public function __construct(
        private string $submissionType,
        private string $title,
        private string $body,
        private string $actionUrl = '/admin',
        private string $icon = 'heroicon-o-document-check',
        private ?string $eventKey = null,
        private bool $messageBearing = false,
        private ?string $pushSubject = null,
    ) {}

    protected function deliveryCategory(): ?string
    {
        return User::preferenceKeyForSubmissionType($this->submissionType);
    }

    protected function deliveryEventKey(): ?string
    {
        return $this->eventKey;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->evaluatedChannels($notifiable);
    }

    /** @return array{subject: string, text: string, html: string} */
    public function toBudgetedEmail(object $notifiable): array
    {
        $safeTitle = e($this->title);
        $safeBody = e($this->body);
        $url = url($this->deliveryUrl($notifiable, $this->actionUrl));

        return [
            'subject' => $this->title,
            'text' => $this->body."\n\nView in MBFD Hub: {$url}",
            'html' => "<h1>{$safeTitle}</h1><p>{$safeBody}</p><p><a href=\"".e($url).'\">View in MBFD Hub</a></p>',
        ];
    }

    /**
     * Filament-compatible database notification format.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'icon' => $this->icon,
            'iconColor' => 'info',
            'format' => 'filament',
            'duration' => 'persistent',
            'actions' => [
                [
                    'name' => 'view',
                    'label' => 'View',
                    'url' => $this->deliveryUrl($notifiable, $this->actionUrl),
                    'color' => 'primary',
                    'isOutlined' => false,
                ],
            ],
            // Not rendered by Filament; answers "why did I get this".
            'provenance' => $this->deliveryProvenance($notifiable),
        ];
    }

    /**
     * Web Push notification payload.
     */
    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        // One lock-screen entry per event; two different events never replace each other.
        $tag = 'mbfd-submission-'.$this->submissionType
            .($this->eventKey !== null ? '-'.substr(sha1($this->eventKey), 0, 12) : '');

        $title = $this->messageBearing ? 'New message' : $this->title;
        $body = $this->messageBearing ? PushPrivacy::messageBody($this->pushSubject) : $this->body;

        return (new WebPushMessage)
            ->title($title)
            ->body($body)
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->tag($tag)
            ->data(['url' => $this->deliveryUrl($notifiable, $this->actionUrl)]);
    }

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
        Log::error('Submission notification delivery failed', [
            'notification' => static::class,
            'submission_type' => $this->submissionType,
            'title' => $this->title,
            'action_url' => $this->actionUrl,
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'vapid_subject' => config('webpush.vapid.subject'),
            'vapid_public_key_present' => filled(config('webpush.vapid.public_key')),
            'vapid_private_key_present' => filled(config('webpush.vapid.private_key')),
        ]);
    }
}
