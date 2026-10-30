<?php

declare(strict_types=1);

namespace App\Services\Narcotics;

use App\Models\Narcotics\NarcoticsCheck;
use App\Models\Narcotics\NarcoticsCheckEvent;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Captain5\Captain5DutyRoster;
use App\Services\Inspections\InspectionNotificationDispatcher;
use App\Support\Narcotics\NarcoticsLinks;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Narcotics check notifications (docs/narcotics-check.md section 6), the same ledger pattern as
 * EmsNotifier and Captain5Notifier: call inside the domain transaction, one
 * `inspection_notification_deliveries` row per (event key, recipient), delivery after commit, never
 * throws into the domain flow, the actor is never notified. Event keys: narcotics:check:{id}:submitted
 * and narcotics:check:{id}:decided:{eventId}; inspections:retry-notifications routes them to redeliver().
 *
 * What is NEVER put in a notification: the photo, the member's note or the rejection explanation. The
 * in-app copy names the member (it is behind sign-in) and says what needs doing; the lock-screen push
 * text is the generic "Open MBFD Hub for details." that InspectionEventNotification::pushText() gives
 * every non-message kind.
 */
final class NarcoticsNotifier
{
    public const KIND_SUBMITTED = 'narcotics_check_submitted';

    public const KIND_DECISION = 'narcotics_check_decision';

    /** @var array{user: int, key: string, sent: bool}|null */
    private ?array $replay = null;

    public function __construct(
        private readonly Captain5DutyRoster $roster,
        private readonly InspectionNotificationDispatcher $dispatcher,
        private readonly NarcoticsCheckAccess $access,
    ) {}

    /** The on-duty Captain 5 (every Captain 5 seat holder when nobody resolves), limited to people who may still review. */
    public function checkSubmitted(NarcoticsCheck $check): void
    {
        $this->guard('checkSubmitted', function () use ($check): void {
            $submitter = $check->submitted_by_user_id !== null ? User::query()->find($check->submitted_by_user_id) : null;
            $resubmission = $check->resubmission_of_id !== null ? ' (sent again after a rejection)' : '';

            $this->queue(
                'narcotics:check:'.$check->getKey().':submitted',
                self::KIND_SUBMITTED,
                $this->roster->notificationRecipients(),
                fn (User $recipient): array => [
                    'title' => 'Narcotics check to review',
                    'body' => "{$check->submitter_name} sent a narcotics check{$resubmission}. Open Captain 5 to review it.",
                    'url' => NarcoticsLinks::staff($check->uuid),
                ],
                $submitter,
                reviewers: true,
            );
        });
    }

    /** Tells the member the outcome. Never carries the explanation: it is read in the app. */
    public function checkDecided(NarcoticsCheck $check, NarcoticsCheckEvent $event): void
    {
        $this->guard('checkDecided', function () use ($check, $event): void {
            $actor = $event->actor_user_id !== null ? User::query()->find($event->actor_user_id) : null;
            $approved = $check->status === NarcoticsCheck::APPROVED;

            $this->queue(
                'narcotics:check:'.$check->getKey().':decided:'.$event->getKey(),
                self::KIND_DECISION,
                $check->submitted_by_user_id === null ? collect() : User::query()->whereKey($check->submitted_by_user_id)->get(),
                fn (User $recipient): array => [
                    'title' => $approved ? 'Narcotics check approved' : 'Narcotics check rejected',
                    'body' => $approved
                        ? 'Captain 5 approved your narcotics check.'
                        : 'Captain 5 did not accept your narcotics check. Open MBFD Hub to see why and send it again.',
                    'url' => NarcoticsLinks::member($check->uuid),
                ],
                $actor,
            );
        });
    }

