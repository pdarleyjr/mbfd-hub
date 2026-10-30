<?php

declare(strict_types=1);

namespace App\Notifications\SupportServices;

use App\Models\User;
use App\Notifications\Channels\BudgetedMailChannel;
use App\Notifications\Concerns\EvaluatesDelivery;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Notifications\PushPrivacy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use Throwable;

/**
 * One alert for Support Services messaging (docs/employee-logistics-messaging.md section 10).
 *
 * - AUDIENCE_SUPPORT: a member started a conversation or replied. Goes to the Support Services inbox
 *   audience (category support_services_messages); eligibility is EmployeeLogisticsMessagingAccess,
 *   asked when the alert is queued and again when each channel is delivered.
 * - AUDIENCE_MEMBER: Support Services replied. Goes to the member who owns the conversation
 *   (category member_request_updates).
 *
 * The in-app copy (behind sign-in) carries the sender, reason and a preview. The lock screen and the
 * email preview never carry a name, subject, reason or message text: they only say there is a new
 * message. The click-through is resolved for each recipient (NotificationLink), so someone who has
 * lost access lands somewhere safe instead of on a 403.
 */
final class SupportServicesMessageNotification extends Notification implements ShouldQueue
{
    use EvaluatesDelivery;
    use Queueable;

    public const AUDIENCE_SUPPORT = 'support';

    public const AUDIENCE_MEMBER = 'member';

    public function __construct(
        public readonly string $audience,
        public readonly int $threadId,
        public readonly int $messageId,
        private readonly string $senderName,
        private readonly ?string $reasonLabel,
        private readonly string $preview,
    ) {
        $this->onQueue('notifications');
    }

    public static function eventKey(int $messageId): string
    {
        return 'support-msg:'.$messageId;
    }

    /** The category this alert is sent under. */
    public function categoryKey(): string
    {
        return $this->audience === self::AUDIENCE_SUPPORT
            ? NotificationCategoryRegistry::SUPPORT_SERVICES_MESSAGES
            : User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES;
    }

    protected function deliveryCategory(): string
    {
        return $this->categoryKey();
    }

    protected function deliveryEventKey(): string
    {
        return self::eventKey($this->messageId);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->evaluatedChannels($notifiable);
    }

    /** Site-relative click-through for this audience. */
    public function path(): string
    {
        return $this->audience === self::AUDIENCE_SUPPORT
            ? '/admin/employee-messages?thread='.$this->threadId
            : '/employee/messages?thread='.$this->threadId;
    }

    /** @return array<string, mixed> Filament-compatible database notification; full text allowed here. */
    public function toDatabase(object $notifiable): array
    {
        $support = $this->audience === self::AUDIENCE_SUPPORT;

        return [
            'title' => $support ? 'New message from '.$this->senderName : 'Support Services replied',
            'body' => trim(($support && $this->reasonLabel !== null ? $this->reasonLabel.': ' : '').$this->preview),
            'icon' => 'heroicon-o-chat-bubble-bottom-center-text',
            'iconColor' => 'info',
            'format' => 'filament',
            'duration' => 'persistent',
            'actions' => [[
                'name' => 'view',
                'label' => $support ? 'Open conversation' : 'Read reply',
                'url' => $this->deliveryUrl($notifiable, $this->path()),
                'color' => 'primary',
                'isOutlined' => false,
            ]],
            'support_services_message' => ['thread_id' => $this->threadId, 'message_id' => $this->messageId],
            'provenance' => $this->deliveryProvenance($notifiable),
        ];
    }

    /** @return array{title: string, body: string} The only text that may reach a lock screen. */
    public function pushText(): array
    {
        return [
            'title' => $this->audience === self::AUDIENCE_SUPPORT ? 'New Support Services message' : 'Support Services replied',
            'body' => PushPrivacy::messageBody(),
        ];
    }

    public function toWebPush(object $notifiable, mixed $notification): WebPushMessage
    {
        $push = $this->pushText();

        return (new WebPushMessage)
            ->title($push['title'])
            ->body($push['body'])
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->tag('mbfd-support-msg-'.$this->messageId)
            ->data(['url' => $this->deliveryUrl($notifiable, $this->path())]);
    }

    /** @return array{subject: string, text: string, html: string} No name, subject, reason or message text. */
    public function toBudgetedEmail(object $notifiable): array
    {
        $push = $this->pushText();
        $url = url($this->deliveryUrl($notifiable, $this->path()));

        return [
            'subject' => $push['title'],
            'text' => $push['body']."\n\nOpen in MBFD Hub: {$url}",
            'html' => '<h1>'.e($push['title']).'</h1><p>'.e($push['body']).'</p><p><a href="'.e($url).'">Open in MBFD Hub</a></p>',
        ];
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
        Log::error('Support Services message notification failed', [
            'thread_id' => $this->threadId,
            'message_id' => $this->messageId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
