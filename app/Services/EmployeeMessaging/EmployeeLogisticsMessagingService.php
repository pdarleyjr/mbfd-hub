<?php

declare(strict_types=1);

namespace App\Services\EmployeeMessaging;

use App\Models\Employee;
use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Models\EmployeeMessaging\EmployeeLogisticsThread;
use App\Models\User;
use App\Notifications\SupportServices\SupportServicesMessageNotification;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Security\EmployeeLogisticsMessagingAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The one home of Employee <-> Logistics messaging behavior (docs/employee-logistics-messaging.md).
 * Member pages, the Logistics inbox and the Logistics Dashboard card are thin views over this class.
 *
 * Every public method takes the signed-in actor and re-checks EmployeeLogisticsMessagingAccess, so a
 * revoked role or disabled account stops working on the next request, not at the next page load.
 * Member reads are always scoped to member_user_id = actor; a thread outside that scope is "not
 * found" (never "forbidden") so ids cannot be probed.
 *
 * Writes are idempotent per (author, client_request_id): a retried send returns the original
 * message; the same id with different text is refused instead of silently accepted.
 */
final class EmployeeLogisticsMessagingService
{
    public const MAX_BODY_LENGTH = 5000;

    public const MAX_SUBJECT_LENGTH = 120;

    public const THREAD_PAGE_SIZE = 20;

    public const THREAD_MESSAGE_LIMIT = 200;

    public const FILTER_ALL = 'all';

    public const FILTER_UNREAD = 'unread';

    public const FILTER_AWAITING_REPLY = 'awaiting';

    private const MEMBER_SENDS_PER_MINUTE = 10;

    private const LOGISTICS_SENDS_PER_MINUTE = 30;

    private const PREVIEW_LENGTH = 140;

    public function __construct(
        private readonly EmployeeLogisticsMessagingAccess $access,
    ) {}

    // ---------------------------------------------------------------- member side