    /** Replays one queued ledger row (inspections:retry-notifications). */
    public function redeliver(string $eventKey, string $kind, int $recipientUserId): bool
    {
        $this->replay = ['user' => $recipientUserId, 'key' => $eventKey, 'sent' => false];

        try {
            $parts = explode(':', $eventKey);
            if (($parts[0] ?? '') !== 'narcotics' || ($parts[1] ?? '') !== 'check') {
                Log::warning('Narcotics notification retry: event cannot be rebuilt', ['event_key' => $eventKey]);

                return false;
            }
            $check = NarcoticsCheck::query()->find((int) ($parts[2] ?? 0));
            if ($check === null) {
                return false;
            }
            if (($parts[3] ?? '') === 'submitted') {
                $this->checkSubmitted($check);
            } elseif (($parts[3] ?? '') === 'decided') {
                $event = NarcoticsCheckEvent::query()->find((int) ($parts[4] ?? 0));
                if ($event !== null) {
                    $this->checkDecided($check, $event);
                }
            }

            return $this->replay['sent'];
        } finally {
            $this->replay = null;
        }
    }

    /**
     * @param  Collection<int, User>  $candidates
     * @param  Closure(User): array{title: string, body: string, url: string}  $content
     */
    private function queue(string $eventKey, string $kind, Collection $candidates, Closure $content, ?User $actor = null, bool $reviewers = false): void
    {
        $recipients = $this->dispatcher->distinctRecipients($candidates, $actor);
        if ($reviewers) {
            // Reviewer-addressed notices go only to people who can still open the inbox right now.
            $recipients = $recipients->filter(fn (User $user): bool => $this->access->canReview($user))->values();
        }

        if ($this->replay !== null) {
            if ($eventKey !== $this->replay['key']) {
                return;
            }
            $recipient = $recipients->first(fn (User $user): bool => (int) $user->getKey() === $this->replay['user']);
            if ($recipient !== null) {
                try {
                    $payload = $content($recipient);
                    $recipient->notify(new InspectionEventNotification($kind, $payload['title'], $payload['body'], $payload['url'], $eventKey));
                    $this->replay['sent'] = true;
                } catch (Throwable $exception) {
                    Log::error('Narcotics notification retry could not be dispatched', ['event_key' => $eventKey, 'exception' => $exception->getMessage()]);
                }
            }

            return;
        }

        $fresh = [];
        foreach ($recipients as $recipient) {
            $inserted = DB::table('inspection_notification_deliveries')->insertOrIgnore([
                'event_key' => $eventKey,
                'recipient_user_id' => $recipient->getKey(),
                'notification_type' => $kind,
                'status' => 'queued',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted > 0) {
                $fresh[] = $recipient;
            }
        }
        if ($fresh === []) {
            return;
        }

        DB::afterCommit(function () use ($fresh, $eventKey, $kind, $content): void {
            foreach ($fresh as $recipient) {
                try {
                    $payload = $content($recipient);
                    $recipient->notify(new InspectionEventNotification($kind, $payload['title'], $payload['body'], $payload['url'], $eventKey));
                    $this->mark($eventKey, $recipient, 'dispatched');
                } catch (Throwable $exception) {
                    $this->mark($eventKey, $recipient, 'failed');
                    Log::error('Narcotics notification could not be dispatched', [
                        'event_key' => $eventKey, 'kind' => $kind,
                        'recipient_user_id' => $recipient->getKey(), 'exception' => $exception->getMessage(),
                    ]);
                }
            }
        });
    }

    private function mark(string $eventKey, User $recipient, string $status): void
    {
        try {
            DB::table('inspection_notification_deliveries')
                ->where('event_key', $eventKey)->where('recipient_user_id', $recipient->getKey())
                ->update(['status' => $status, 'updated_at' => now()]);
        } catch (Throwable $exception) {
            Log::error('Narcotics notification ledger could not be updated', ['event_key' => $eventKey, 'exception' => $exception->getMessage()]);
        }
    }

    private function guard(string $step, Closure $work): void
    {
        try {
            DB::transaction($work); // savepoint: a ledger failure never aborts the caller's transaction
        } catch (Throwable $exception) {
            Log::error('Narcotics notification could not be prepared', ['step' => $step, 'exception' => $exception->getMessage()]);
        }
    }
}
