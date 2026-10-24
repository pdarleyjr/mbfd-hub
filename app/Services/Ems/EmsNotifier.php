<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\InventoryRequest;
use App\Models\Ems\InventoryRequestEvent;
use App\Models\Ems\InventoryRequestMessage;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Inspections\InspectionNotificationDispatcher;
use App\Support\Ems\EmsLinks;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * EMS notifications (docs/ems/ems-inventory-contract.md section 8). Same pattern as
 * Captain5Notifier: call inside the domain transaction, one ledger row per (event key, recipient) in
 * `inspection_notification_deliveries`, delivery after commit, never throws into the domain flow, the
 * actor is never notified. Event keys: ems:request:{id}:submitted, ems:message:{id},
 * ems:request:{id}:status:{eventId}.
 */
final class EmsNotifier
{
    public const KIND_SUBMITTED = 'ems_request_submitted';

    public const KIND_MESSAGE = 'ems_message';

    public const KIND_STATUS = 'ems_request_update';

    private const EXCERPT_LENGTH = 240;

    /** Request statuses the requester hears about (everything else stays in history). */
    private const MEMBER_NOTIFIED = [InventoryRequest::ISSUED, InventoryRequest::CLOSED];

    /** @var array{user: int, key: string, sent: bool}|null */
    private ?array $replay = null;

    public function __construct(
        private readonly Captain5Recipients $recipients,
        private readonly InspectionNotificationDispatcher $dispatcher,
        private readonly EmsAccess $access,
    ) {}

    public function requestSubmitted(InventoryRequest $request): void
    {
        $this->guard('requestSubmitted', function () use ($request): void {
            $submitter = $request->submitted_by_user_id !== null ? User::query()->find($request->submitted_by_user_id) : null;
            $count = $request->lines()->count();
            $body = sprintf(
                '%s sent %s for %s with %d item%s.',
                $request->submitter_name,
                $request->number,
                $request->target_label,
                $count,
                $count === 1 ? '' : 's',
            );
            if (trim((string) $request->member_note) !== '') {
                $body .= ' Note: '.$this->excerpt((string) $request->member_note);
            }

            $this->queue(
                'ems:request:'.$request->getKey().':submitted',
                self::KIND_SUBMITTED,
                $this->recipients->staff(),
                fn (User $recipient): array => [
                    'title' => "{$request->number}: new medical inventory request",
                    'body' => $body,
                    'url' => $this->staffUrl($recipient, $request),
                ],
                $submitter,
                staff: true,
            );
        });
    }

    public function messagePosted(InventoryRequestMessage $message): void
    {
        $this->guard('messagePosted', function () use ($message): void {
            $request = InventoryRequest::query()->find($message->request_id);
            if ($request === null) {
                return;
            }
            $author = $message->author_user_id !== null ? User::query()->find($message->author_user_id) : null;
            $fromMember = $message->author_context === InventoryRequestEvent::CONTEXT_MEMBER;
            $candidates = $fromMember
                ? $this->recipients->staff()->merge($this->workingStaff($request))
                : $this->requester($request);
            $excerpt = $this->excerpt($message->body);

            $this->queue(
                'ems:message:'.$message->getKey(),
                self::KIND_MESSAGE,
                $candidates,
                fn (User $recipient): array => [
                    'title' => $fromMember ? "{$request->number}: message from {$message->author_name}" : "{$request->number}: message from the EMS supply room",
                    'body' => $excerpt,
                    'url' => $fromMember ? $this->staffUrl($recipient, $request) : EmsLinks::member($request->uuid),
                ],
                $author,
                staff: $fromMember,
                coalesceRequest: $request->uuid,
            );
        });
    }

