<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Domains;

use App\Models\AiAutomation\AiIntakeEvent;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionConcern;
use App\Models\ApparatusIssueMessage;
use App\Services\AiAutomation\BacklogCandidate;
use App\Services\AiAutomation\Contracts\DomainAdapter;
use App\Services\AiAutomation\Finding;
use App\Services\AiAutomation\IntakeFacts;
use App\Services\AiAutomation\ReplyRecord;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Inspections\InspectionNotificationDispatcher;
use App\Services\Inspections\IssueMessageService;
use App\Support\Fleet\DefectActionStatus;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Vehicle findings on a Daily Checkout, and a crew member's message on one of those issue threads.
 *
 * Read side: re-derives everything from the database on every call. Every equipment finding is in scope,
 * including Rescue checkouts and the medical items of Engine/Ladder checkouts (owner decision: a simple Support
 * Services acknowledgment, and a Captain 5 referral where the shared classification says so, inside the existing
 * checkout conversation only). Only scheduled duties are not equipment and stay out. The adapter never reads the
 * Captain 5 scope tables, the EMS inventory or any Captain 5 workflow state; the only thing it learns about a unit
 * is its checklist type (a DailyCheckoutChecklistResolver lookup on the apparatus row), which it hands to the
 * shared classifier as Finding::$apparatusType.
 *
 * Write side: appends ONE `assistant` message through the model, never through IssueMessageService::post, so
 * no staff or follower notification fires. It touches no defect, inspection, unit or readiness field.
 * The record is addressed by a client_message_id derived from the intake id, which makes a repeat harmless.
 */
final class VehicleAdapter implements DomainAdapter
{
    public const SOURCE_INSPECTION = 'apparatus_inspection';

    public const SOURCE_ISSUE_MESSAGE = 'apparatus_issue_message';

    private const KIND_FINDINGS = 'inspection_findings';

    private const KIND_MESSAGE = 'issue_message';

    private const RECORD_TYPE = 'apparatus_issue_message';

    /** Fixed namespace for the per-intake reply id (UUID v5). Never change it: it is what makes a repeat idempotent. */
    private const REPLY_NAMESPACE = '3f6c1a52-8d0e-4b59-a7c4-5e2b9d1f6a30';

    /** Observations that say "this item is a problem on this checkout"; appears_corrected is the member saying Present. */
    private const FINDING_OBSERVATIONS = ['first_reported', 'confirmed_again'];

    /** Pseudo-compartment for scheduled duties (Fire Boat v2): a duty done or not done, never equipment. */
    private const NON_EQUIPMENT_COMPARTMENTS = ['scheduled_duties'];

    /** What DailyCheckoutChecklistResolver::checklistTypeFor() answers for a unit it cannot map. */
    private const UNMAPPED_TYPE = 'unmapped';

    private const NOTE_LIMIT = 300;

    private const TEXT_LIMIT = 1500;

    public function domain(): string
    {
        return 'vehicle';
    }

    public function sourceTypes(): array
    {
        return [self::SOURCE_INSPECTION, self::SOURCE_ISSUE_MESSAGE];
    }

    public function facts(AiIntakeEvent $intake): IntakeFacts
    {
        $id = (int) $intake->source_id;

        return match ($intake->source_type) {
            self::SOURCE_INSPECTION => $this->inspectionFacts($id),
            self::SOURCE_ISSUE_MESSAGE => $this->messageFacts($id),
            default => $this->unusable(self::KIND_FINDINGS, 'unknown_source'),
        };
    }

