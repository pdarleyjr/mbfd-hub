<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Domains;

use App\Models\AiAutomation\AiIntakeEvent;
use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Models\EmployeeMessaging\EmployeeLogisticsThread;
use App\Models\User;
use App\Services\AiAutomation\BacklogCandidate;
use App\Services\AiAutomation\Contracts\DomainAdapter;
use App\Services\AiAutomation\IntakeFacts;
use App\Services\AiAutomation\ReplyRecord;
use App\Services\EmployeeMessaging\EmployeeMessageReason;
use App\Services\Security\EmployeeLogisticsMessagingAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Support Services messaging (employee <-> Logistics, docs/employee-logistics-messaging.md) for the AI Response
 * automation. One intake per member-authored message.
 *
 * What the reply is allowed to touch (and what it is not):
 *   - It appends ONE employee_logistics_messages row with author_side = 'assistant', no author user, the
 *     configured sender label, and the intake id as its idempotency key (ai_intake_event_id is unique).
 *   - On the thread it sets last_assistant_message_id and adds one to message_count. Nothing else.
 *     last_message_side, last_member_message_id, last_logistics_message_id, last_message_at,
 *     last_message_preview and the staff read marker are never written, so the thread stays in the staff
 *     "awaiting reply" queue (last_message_side = member), stays unread for staff exactly as before, and keeps
 *     its place in the inbox ordering. The member's own unread marker does see it (isUnreadForMember).
 *   - It never goes through EmployeeLogisticsMessagingService::append(), so SupportServicesMessageNotification,
 *     email and push are never raised. Telling the member in-app is the engine's notifier, not this class.
 *
 * Reason mapping (EmployeeMessageReason, conservative; edit OUT_OF_SCOPE_REASONS to change it):
 *   general, equipment, supplies, bunker_gear_cleaning, and a null reason (conversations from before reasons
 *   existed) are in scope; the key is passed as the category ('general' for null). Bunker gear cleaning and
 *   drop-off messages are in scope by the owner's decision. `uniforms` is out of scope: it is the uniform
 *   entitlement/allowance workflow, where an automatic acknowledgment could be mistaken for a decision.
 */
final class SupportServicesAdapter implements DomainAdapter
{
    public const SOURCE_TYPE = 'employee_logistics_message';

    public const EVENT_KEY = 'received';

    /** @var list<string> reasons whose messages belong to a workflow the assistant must stay out of */
    public const OUT_OF_SCOPE_REASONS = [EmployeeMessageReason::UNIFORMS];

    public function __construct(
        private readonly EmployeeLogisticsMessagingAccess $access,
    ) {}

    public function domain(): string
    {
        return 'support';
    }

    public function sourceTypes(): array
    {
        return [self::SOURCE_TYPE];
    }

    public function facts(AiIntakeEvent $intake): IntakeFacts
    {
        $message = EmployeeLogisticsMessage::query()->find($intake->source_id);
        if (! $message instanceof EmployeeLogisticsMessage) {
            return $this->ineligible(null, 'source_missing');
        }

        $requesterId = $message->author_user_id !== null ? (int) $message->author_user_id : null;
        if ($message->author_side !== EmployeeLogisticsMessage::SIDE_MEMBER) {
            return $this->ineligible($requesterId, 'not_member_message');
        }

        $thread = EmployeeLogisticsThread::query()->find($message->thread_id);
        if (! $thread instanceof EmployeeLogisticsThread) {
            return $this->ineligible($requesterId, 'source_missing');
        }
        if ($requesterId === null || $thread->member_user_id === null || (int) $thread->member_user_id !== $requesterId) {
            return $this->ineligible($requesterId, 'no_requester');
        }

        $category = $thread->reason ?? EmployeeMessageReason::GENERAL;
        $submittedAt = $message->created_at !== null ? CarbonImmutable::instance($message->created_at) : null;

        // Cheapest checks first; each is a decision the engine records, never an error.
        $reason = null;
        if (in_array($thread->reason, self::OUT_OF_SCOPE_REASONS, true)) {
            $reason = 'out_of_scope';
        } elseif (! $this->memberMayStillBeAnswered($requesterId)) {
            $reason = 'member_inactive';
        }

        // What follows this message in the thread: the first response (staff or assistant) and the first
        // newer member message. A newer member message with no response between them supersedes this one.
        $nextResponseId = $this->firstLaterId($message, responses: true);
        $nextMemberId = $this->firstLaterId($message, responses: false);
        if ($reason === null && $nextMemberId !== null && ($nextResponseId === null || $nextMemberId < $nextResponseId)) {
            $reason = 'superseded_by_newer_member_message';
        }

        return new IntakeFacts(
            domain: 'support',
            kind: 'support_message',
            requesterUserId: $requesterId,
            memberText: $this->classificationText($thread, $message),
            category: $category,
            alreadyAnswered: $nextResponseId !== null,
            ineligibleReason: $reason,
            routed: false,
            submittedAt: $submittedAt,
            threadKey: 'employee_logistics_thread:'.$message->thread_id,
        );
    }