    /** Tells the requester when their request is issued or closed (other changes stay in history). */
    public function statusChanged(InventoryRequest $request, InventoryRequestEvent $event): void
    {
        // Reopening lands back on a status the member already heard about; that stays in history.
        if (! in_array($event->to_status, self::MEMBER_NOTIFIED, true) || $event->action === 'reopened') {
            return;
        }
        $this->guard('statusChanged', function () use ($request, $event): void {
            $actor = $event->actor_user_id !== null ? User::query()->find($event->actor_user_id) : null;
            $body = $event->to_status === InventoryRequest::ISSUED
                ? "{$request->number} for {$request->target_label} was issued."
                : "{$request->number} for {$request->target_label} was closed.".($request->close_reason ? ' '.$this->excerpt((string) $request->close_reason) : '');

            $this->queue(
                'ems:request:'.$request->getKey().':status:'.$event->getKey(),
                self::KIND_STATUS,
                $this->requester($request),
                fn (User $recipient): array => [
                    'title' => $event->to_status === InventoryRequest::ISSUED ? "{$request->number}: supplies issued" : "{$request->number}: request closed",
                    'body' => $body,
                    'url' => EmsLinks::member($request->uuid),
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
            if (($parts[0] ?? '') !== 'ems') {
                return false;
            }
            if (($parts[1] ?? '') === 'message') {
                $message = InventoryRequestMessage::query()->find((int) ($parts[2] ?? 0));
                if ($message !== null) {
                    $this->messagePosted($message);
                }
            } elseif (($parts[1] ?? '') === 'request') {
                $request = InventoryRequest::query()->find((int) ($parts[2] ?? 0));
                if ($request !== null && ($parts[3] ?? '') === 'submitted') {
                    $this->requestSubmitted($request);
                } elseif ($request !== null && ($parts[3] ?? '') === 'status') {
                    $event = InventoryRequestEvent::query()->find((int) ($parts[4] ?? 0));
                    if ($event !== null) {
                        $this->statusChanged($request, $event);
                    }
                }
            } else {
                Log::warning('EMS notification retry: event cannot be rebuilt', ['event_key' => $eventKey]);
            }

            return $this->replay['sent'];
        } finally {
            $this->replay = null;
        }
    }

    // ------------------------------------------------------------ recipients

    /** @return Collection<int, User> */
    private function requester(InventoryRequest $request): Collection
    {
        return $request->submitted_by_user_id === null
            ? collect()
            : User::query()->whereKey($request->submitted_by_user_id)->get();
    }

    /** Staff who already acted on this request hear about the member's reply too. */
    private function workingStaff(InventoryRequest $request): Collection
    {
        $ids = InventoryRequestEvent::query()
            ->where('request_id', $request->getKey())
            ->whereIn('actor_context', [InventoryRequestEvent::CONTEXT_CAPTAIN5, InventoryRequestEvent::CONTEXT_EMS_ADMIN])
            ->whereNotNull('actor_user_id')
            ->pluck('actor_user_id')
            ->merge(InventoryRequestMessage::query()->where('request_id', $request->getKey())
                ->whereIn('author_context', [InventoryRequestEvent::CONTEXT_CAPTAIN5, InventoryRequestEvent::CONTEXT_EMS_ADMIN])
                ->whereNotNull('author_user_id')->pluck('author_user_id'))
            ->unique()->all();

        return $ids === [] ? collect() : User::query()->whereIn('id', $ids)->get();
    }

    /** Captain 5 officers land in the workspace; Admin-only EMS people on the admin page. */
    private function staffUrl(User $recipient, InventoryRequest $request): string
    {
        return $this->access->viaCaptain5($recipient)
            ? EmsLinks::staff($request->uuid)
            : EmsLinks::admin($request->uuid);
    }

    // -------------------------------------------------------------- delivery

    /**
     * @param  Collection<int, User>  $candidates
     * @param  Closure(User): array{title: string, body: string, url: string}  $content
     */
    private function queue(string $eventKey, string $kind, Collection $candidates, Closure $content, ?User $actor = null, bool $staff = false, ?string $coalesceRequest = null): void
    {
        $recipients = $this->dispatcher->distinctRecipients($candidates, $actor);
        if ($staff) {
            // Staff-addressed notices go only to people who can still open EMS right now.
            $recipients = $recipients->filter(fn (User $user): bool => $this->access->canView($user))->values();
        }
        if ($coalesceRequest !== null && $this->replay === null) {
            // One unread message notice per request per person is enough; the thread itself has the rest.
            $recipients = $recipients->reject(fn (User $user): bool => $this->hasUnread($user, $kind, $coalesceRequest))->values();
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
                    Log::error('EMS notification retry could not be dispatched', ['event_key' => $eventKey, 'exception' => $exception->getMessage()]);
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
                    Log::error('EMS notification could not be dispatched', [
                        'event_key' => $eventKey, 'kind' => $kind,
                        'recipient_user_id' => $recipient->getKey(), 'exception' => $exception->getMessage(),
                    ]);
                }
            }
        });
    }

    private function hasUnread(User $user, string $kind, string $requestUuid): bool
    {
        return $user->unreadNotifications()
            ->where('data', 'like', '%"kind":"'.$kind.'"%')
            ->where('data', 'like', '%'.$requestUuid.'%')
            ->exists();
    }

    private function mark(string $eventKey, User $recipient, string $status): void
    {
        try {
            DB::table('inspection_notification_deliveries')
                ->where('event_key', $eventKey)->where('recipient_user_id', $recipient->getKey())
                ->update(['status' => $status, 'updated_at' => now()]);
        } catch (Throwable $exception) {
            Log::error('EMS notification ledger could not be updated', ['event_key' => $eventKey, 'exception' => $exception->getMessage()]);
        }
    }

    private function guard(string $step, Closure $work): void
    {
        try {
            DB::transaction($work); // savepoint: a ledger failure never aborts the caller's transaction
        } catch (Throwable $exception) {
            Log::error('EMS notification could not be prepared', ['step' => $step, 'exception' => $exception->getMessage()]);
        }
    }

    private function excerpt(string $text): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', strip_tags($text))), self::EXCERPT_LENGTH);
    }
}
