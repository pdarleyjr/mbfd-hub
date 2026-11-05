<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

use App\Models\AiAutomation\AiIntakeEvent;
use App\Models\User;
use App\Notifications\AiResponseNotification;
use App\Services\AiAutomation\Contracts\DomainAdapter;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * In-app bell notification for the member who submitted, through the Hub's normal dispatcher (preferences,
 * delivery ledger, after-commit). Database channel only: never email, never push. A failure here never undoes
 * the reply, which is already visible on the member's own screen.
 */
final class AiReplyNotifier
{
    /** Template keys whose reply is visible in the thread but never sent as a notification. */
    public const SILENT_TEMPLATE = 'clean_receipt';

    public function __construct(
        private readonly NotificationDispatchService $dispatcher,
        private readonly AiAutomationSwitch $switch,
    ) {}

    public function notify(AiIntakeEvent $intake, DomainAdapter $adapter, IntakeFacts $facts): void
    {
        // The clean-checkout receipt is only a line in the checkout's own thread and My Activity. It never rings the bell.
        if ($intake->template_key === self::SILENT_TEMPLATE) {
            return;
        }

        try {
            $user = $facts->requesterUserId !== null ? User::query()->find($facts->requesterUserId) : null;
            // The reply was written before any OFF; the bell is still new output, so OFF suppresses it too.
            if (! $user instanceof User || $intake->reply_text === null || ! $this->switch->allows((int) $intake->epoch)) {
                return;
            }

            $this->dispatcher->dispatch(
                User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES,
                new AiResponseNotification((string) $intake->reply_text, $adapter->memberUrl($intake)),
                [$user],
                NotificationDispatchService::eventKey(User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES, 'ai_intake_event', (int) $intake->getKey(), 'reply'),
            );
        } catch (Throwable $exception) {
            Log::warning('AI Response in-app notification failed.', [
                'intake_id' => $intake->getKey(),
                'error' => $exception::class,
            ]);
        }
    }
}