    public function writeReply(AiIntakeEvent $intake, IntakeFacts $facts, string $text): ReplyRecord
    {
        $body = mb_substr(trim($text), 0, IssueMessageService::MAX_BODY);
        if ($body === '') {
            throw new RuntimeException('An AI Response cannot be empty.');
        }

        $key = $this->replyKey($intake);
        $existing = $this->existingReply($key);
        if ($existing !== null) {
            return $existing;
        }

        [$inspectionId, $defectId] = $this->replyTarget($intake);

        try {
            // A savepoint, so a lost race cannot poison the engine's surrounding PostgreSQL transaction.
            $message = DB::transaction(static fn (): ApparatusIssueMessage => ApparatusIssueMessage::query()->create([
                'apparatus_inspection_id' => $inspectionId,
                'apparatus_defect_id' => $defectId,
                'author_user_id' => null,
                'author_context' => ApparatusIssueMessage::CONTEXT_ASSISTANT,
                'visibility' => ApparatusIssueMessage::VISIBILITY_MEMBER,
                'body' => $body,
                'client_message_id' => $key,
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            $winner = $this->existingReply($key);
            if ($winner === null) {
                throw $exception;
            }

            return $winner;
        }

        return new ReplyRecord(self::RECORD_TYPE, (int) $message->getKey());
    }

    public function memberUrl(AiIntakeEvent $intake): ?string
    {
        try {
            if ($intake->source_type === self::SOURCE_INSPECTION) {
                $inspectionId = (int) $intake->source_id;
                $anchor = 'messages';
            } else {
                $message = ApparatusIssueMessage::query()->find((int) $intake->source_id, ['id', 'apparatus_inspection_id', 'apparatus_defect_id']);
                if ($message === null || $message->apparatus_inspection_id === null) {
                    return null;
                }
                $inspectionId = (int) $message->apparatus_inspection_id;
                $anchor = 'issue-'.(int) $message->apparatus_defect_id;
            }

            return app(InspectionNotificationDispatcher::class)->memberInspectionUrl($inspectionId, $anchor);
        } catch (Throwable) {
            return null;
        }
    }

    public function backlogSourceTypes(): array
    {
        return [self::SOURCE_INSPECTION];
    }

    public function backlogCandidates(string $sourceType, int $afterId, int $limit): array
    {
        if ($sourceType !== self::SOURCE_INSPECTION || $limit < 1) {
            return [];
        }

        $chunk = max(50, $limit * 4);
        $cursor = max(0, $afterId);
        $found = [];

        while (true) {
            $rows = $this->backlogQuery($cursor)->limit($chunk)->get(['apparatus_inspections.id', 'apparatus_inspections.completed_at', 'apparatus_inspections.created_at']);
            foreach ($rows as $row) {
                $cursor = (int) $row->id;
                if (! $this->inspectionFacts($cursor)->eligible()) {
                    continue;
                }
                $found[] = new BacklogCandidate(self::SOURCE_INSPECTION, $cursor, 'received', $row->completed_at ?? $row->created_at);
                if (count($found) >= $limit) {
                    return $found;
                }
            }
            if ($rows->count() < $chunk) {
                return $found;
            }
        }
    }

    // ------------------------------------------------------------------ inspection facts

    private function inspectionFacts(int $id): IntakeFacts
    {
        $inspection = ApparatusInspection::query()->find($id);
        if ($inspection === null) {
            return $this->unusable(self::KIND_FINDINGS, 'source_missing');
        }

        $requester = $inspection->actor_user_id !== null ? (int) $inspection->actor_user_id : null;
        $submittedAt = $inspection->completed_at ?? $inspection->created_at;
        $refuse = fn (string $reason, array $findings = [], bool $answered = false): IntakeFacts => new IntakeFacts(
            domain: $this->domain(),
            kind: self::KIND_FINDINGS,
            requesterUserId: $requester,
            findings: $findings,
            alreadyAnswered: $answered,
            ineligibleReason: $reason,
            submittedAt: $submittedAt,
        );

        if ($inspection->test_excluded_at !== null) {
            return $refuse('test_excluded');
        }
        if ($requester === null && $inspection->employee_id === null) {
            return $refuse('no_requester');
        }

        $observations = ApparatusDefectObservation::query()
            ->where('apparatus_inspection_id', $inspection->getKey())
            ->whereIn('observation', self::FINDING_OBSERVATIONS)
            ->orderBy('id')
            ->get();
        if ($observations->isEmpty()) {
            return $refuse('no_findings');
        }

        $defects = ApparatusDefect::query()->whereIn('id', $observations->pluck('apparatus_defect_id')->all())->get()->keyBy('id');
        $scoped = $observations->filter(fn (ApparatusDefectObservation $row): bool => ($defect = $defects->get($row->apparatus_defect_id)) !== null
            && $this->inVehicleScope($defect))->values();
        if ($scoped->isEmpty()) {
            return $refuse('out_of_scope');
        }

        $open = $scoped->filter(fn (ApparatusDefectObservation $row): bool => ! DefectActionStatus::isClosed($defects->get($row->apparatus_defect_id)->action_status))->values();
        if ($open->isEmpty()) {
            return $refuse('issue_closed');
        }

        $defectIds = $open->pluck('apparatus_defect_id')->map(static fn ($id): int => (int) $id)->all();
        $significant = $this->significantDefectIds($inspection, $defectIds);
        // The checklist the member just answered: the inspected unit's, which is what matters on a swapped or reserve truck.
        $type = $this->checklistTypeOf((int) $inspection->apparatus_id);
        $findings = $open->map(fn (ApparatusDefectObservation $row): Finding => $this->finding($row, $defects->get($row->apparatus_defect_id), $significant, $type))->all();
        $answered = $this->inspectionAnswered($inspection, $defectIds, $defects);

        if ($open->every(static fn (ApparatusDefectObservation $row): bool => $row->observation !== 'first_reported')
            && ! array_diff($defectIds, $this->respondedDefectIds($defectIds, $defects, (int) $inspection->getKey()))) {
            return $refuse('repeat_only', $findings, $answered);
        }

        return new IntakeFacts(
            domain: $this->domain(),
            kind: self::KIND_FINDINGS,
            requesterUserId: $requester,
            findings: $findings,
            memberText: $this->memberText($inspection, $open),
            alreadyAnswered: $answered,
            submittedAt: $submittedAt,
        );
    }

    /**
     * Every equipment finding is in scope: Logistics items, Rescue and the medical items of Engine/Ladder, and items
     * nobody has classified yet. Only scheduled duties are out. Nothing here reads Captain 5 or EMS data.
     */
    private function inVehicleScope(ApparatusDefect $defect): bool
    {
        return ! in_array((string) $defect->compartment_key, self::NON_EQUIPMENT_COMPARTMENTS, true);
    }

    /** The checklist type of a unit, or null when the unit is gone or has no checklist mapping yet. */
    private function checklistTypeOf(int $apparatusId): ?string
    {
        $apparatus = Apparatus::query()->find($apparatusId);
        if (! $apparatus instanceof Apparatus) {
            return null;
        }
        $type = app(DailyCheckoutChecklistResolver::class)->checklistTypeFor($apparatus);

        return $type === '' || $type === self::UNMAPPED_TYPE ? null : $type;
    }

    /** The unit whose checklist a defect was recorded against: its first inspection's unit, else the truck it sits on. */
    private function checklistTypeOfDefect(ApparatusDefect $defect): ?string
    {
        $inspectionId = $defect->first_reported_inspection_id ?? $defect->apparatus_inspection_id;
        $inspected = $inspectionId !== null
            ? ApparatusInspection::query()->whereKey($inspectionId)->value('apparatus_id')
            : null;

        return $this->checklistTypeOf((int) ($inspected ?? $defect->apparatus_id));
    }

    /** @param  list<int>  $defectIds */
    private function significantDefectIds(ApparatusInspection $inspection, array $defectIds): array
    {
        return ApparatusDefectEvent::query()
            ->where('apparatus_inspection_id', $inspection->getKey())
            ->whereIn('apparatus_defect_id', $defectIds)
            ->whereIn('event_type', ['reported', 'observed_again', 'escalated'])
            ->get(['apparatus_defect_id', 'event_type', 'details'])
            ->filter(static fn (ApparatusDefectEvent $event): bool => $event->event_type === 'escalated' || (is_array($event->details) && ($event->details['significant'] ?? false) === true))
            ->map(static fn (ApparatusDefectEvent $event): int => (int) $event->apparatus_defect_id)
            ->unique()
            ->values()
            ->all();
    }

    /** @param  list<int>  $significant */
    private function finding(ApparatusDefectObservation $row, ApparatusDefect $defect, array $significant, ?string $apparatusType): Finding
    {
        $reported = strtolower((string) ($row->reported_status ?: $defect->issue_type));
        $note = trim((string) $row->notes);

        return new Finding(
            itemName: (string) $defect->item,
            condition: in_array($reported, ['missing', 'damaged'], true) ? $reported : 'other',
            significant: in_array((int) $defect->getKey(), $significant, true),
            isNew: $row->observation === 'first_reported',
            note: $note === '' ? null : mb_substr($note, 0, self::NOTE_LIMIT),
            apparatusType: $apparatusType,
            defectId: (int) $defect->getKey(),
        );
    }

    /** Everything the member wrote on this checkout, for classification only. */
    private function memberText(ApparatusInspection $inspection, Collection $observations): ?string
    {
        $parts = $observations->pluck('notes')
            ->merge(ApparatusInspectionConcern::query()->where('apparatus_inspection_id', $inspection->getKey())->orderBy('id')->pluck('description'))
            ->push($inspection->review_flag_explanation)
            ->map(static fn ($text): string => trim((string) $text))
            ->filter()
            ->unique()
            ->implode("\n");

        return $parts === '' ? null : mb_substr($parts, 0, self::TEXT_LIMIT);
    }

    /**
     * A staff (Logistics / Captain 5) or assistant member-visible message exists for this submission after it was made:
     * on the checkout's own thread or on one of its issue threads. A staff note or acknowledgment on those issues counts too.
     *
     * @param  list<int>  $defectIds
     */
    private function inspectionAnswered(ApparatusInspection $inspection, array $defectIds, Collection $defects): bool
    {
        $since = $inspection->created_at;

        $message = ApparatusIssueMessage::query()
            ->memberVisible()
            ->whereIn('author_context', ApparatusIssueMessage::RESPONSE_CONTEXTS)
            ->where('created_at', '>=', $since)
            ->where(static function ($query) use ($inspection, $defectIds): void {
                $query->where(static fn ($thread) => $thread->where('apparatus_inspection_id', $inspection->getKey())->whereNull('apparatus_defect_id'))
                    ->orWhereIn('apparatus_defect_id', $defectIds);
            })
            ->exists();
        if ($message) {
            return true;
        }

        if (ApparatusDefectEvent::query()->whereIn('apparatus_defect_id', $defectIds)->where('created_at', '>=', $since)
            ->whereNotNull('member_message')->where('member_message', '<>', '')->exists()) {
            return true;
        }

        return $defects->filter(static fn (ApparatusDefect $defect): bool => in_array((int) $defect->getKey(), $defectIds, true)
            && $defect->acknowledged_at !== null && $defect->acknowledged_at >= $since)->isNotEmpty();
    }

    /**
     * Defects that already got any staff or assistant response, whenever it was: acknowledged or moved on by staff,
     * a member-visible staff/assistant message, a message sent with an update, or an AI Response sent on an earlier
     * checkout that also reported them.
     *
     * @param  list<int>  $defectIds
     * @return list<int>
     */
    private function respondedDefectIds(array $defectIds, Collection $defects, int $exceptInspectionId): array
    {
        $responded = $defects->filter(static fn (ApparatusDefect $defect): bool => in_array((int) $defect->getKey(), $defectIds, true)
            && ($defect->acknowledged_at !== null || $defect->action_status !== DefectActionStatus::REPORTED))
            ->keys()->map(static fn ($id): int => (int) $id)->all();

        $byMessage = ApparatusIssueMessage::query()->memberVisible()
            ->whereIn('author_context', ApparatusIssueMessage::RESPONSE_CONTEXTS)
            ->whereIn('apparatus_defect_id', $defectIds)
            ->distinct()->pluck('apparatus_defect_id');
        $byEvent = ApparatusDefectEvent::query()->whereIn('apparatus_defect_id', $defectIds)
            ->whereNotNull('member_message')->where('member_message', '<>', '')
            ->distinct()->pluck('apparatus_defect_id');
        $byEarlierReply = DB::table('apparatus_defect_observations as o')
            ->join('ai_intake_events as a', static function ($join): void {
                $join->on('a.source_id', '=', 'o.apparatus_inspection_id')
                    ->where('a.source_type', '=', self::SOURCE_INSPECTION)
                    ->where('a.state', '=', AiIntakeEvent::STATE_SENT);
            })
            ->whereIn('o.apparatus_defect_id', $defectIds)
            ->where('o.apparatus_inspection_id', '<>', $exceptInspectionId)
            ->distinct()->pluck('o.apparatus_defect_id');

        return collect($responded)->merge($byMessage)->merge($byEvent)->merge($byEarlierReply)
            ->map(static fn ($id): int => (int) $id)->unique()->values()->all();
    }

    // ------------------------------------------------------------------ issue message facts

    private function messageFacts(int $id): IntakeFacts
    {
        $message = ApparatusIssueMessage::query()->find($id);
        if ($message === null) {
            return $this->unusable(self::KIND_MESSAGE, 'source_missing');
        }

        $requester = $message->author_user_id !== null ? (int) $message->author_user_id : null;
        $refuse = fn (string $reason, bool $answered = false): IntakeFacts => new IntakeFacts(
            domain: $this->domain(),
            kind: self::KIND_MESSAGE,
            requesterUserId: $requester,
            memberText: (string) $message->body,
            alreadyAnswered: $answered,
            ineligibleReason: $reason,
            submittedAt: $message->created_at,
        );

        if ($message->visibility !== ApparatusIssueMessage::VISIBILITY_MEMBER) {
            return $refuse('internal');
        }
        if ($message->author_context !== ApparatusIssueMessage::CONTEXT_MEMBER || $requester === null) {
            return $refuse('not_member_authored');
        }
        if ($message->corrects_message_id !== null) {
            return $refuse('correction');
        }
        if ($message->apparatus_defect_id === null) {
            return $refuse('not_issue_thread');
        }
        $defect = ApparatusDefect::query()->find($message->apparatus_defect_id);
        if ($defect === null) {
            return $refuse('source_missing');
        }
        if ($message->apparatus_inspection_id !== null
            && ApparatusInspection::query()->whereKey($message->apparatus_inspection_id)->whereNotNull('test_excluded_at')->exists()) {
            return $refuse('test_excluded');
        }
        if (! $this->inVehicleScope($defect)) {
            return $refuse('out_of_scope');
        }
        if (DefectActionStatus::isClosed($defect->action_status)) {
            return $refuse('issue_closed');
        }

        $reported = strtolower((string) $defect->issue_type);

        return new IntakeFacts(
            domain: $this->domain(),
            kind: self::KIND_MESSAGE,
            requesterUserId: $requester,
            findings: [new Finding(
                itemName: (string) $defect->item,
                condition: in_array($reported, ['missing', 'damaged'], true) ? $reported : 'other',
                significant: $defect->severity === 'significant',
                isNew: false,
                apparatusType: $this->checklistTypeOfDefect($defect),
                defectId: (int) $defect->getKey(),
            )],
            memberText: mb_substr((string) $message->body, 0, self::TEXT_LIMIT),
            alreadyAnswered: $this->messageAnswered($message),
            submittedAt: $message->created_at,
            threadKey: 'apparatus_defect:'.$defect->id,
        );
    }

    /** Staff or the assistant wrote on this issue thread after the member did. */
    private function messageAnswered(ApparatusIssueMessage $message): bool
    {
        return ApparatusIssueMessage::query()
            ->memberVisible()
            ->whereIn('author_context', ApparatusIssueMessage::RESPONSE_CONTEXTS)
            ->where('apparatus_defect_id', $message->apparatus_defect_id)
            ->where('id', '>', $message->getKey())
            ->exists()
            || ApparatusDefectEvent::query()->where('apparatus_defect_id', $message->apparatus_defect_id)
                ->where('created_at', '>=', $message->created_at)
                ->whereNotNull('member_message')->where('member_message', '<>', '')->exists();
    }

    // ------------------------------------------------------------------ reply target

    /**
     * Where the one reply goes. A checkout's receipt goes on the checkout's own thread, the "Messages about this
     * inspection" section the submitting member already reads. A reply to a member's issue message goes on that
     * issue thread.
     *
     * @return array{0: int|null, 1: int|null} [inspection id, defect id]
     */
    private function replyTarget(AiIntakeEvent $intake): array
    {
        if ($intake->source_type === self::SOURCE_INSPECTION) {
            return [(int) $intake->source_id, null];
        }

        $message = ApparatusIssueMessage::query()->find((int) $intake->source_id, ['id', 'apparatus_inspection_id', 'apparatus_defect_id']);
        if ($message === null || $message->apparatus_defect_id === null) {
            throw new RuntimeException('The member message this reply answers is gone.');
        }

        return [$message->apparatus_inspection_id !== null ? (int) $message->apparatus_inspection_id : null, (int) $message->apparatus_defect_id];
    }

    private function replyKey(AiIntakeEvent $intake): string
    {
        return Uuid::uuid5(self::REPLY_NAMESPACE, 'ai-response:'.(int) $intake->getKey())->toString();
    }

    private function existingReply(string $key): ?ReplyRecord
    {
        $id = ApparatusIssueMessage::query()->where('client_message_id', $key)->value('id');

        return $id === null ? null : new ReplyRecord(self::RECORD_TYPE, (int) $id);
    }

    // ------------------------------------------------------------------ backlog

    /**
     * Unattended checkouts: a finding on a still-open issue, no member-visible staff or assistant
     * message on the checkout's own thread, and no AI Response already sent or deliberately skipped for it.
     * Scope and the issue-thread checks are exact in inspectionFacts(); this only narrows the scan.
     *
     * @return \Illuminate\Database\Eloquent\Builder<ApparatusInspection>
     */
    private function backlogQuery(int $afterId)
    {
        return ApparatusInspection::query()
            ->operational()
            ->where('apparatus_inspections.id', '>', $afterId)
            ->whereExists(static fn (QueryBuilder $query) => $query->selectRaw('1')
                ->from('apparatus_defect_observations as o')
                ->join('apparatus_defects as d', 'd.id', '=', 'o.apparatus_defect_id')
                ->whereColumn('o.apparatus_inspection_id', 'apparatus_inspections.id')
                ->whereIn('o.observation', self::FINDING_OBSERVATIONS)
                ->where('d.resolved', false)
                ->whereNotIn('d.action_status', DefectActionStatus::CLOSED))
            ->whereNotExists(static fn (QueryBuilder $query) => $query->selectRaw('1')
                ->from('apparatus_issue_messages as m')
                ->whereColumn('m.apparatus_inspection_id', 'apparatus_inspections.id')
                ->whereNull('m.apparatus_defect_id')
                ->where('m.visibility', ApparatusIssueMessage::VISIBILITY_MEMBER)
                ->whereIn('m.author_context', ApparatusIssueMessage::RESPONSE_CONTEXTS))
            ->whereNotExists(static fn (QueryBuilder $query) => $query->selectRaw('1')
                ->from('ai_intake_events as a')
                ->whereColumn('a.source_id', 'apparatus_inspections.id')
                ->where('a.source_type', self::SOURCE_INSPECTION)
                ->whereIn('a.state', [AiIntakeEvent::STATE_SENT, AiIntakeEvent::STATE_SKIPPED]))
            ->orderBy('apparatus_inspections.id');
    }

    private function unusable(string $kind, string $reason): IntakeFacts
    {
        return new IntakeFacts(domain: $this->domain(), kind: $kind, requesterUserId: null, ineligibleReason: $reason);
    }
}