    public function startThread(User $member, string $body, ?string $subject, string $clientRequestId, ?string $reason = null): EmployeeLogisticsMessage
    {
        $employee = $this->requireMember($member);
        $body = $this->normalizeBody($body);
        $subject = $this->normalizeSubject($subject);
        $reason = $this->normalizeReason($reason);
        $clientRequestId = $this->normalizeRequestId($clientRequestId);

        $existing = $this->existing($member, $clientRequestId);
        if ($existing !== null) {
            return $this->replayed($existing, null, $body);
        }
        $this->throttle($member, self::MEMBER_SENDS_PER_MINUTE);

        try {
            return DB::transaction(function () use ($member, $employee, $body, $subject, $reason, $clientRequestId): EmployeeLogisticsMessage {
                $thread = new EmployeeLogisticsThread;
                $thread->forceFill(['member_user_id' => $member->getKey(), 'subject' => $subject, 'reason' => $reason, 'message_count' => 0]);
                $this->snapshotMember($thread, $member, $employee);
                $thread->save();

                return $this->append($thread, $member, EmployeeLogisticsMessage::SIDE_MEMBER, $this->memberName($member, $employee), $body, $clientRequestId);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->replayed($this->existing($member, $clientRequestId) ?? throw $exception, null, $body);
        }
    }

    public function replyAsMember(User $member, int $threadId, string $body, string $clientRequestId): EmployeeLogisticsMessage
    {
        $employee = $this->requireMember($member);
        $body = $this->normalizeBody($body);
        $clientRequestId = $this->normalizeRequestId($clientRequestId);
        $this->memberThread($member, $threadId);

        $existing = $this->existing($member, $clientRequestId);
        if ($existing !== null) {
            return $this->replayed($existing, $threadId, $body);
        }
        $this->throttle($member, self::MEMBER_SENDS_PER_MINUTE);

        try {
            return DB::transaction(function () use ($member, $employee, $threadId, $body, $clientRequestId): EmployeeLogisticsMessage {
                $thread = EmployeeLogisticsThread::query()->whereKey($threadId)
                    ->where('member_user_id', $member->getKey())->lockForUpdate()->firstOrFail();
                $this->snapshotMember($thread, $member, $employee);

                return $this->append($thread, $member, EmployeeLogisticsMessage::SIDE_MEMBER, $this->memberName($member, $employee), $body, $clientRequestId);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->replayed($this->existing($member, $clientRequestId) ?? throw $exception, $threadId, $body);
        }
    }

    /**
     * The member's own conversations, most recent activity first.
     *
     * @return array{threads: Collection<int, EmployeeLogisticsThread>, hasMore: bool}
     */
    public function memberThreads(User $member, int $limit = self::THREAD_PAGE_SIZE): array
    {
        $this->requireMember($member);

        return $this->window(EmployeeLogisticsThread::query()->where('member_user_id', $member->getKey()), $limit);
    }

    public function memberThread(User $member, int $threadId): EmployeeLogisticsThread
    {
        $this->requireMember($member);

        return EmployeeLogisticsThread::query()->whereKey($threadId)
            ->where('member_user_id', $member->getKey())->firstOrFail();
    }

    public function memberUnreadCount(User $member): int
    {
        if (! $this->access->canUseMemberInbox($member)) {
            return 0;
        }

        return EmployeeLogisticsThread::query()->where('member_user_id', $member->getKey())->unreadForMember()->count();
    }

    /** Record that the member has seen this conversation up to the newest message shown to them. */
    public function markReadByMember(User $member, int $threadId, int $seenUpToMessageId): void
    {
        $thread = $this->memberThread($member, $threadId);
        $upTo = $this->clampSeen($thread, $seenUpToMessageId);
        if ($upTo === null) {
            return;
        }

        EmployeeLogisticsThread::query()->whereKey($thread->getKey())
            ->where('member_user_id', $member->getKey())
            ->where(fn (Builder $q) => $q->whereNull('member_read_message_id')->orWhere('member_read_message_id', '<', $upTo))
            ->update(['member_read_message_id' => $upTo]);
    }

    // ---------------------------------------------------------------- Logistics side

    public function replyAsLogistics(User $responder, int $threadId, string $body, string $clientRequestId): EmployeeLogisticsMessage
    {
        if (! $this->access->canReplyAsLogistics($responder)) {
            throw new AuthorizationException('You do not have permission to reply to employee messages.');
        }
        $body = $this->normalizeBody($body);
        $clientRequestId = $this->normalizeRequestId($clientRequestId);
        EmployeeLogisticsThread::query()->whereKey($threadId)->firstOrFail();

        $existing = $this->existing($responder, $clientRequestId);
        if ($existing !== null) {
            return $this->replayed($existing, $threadId, $body);
        }
        $this->throttle($responder, self::LOGISTICS_SENDS_PER_MINUTE);

        try {
            return DB::transaction(function () use ($responder, $threadId, $body, $clientRequestId): EmployeeLogisticsMessage {
                $thread = EmployeeLogisticsThread::query()->whereKey($threadId)->lockForUpdate()->firstOrFail();

                return $this->append($thread, $responder, EmployeeLogisticsMessage::SIDE_LOGISTICS, $this->userName($responder), $body, $clientRequestId);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->replayed($this->existing($responder, $clientRequestId) ?? throw $exception, $threadId, $body);
        }
    }

    /**
     * The shared Logistics inbox, most recent activity first.
     *
     * @return array{threads: Collection<int, EmployeeLogisticsThread>, hasMore: bool}
     */
    public function logisticsThreads(User $viewer, string $filter = self::FILTER_ALL, ?string $search = null, int $limit = self::THREAD_PAGE_SIZE, ?string $reason = null): array
    {
        $this->requireLogisticsViewer($viewer);
        $query = EmployeeLogisticsThread::query();

        if (EmployeeMessageReason::isValid($reason)) {
            $query->where('reason', $reason);
        }

        if ($filter === self::FILTER_UNREAD) {
            $query->unreadForLogistics();
        } elseif ($filter === self::FILTER_AWAITING_REPLY) {
            $query->where('last_message_side', EmployeeLogisticsMessage::SIDE_MEMBER);
        }

        $term = $this->searchTerm($search);
        if ($term !== null) {
            $like = '%'.$term.'%';
            $reasonKeys = EmployeeMessageReason::keysMatching(mb_strtolower(trim((string) $search)));
            $query->where(function (Builder $q) use ($like, $reasonKeys): void {
                if ($reasonKeys !== []) {
                    $q->orWhereIn('reason', $reasonKeys);
                }
                foreach (['member_name', 'member_employee_number', 'member_station', 'subject', 'last_message_preview'] as $column) {
                    $q->orWhereRaw('LOWER('.$column.") LIKE ? ESCAPE '!'", [$like]);
                }
                $q->orWhereExists(fn ($messages) => $messages->selectRaw('1')->from('employee_logistics_messages')
                    ->whereColumn('employee_logistics_messages.thread_id', 'employee_logistics_threads.id')
                    ->whereRaw("LOWER(employee_logistics_messages.body) LIKE ? ESCAPE '!'", [$like]));
            });
        }

        return $this->window($query, $limit);
    }

    public function logisticsThread(User $viewer, int $threadId): EmployeeLogisticsThread
    {
        $this->requireLogisticsViewer($viewer);

        return EmployeeLogisticsThread::query()->whereKey($threadId)->firstOrFail();
    }

    /** Conversations whose newest message is the member's: the team still owes a reply. */
    public function logisticsAwaitingCount(User $viewer): int
    {
        if (! $this->access->canViewLogisticsInbox($viewer)) {
            return 0;
        }

        return EmployeeLogisticsThread::query()->where('last_message_side', EmployeeLogisticsMessage::SIDE_MEMBER)->count();
    }

    public function logisticsUnreadCount(User $viewer): int
    {
        if (! $this->access->canViewLogisticsInbox($viewer)) {
            return 0;
        }

        return EmployeeLogisticsThread::query()->unreadForLogistics()->count();
    }

    /**
     * Record that the Logistics team has seen this conversation up to the newest message an
     * authorized responder actually opened. A dashboard preview or poll never calls this.
     */
    public function markReadByLogistics(User $viewer, int $threadId, int $seenUpToMessageId): void
    {
        $thread = $this->logisticsThread($viewer, $threadId);
        $upTo = $this->clampSeen($thread, $seenUpToMessageId);
        if ($upTo === null) {
            return;
        }

        EmployeeLogisticsThread::query()->whereKey($thread->getKey())
            ->where(fn (Builder $q) => $q->whereNull('logistics_read_message_id')->orWhere('logistics_read_message_id', '<', $upTo))
            ->update([
                'logistics_read_message_id' => $upTo,
                'logistics_read_by_user_id' => $viewer->getKey(),
                'logistics_read_at' => now(),
            ]);
    }

    // ---------------------------------------------------------------- shared reads

    /**
     * The newest messages of an already-authorized thread, oldest first.
     *
     * @return array{messages: Collection<int, EmployeeLogisticsMessage>, hasEarlier: bool}
     */
    public function messagesFor(EmployeeLogisticsThread $thread, int $limit = self::THREAD_MESSAGE_LIMIT): array
    {
        $limit = max(1, min($limit, self::THREAD_MESSAGE_LIMIT));
        $rows = EmployeeLogisticsMessage::query()->where('thread_id', $thread->getKey())->orderByDesc('id')->limit($limit + 1)
            ->get(['id', 'thread_id', 'author_user_id', 'author_side', 'author_name', 'body', 'created_at']);
        $hasEarlier = $rows->count() > $limit;

        return ['messages' => $rows->take($limit)->reverse()->values(), 'hasEarlier' => $hasEarlier];
    }

    // ---------------------------------------------------------------- internals

    private function requireMember(User $member): Employee
    {
        return $this->access->memberEmployee($member)
            ?? throw new AuthorizationException('Your account cannot use Employee Portal messages.');
    }

    private function requireLogisticsViewer(User $viewer): void
    {
        if (! $this->access->canViewLogisticsInbox($viewer)) {
            throw new AuthorizationException('You do not have permission to view employee messages.');
        }
    }

    private function append(EmployeeLogisticsThread $thread, User $author, string $side, string $authorName, string $body, string $clientRequestId): EmployeeLogisticsMessage
    {
        $message = new EmployeeLogisticsMessage;
        $message->forceFill([
            'thread_id' => $thread->getKey(),
            'author_user_id' => $author->getKey(),
            'author_side' => $side,
            'author_name' => $authorName,
            'body' => $body,
            'client_request_id' => $clientRequestId,
        ])->save();

        $id = (int) $message->getKey();
        $thread->forceFill([
            'last_message_preview' => $this->preview($body),
            'last_message_side' => $side,
            'last_message_at' => $message->created_at,
            'message_count' => $thread->message_count + 1,
            $side === EmployeeLogisticsMessage::SIDE_MEMBER ? 'last_member_message_id' : 'last_logistics_message_id' => $id,
        ])->save();
        $message->setRelation('thread', $thread);
        $this->announce($thread, $message, $author, $side);

        return $message;
    }

    /**
     * Tell the other side through the existing notification pipeline (docs section 10): a member's new
     * message or reply alerts the Support Services audience; a Support Services reply alerts the member.
     * Queued after the surrounding transaction commits and deduplicated per message, so a retried or
     * replayed send (which never reaches append()) cannot alert twice, and a notification problem can
     * never fail the write. Eligibility is decided again by the evaluator for each recipient.
     */
    private function announce(EmployeeLogisticsThread $thread, EmployeeLogisticsMessage $message, User $author, string $side): void
    {
        try {
            $fromMember = $side === EmployeeLogisticsMessage::SIDE_MEMBER;
            $notification = new SupportServicesMessageNotification(
                $fromMember ? SupportServicesMessageNotification::AUDIENCE_SUPPORT : SupportServicesMessageNotification::AUDIENCE_MEMBER,
                (int) $thread->getKey(),
                (int) $message->getKey(),
                (string) $thread->member_name,
                EmployeeMessageReason::label($thread->reason),
                (string) $thread->last_message_preview,
            );
            $candidates = null;
            if (! $fromMember) {
                $member = User::query()->find($thread->member_user_id);
                if (! $member instanceof User) {
                    return;
                }
                $candidates = [$member];
            }

            app(NotificationDispatchService::class)->dispatch(
                $notification->categoryKey(),
                $notification,
                $candidates,
                SupportServicesMessageNotification::eventKey((int) $message->getKey()),
                (int) $author->getKey(),
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function existing(User $author, string $clientRequestId): ?EmployeeLogisticsMessage
    {
        return EmployeeLogisticsMessage::query()
            ->where('author_user_id', $author->getKey())
            ->where('client_request_id', $clientRequestId)
            ->first();
    }

    /** A retried send: hand back the original, but never accept different text under the same id. */
    private function replayed(EmployeeLogisticsMessage $existing, ?int $threadId, string $body): EmployeeLogisticsMessage
    {
        $wrongPlace = $threadId !== null
            ? $existing->thread_id !== $threadId
            // A new conversation's id must be the one that opened it, never a later reply's.
            : EmployeeLogisticsMessage::query()->where('thread_id', $existing->thread_id)->where('id', '<', $existing->id)->exists();
        if ($wrongPlace || $existing->body !== $body) {
            throw ValidationException::withMessages([
                'body' => 'This send was already used for a different message. Reload the page and send again.',
            ]);
        }

        return $existing;
    }

    private function throttle(User $actor, int $perMinute): void
    {
        if (! RateLimiter::attempt('employee-logistics-message:'.$actor->getKey(), $perMinute, static fn (): bool => true, 60)) {
            throw ValidationException::withMessages([
                'body' => 'You are sending messages too quickly. Wait a minute and try again. Your message is still here.',
            ]);
        }
    }

    private function normalizeBody(string $body): string
    {
        if (! mb_check_encoding($body, 'UTF-8')) {
            throw ValidationException::withMessages(['body' => 'The message contains characters that cannot be saved.']);
        }
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = (string) preg_replace('/[^\P{Cc}\n\t]/u', '', $body);
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Type a message before sending.']);
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw ValidationException::withMessages([
                'body' => 'Messages can be up to '.number_format(self::MAX_BODY_LENGTH).' characters.',
            ]);
        }

        return $body;
    }

    private function normalizeSubject(?string $subject): ?string
    {
        if ($subject === null || ! mb_check_encoding($subject, 'UTF-8')) {
            return null;
        }
        $subject = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{Cc}/u', ' ', $subject)));
        if ($subject === '') {
            return null;
        }
        if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            throw ValidationException::withMessages([
                'subject' => 'Subjects can be up to '.self::MAX_SUBJECT_LENGTH.' characters.',
            ]);
        }

        return $subject;
    }

    private function normalizeReason(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);
        if ($reason === null || $reason === '') {
            return null;
        }
        if (! EmployeeMessageReason::isValid($reason)) {
            throw ValidationException::withMessages(['reason' => 'Choose one of the reasons in the list.']);
        }

        return $reason;
    }

    private function normalizeRequestId(string $clientRequestId): string
    {
        $clientRequestId = trim($clientRequestId);
        if (preg_match('/^[A-Za-z0-9-]{8,64}$/D', $clientRequestId) !== 1) {
            throw ValidationException::withMessages(['body' => 'This page is out of date. Reload it and send again.']);
        }

        return $clientRequestId;
    }

    private function preview(string $body): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $body));

        return mb_strlen($flat) > self::PREVIEW_LENGTH ? rtrim(mb_substr($flat, 0, self::PREVIEW_LENGTH - 1)).'…' : $flat;
    }

    private function searchTerm(?string $search): ?string
    {
        $term = mb_strtolower(trim((string) $search));
        if ($term === '') {
            return null;
        }

        return strtr(mb_substr($term, 0, 100), ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    /** Never record "seen" beyond a message that exists in this thread. */
    private function clampSeen(EmployeeLogisticsThread $thread, int $seenUpToMessageId): ?int
    {
        $newest = max((int) $thread->last_member_message_id, (int) $thread->last_logistics_message_id);
        $upTo = min($seenUpToMessageId, $newest);

        return $upTo > 0 ? $upTo : null;
    }

    /**
     * @param  Builder<EmployeeLogisticsThread>  $query
     * @return array{threads: Collection<int, EmployeeLogisticsThread>, hasMore: bool}
     */
    private function window(Builder $query, int $limit): array
    {
        $limit = max(1, min($limit, 500));
        $rows = $query->orderByDesc('last_message_at')->orderByDesc('id')->limit($limit + 1)->get();

        return ['threads' => $rows->take($limit)->values(), 'hasMore' => $rows->count() > $limit];
    }

    private function snapshotMember(EmployeeLogisticsThread $thread, User $member, Employee $employee): void
    {
        $thread->forceFill([
            'employee_profile_id' => $employee->getKey(),
            'member_name' => mb_substr($this->memberName($member, $employee), 0, 160),
            'member_employee_number' => $this->trimmed($employee->employee_id ?? $member->employee_id, 32),
            'member_rank' => $this->trimmed($employee->rank, 80),
            'member_station' => $this->trimmed($employee->station, 80),
        ]);
    }

    private function memberName(User $member, Employee $employee): string
    {
        foreach ([$employee->display_name, $employee->name, $member->display_name ?? null, $member->name] as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return mb_substr($value, 0, 160);
            }
        }

        return 'Member';
    }

    private function userName(User $user): string
    {
        foreach ([$user->display_name ?? null, $user->name] as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return mb_substr($value, 0, 160);
            }
        }

        return 'Support Services';
    }

    private function trimmed(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
