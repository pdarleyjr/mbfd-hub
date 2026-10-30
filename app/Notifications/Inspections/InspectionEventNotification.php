<?php

declare(strict_types=1);

namespace App\Notifications\Inspections;

use App\Models\User;
use App\Notifications\Channels\BudgetedMailChannel;
use App\Notifications\Concerns\EvaluatesDelivery;
use App\Services\Captain5\Captain5Notifier;
use App\Services\Narcotics\NarcoticsNotifier;
use App\Services\Notifications\NotificationDecision;
use App\Services\Notifications\NotificationRecipientEvaluator;
use App\Services\Notifications\PushPrivacy;
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
 * Every kind belongs to a registry category (InspectionNotificationKind::categoryTable,
 * refined per recipient by InspectionNotificationCategory). Who may receive it is the
 * evaluator's answer for that category NOW, both when queued and when delivered. The
 * in-app (Filament bell) copy is always delivered to an eligible recipient: these are
 * accountability messages. Web push and the city email follow the recipient's saved
 * choices, which can only narrow what eligibility allows.
 *
 * Only member-safe text ever reaches this class. Internal notes are never
 * passed in by the dispatcher.
 */
final class InspectionEventNotification extends Notification implements ShouldQueue
{
    use EvaluatesDelivery;
    use Queueable;

    /** @var array<int, string> recipient id => category, so one delivery evaluates it once */
    private array $categoryByRecipient = [];

    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly ?string $eventKey = null,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Reviewer kinds must still be reviewer-eligible when they are queued AND when
     * the worker delivers them; member kinds need an active account. Either way the
     * in-app copy is kept (accountability message) while push and email follow the
     * member's saved choices.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $decision = $this->evaluateDelivery($notifiable);
        if ($decision === null || ! $decision->eligible) {
            return [];
        }

        return ['database', ...array_values(array_filter(
            $decision->laravelChannels(),
            static fn (string $channel): bool => $channel !== 'database',
        ))];
    }

    /** Re-evaluated at delivery from a fresh copy of the member. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User) {
            return false;
        }
        $fresh = User::query()->find($notifiable->getKey());
        $decision = $fresh instanceof User ? $this->evaluateDelivery($fresh) : null;
        if ($decision === null || ! $decision->eligible) {
            return false;
        }

        return $channel === 'database' || $decision->allows($channel);
    }

    /** The category for a recipient with no special relationship to the subject. */
    protected function deliveryCategory(): string
    {
        return InspectionNotificationKind::category($this->kind);
    }

    /** The category this kind has FOR THIS RECIPIENT (an owner's own update is a member update). */
    public function categoryFor(User $recipient): string
    {
        return $this->categoryByRecipient[(int) $recipient->getKey()]
            ??= InspectionNotificationCategory::for($this->kind, $recipient, $this->eventKey);
    }

    protected function evaluateDelivery(object $notifiable): ?NotificationDecision
    {
        if (! $notifiable instanceof User) {
            return null;
        }

        return app(NotificationRecipientEvaluator::class)->decide($notifiable, $this->categoryFor($notifiable), $this->deliveryChannelMask());
    }

    /** @return array{category: ?string, rule: ?string, event_key: ?string} */
    protected function deliveryProvenance(object $notifiable): array
    {
        $decision = $this->evaluateDelivery($notifiable);

        return [
            'category' => $decision->category ?? $this->deliveryCategory(),
            'rule' => $decision?->rule,
            'event_key' => $this->eventKey,
        ];
    }

    protected function deliveryEventKey(): ?string
    {
        return $this->eventKey;
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
                    'url' => $this->deliveryUrl($notifiable, $this->url),
                    'color' => 'primary',
                    'isOutlined' => false,
                ],
            ],
            // Not rendered by Filament; lets support trace a bell entry to its ledger row.
            'inspection_event' => ['kind' => $this->kind, 'event_key' => $this->eventKey],
            'provenance' => $this->deliveryProvenance($notifiable),
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

    /**
     * Kinds whose text carries a person's words, a reason or a name. Their lock-screen copy is generic
     * ("New message about E3 - open MBFD Hub to read it."); the in-app copy keeps the full text.
     *
     * @return list<string>
     */
    public static function messageBearingKinds(): array
    {
        return [
            InspectionNotificationKind::INSPECTION_MESSAGE,
            InspectionNotificationKind::CLARIFICATION_REQUESTED,
            InspectionNotificationKind::MEMBER_REPLY,
            InspectionNotificationKind::ISSUE_MEMBER_UPDATE,
            InspectionNotificationKind::INSPECTION_DECIDED,
            InspectionNotificationKind::INVENTORY_CORRECTION_SUBMITTED,
            InspectionNotificationKind::INVENTORY_CORRECTION_UPDATE,
            InspectionNotificationKind::INVENTORY_CORRECTION_REPLY,
            Captain5Notifier::KIND_MESSAGE,
            Captain5Notifier::KIND_MEMBER_REPLY,
            Captain5Notifier::KIND_COMMUNICATION,
            Captain5Notifier::KIND_ISSUE_UPDATE,
        ];
    }

    /**
     * What may appear on a lock screen. Titles of the other kinds are fixed phrases led by the unit ("E3: ...");
     * the messages (including the administration post, whose title is the author's subject line) get a generic
     * title. No kind ever puts its body on the lock screen: bodies name members, equipment and reasons.
     *
     * @return array{title: string, body: string}
     */
    public function pushText(): array
    {
        // A narcotics check is never named on a lock screen: not the kind, not the member, not the outcome.
        if (in_array($this->kind, [NarcoticsNotifier::KIND_SUBMITTED, NarcoticsNotifier::KIND_DECISION], true)) {
            return ['title' => 'MBFD Hub update', 'body' => PushPrivacy::detailBody()];
        }

        $unit = $this->kind === Captain5Notifier::KIND_COMMUNICATION ? null : PushPrivacy::subjectFromTitle($this->title);

        if (in_array($this->kind, self::messageBearingKinds(), true)) {
            return [
                'title' => $unit !== null ? "{$unit}: new message" : 'New message',
                'body' => PushPrivacy::messageBody($unit),
            ];
        }

        return [
            'title' => $this->title,
            'body' => PushPrivacy::detailBody(),
        ];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        $push = $this->pushText();

        return (new WebPushMessage)
            ->title($push['title'])
            ->body($push['body'])
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->tag($this->pushTag())
            ->data(['url' => $this->deliveryUrl($notifiable, $this->url)]);
    }

    /**
     * One lock-screen entry per EVENT: every device of a recipient gets the same tag, so a
     * retry or a second device collapses into one entry, while two different events of the
     * same kind (two issues, two messages) never replace each other.
     */
    public function pushTag(): string
    {
        return 'mbfd-inspection-'.$this->kind
            .($this->eventKey !== null ? '-'.substr(sha1($this->eventKey), 0, 12) : '');
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
}