    public function writeReply(AiIntakeEvent $intake, IntakeFacts $facts, string $text): ReplyRecord
    {
        $existing = EmployeeLogisticsMessage::query()->where('ai_intake_event_id', $intake->getKey())->first();
        if ($existing instanceof EmployeeLogisticsMessage) {
            return new ReplyRecord(self::SOURCE_TYPE, (int) $existing->getKey());
        }

        $source = EmployeeLogisticsMessage::query()->findOrFail($intake->source_id);
        // Same lock order as the human reply paths (thread row first), so a staff reply and this write serialize.
        $thread = EmployeeLogisticsThread::query()->whereKey($source->thread_id)->lockForUpdate()->firstOrFail();

        $reply = new EmployeeLogisticsMessage;
        $reply->forceFill([
            'thread_id' => $thread->getKey(),
            'author_user_id' => null,
            'author_side' => EmployeeLogisticsMessage::SIDE_ASSISTANT,
            'author_name' => (string) config('ai_automation.sender_label', 'AI Response'),
            'body' => $text,
            'client_request_id' => 'ai-intake-'.$intake->getKey(),
            'ai_intake_event_id' => $intake->getKey(),
        ])->save();

        // Only the assistant marker and the message count. Never the answer markers (see the class comment).
        EmployeeLogisticsThread::query()->whereKey($thread->getKey())->update([
            'last_assistant_message_id' => $reply->getKey(),
            'message_count' => DB::raw('message_count + 1'),
        ]);

        return new ReplyRecord(self::SOURCE_TYPE, (int) $reply->getKey());
    }

    public function memberUrl(AiIntakeEvent $intake): ?string
    {
        $threadId = EmployeeLogisticsMessage::query()->whereKey($intake->source_id)->value('thread_id');

        return $threadId !== null ? '/employee/messages?thread='.(int) $threadId : null;
    }

    public function backlogSourceTypes(): array
    {
        return [self::SOURCE_TYPE];
    }

    public function backlogCandidates(string $sourceType, int $afterId, int $limit): array
    {
        if ($sourceType !== self::SOURCE_TYPE || $limit < 1) {
            return [];
        }

        // A thread's newest message, when it is the member's: no later staff, assistant or member message
        // exists, so it is the one unanswered message of that thread (one candidate per thread).
        $rows = DB::table('employee_logistics_messages as m')
            ->join('employee_logistics_threads as t', 't.id', '=', 'm.thread_id')
            ->where('m.author_side', EmployeeLogisticsMessage::SIDE_MEMBER)
            ->where('m.id', '>', $afterId)
            ->whereNotNull('m.author_user_id')
            ->whereColumn('t.member_user_id', 'm.author_user_id')
            ->where(fn ($q) => $q->whereNull('t.reason')->orWhereNotIn('t.reason', self::OUT_OF_SCOPE_REASONS))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('employee_logistics_messages as n')
                ->whereColumn('n.thread_id', 'm.thread_id')
                ->whereColumn('n.id', '>', 'm.id'))
            ->orderBy('m.id')
            ->limit($limit)
            ->get(['m.id', 'm.created_at']);

        return $rows->map(fn (object $row): BacklogCandidate => new BacklogCandidate(
            self::SOURCE_TYPE,
            (int) $row->id,
            self::EVENT_KEY,
            $row->created_at !== null ? CarbonImmutable::parse($row->created_at) : null,
        ))->values()->all();
    }

    private function ineligible(?int $requesterId, string $reason): IntakeFacts
    {
        return new IntakeFacts(domain: 'support', kind: 'support_message', requesterUserId: $requesterId, ineligibleReason: $reason);
    }

    /** The member's account must still be able to use the member inbox (not disabled or departed). */
    private function memberMayStillBeAnswered(int $userId): bool
    {
        $member = User::query()->find($userId);

        return $member instanceof User && $this->access->canUseMemberInbox($member);
    }

    /** Smallest id of a later message in the same thread: a response (any non-member side) or a member message. */
    private function firstLaterId(EmployeeLogisticsMessage $message, bool $responses): ?int
    {
        $query = EmployeeLogisticsMessage::query()
            ->where('thread_id', $message->thread_id)
            ->where('id', '>', $message->getKey());

        $responses
            ? $query->where('author_side', '!=', EmployeeLogisticsMessage::SIDE_MEMBER)
            : $query->where('author_side', EmployeeLogisticsMessage::SIDE_MEMBER);

        $id = $query->min('id');

        return $id !== null ? (int) $id : null;
    }

    private function classificationText(EmployeeLogisticsThread $thread, EmployeeLogisticsMessage $message): string
    {
        $subject = trim((string) $thread->subject);

        return $subject !== '' ? $subject."\n".$message->body : (string) $message->body;
    }
}
