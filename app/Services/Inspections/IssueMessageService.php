<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Services\Captain5\Captain5IssueScope;
use App\Services\Defects\DefectParticipants;
use App\Services\Security\Captain5Access;
use App\Support\Fleet\InspectionReviewers;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Append-only conversation on a daily inspection (docs/daily-inspection-continuity.md section 4.4).
 *
 * Two thread shapes:
 *  - general inspection thread: inspection set, defect null;
 *  - issue thread: defect set (inspection is optional context).
 *
 * Reviewers (same rule as ApparatusInspectionPolicy::approve OR Fleet manage) may read and
 * write everything, including internal notes. A member may only read member-visible messages
 * and only post member-visible messages, on an inspection they own or an issue they follow.
 * Internal notes are never returned to a member by any method here.
 *
 * Captain 5 (docs/captain5-ems-operations-center.md section 5): a member who may act as Captain 5
 * reads and writes like a reviewer, but only on in-scope issues and on Rescue inspections (or an
 * inspection that observed an in-scope issue), and writes with author context `captain5`. A
 * view-only Captain 5 officer reads member-visible messages of those threads only. Writing as Captain 5
 * is opt-in (`as_captain5`), and an inspection's general thread is Captain 5's only for Rescue units.
 */
final class IssueMessageService
{
    public const MAX_BODY = 4000;

    public function __construct(
        private readonly EmployeeInspectionHistoryService $history,
        private readonly DefectParticipants $participants,
        private readonly InspectionNotificationDispatcher $notifications,
        private readonly Captain5Access $captain5Access,
        private readonly Captain5IssueScope $captain5Scope,
    ) {}

    /**
     * @param  array{inspection_id?: int|null, defect_id?: int|null, visibility?: string, body?: mixed, client_message_id?: string|null, corrects_message_id?: int|null, kind?: string|null, notify?: bool}  $data
     *                                                                                                                                                                                                              `notify=false` suppresses the automatic notification (a caller that will call
     *                                                                                                                                                                                                              InspectionNotificationDispatcher itself); `kind=clarification_requested` labels a
     *                                                                                                                                                                                                              reviewer message to the inspection owner as a clarification request.
     *
     * @throws ValidationException invalid body/visibility/target
     * @throws AuthorizationException the author may not post to that thread (forged or unrelated ids included)
     * @throws ModelNotFoundException a reviewer referenced an id that does not exist
     */
    public function post(User $author, array $data): ApparatusIssueMessage
    {
        $validated = Validator::make($data, [
            'inspection_id' => ['nullable', 'integer', 'min:1'],
            'defect_id' => ['nullable', 'integer', 'min:1'],
            'visibility' => ['nullable', Rule::in([ApparatusIssueMessage::VISIBILITY_MEMBER, ApparatusIssueMessage::VISIBILITY_INTERNAL])],
            'body' => ['required', 'string', 'max:'.self::MAX_BODY],
            'client_message_id' => ['nullable', 'uuid'],
            'corrects_message_id' => ['nullable', 'integer', 'min:1'],
            'kind' => ['nullable', 'string', 'max:40'],
            'as_captain5' => ['nullable', 'boolean'],
        ])->validate();

        try {
            return DB::transaction(fn (): ApparatusIssueMessage => $this->store($author, $validated, (bool) ($data['notify'] ?? true)), 3);
        } catch (UniqueConstraintViolationException $exception) {
            // Two retries of one client message raced; the loser returns the winner's row.
            $existing = $this->existing($author, $validated['client_message_id'] ?? null);
            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * Append a correction. The original stays visible; the new row points at it and
     * inherits its thread and visibility. Only the original author or a reviewer may correct.
     */
    public function correct(User $author, int $messageId, string $body, ?string $clientMessageId = null): ApparatusIssueMessage
    {
        return $this->post($author, [
            'body' => $body,
            'corrects_message_id' => $messageId,
            'client_message_id' => $clientMessageId,
        ]);
    }

    /**
     * Messages the viewer may see, oldest first. An inspection target is its general
     * thread (defect null); a defect target is that issue's thread. Members never
     * receive internal messages.
     *
     * @return Collection<int, ApparatusIssueMessage>
     *
     * @throws AuthorizationException
     */
    public function threadFor(User $viewer, ApparatusInspection|ApparatusDefect $target): Collection
    {
        $inspection = $target instanceof ApparatusInspection ? $target : null;
        $defect = $target instanceof ApparatusDefect ? $target : null;
        $isReviewer = InspectionReviewers::canReview($viewer);
        $captain5 = ! $isReviewer && $this->captain5Reads($viewer, $inspection, $defect);
        $seesInternal = $isReviewer || ($captain5 && $this->captain5Writes($viewer, $inspection, $defect));
        $this->authorizeThread($viewer, $isReviewer || $captain5, $inspection, $defect);

        $query = ApparatusIssueMessage::query()->with('author:id,name,display_name');
        if ($target instanceof ApparatusDefect) {
            $query->where('apparatus_defect_id', $target->getKey());
        } else {
            $query->where('apparatus_inspection_id', $target->getKey())->whereNull('apparatus_defect_id');
        }
        if (! $seesInternal) {
            $query->memberVisible();
        }

        return $query->orderBy('created_at')->orderBy('id')->get();
    }

    /**
     * Member-visible threads for the issues one owned inspection reported or observed, loaded in a
     * constant number of queries. Authorization happens once for the page: the viewer must own the
     * inspection, and an issue qualifies only when this inspection created, first reported or
     * observed it on the same physical vehicle. Internal messages are never returned, even to a
     * viewer who also holds a reviewer role.
     *
     * @param  list<int>  $defectIds
     * @return array<int, Collection<int, ApparatusIssueMessage>> defect id => messages (oldest first); ids that do not qualify are omitted
     *
     * @throws AuthorizationException
     */
    public function memberThreadsForInspection(User $viewer, ApparatusInspection $inspection, array $defectIds): array
    {
        if (! $this->history->owns($viewer, $inspection)) {
            throw new AuthorizationException('You can only use the threads of an inspection you submitted.');
        }
        if ($defectIds === []) {
            return [];
        }

        $observed = DB::table('apparatus_defect_observations')
            ->where('apparatus_inspection_id', $inspection->getKey())
            ->whereIn('apparatus_defect_id', $defectIds)
            ->pluck('apparatus_defect_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $physicalId = (int) ($inspection->processed_apparatus_id ?? $inspection->apparatus_id);
        $allowed = ApparatusDefect::query()
            ->whereIn('id', $defectIds)
            ->where('apparatus_id', $physicalId)
            ->get(['id', 'apparatus_inspection_id', 'first_reported_inspection_id'])
            ->filter(fn (ApparatusDefect $defect): bool => (int) $defect->apparatus_inspection_id === (int) $inspection->getKey()
                || (int) $defect->first_reported_inspection_id === (int) $inspection->getKey()
                || in_array((int) $defect->getKey(), $observed, true))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        if ($allowed === []) {
            return [];
        }

        $byDefect = ApparatusIssueMessage::query()
            ->with('author:id,name,display_name')
            ->whereIn('apparatus_defect_id', $allowed)
            ->memberVisible()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('apparatus_defect_id');

        $threads = [];
        foreach ($allowed as $id) {
            $threads[$id] = $byDefect->get($id, new Collection);
        }

        return $threads;
    }

    public function canPost(User $user, ApparatusInspection|ApparatusDefect $target, string $visibility = ApparatusIssueMessage::VISIBILITY_MEMBER): bool
    {
        try {
            if ($target instanceof ApparatusInspection && $target->test_excluded_at !== null) {
                return false;
            }
            $inspection = $target instanceof ApparatusInspection ? $target : null;
            $defect = $target instanceof ApparatusDefect ? $target : null;
            $isStaff = InspectionReviewers::canReview($user) || $this->captain5Writes($user, $inspection, $defect);
            if (! $isStaff && $visibility !== ApparatusIssueMessage::VISIBILITY_MEMBER) {
                return false;
            }
            $this->authorizeThread($user, $isStaff, $inspection, $defect);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    /** @param array<string, mixed> $data validated */
    private function store(User $author, array $data, bool $notify): ApparatusIssueMessage
    {
        $clientId = $data['client_message_id'] ?? null;
        $existing = $this->existing($author, $clientId);
        if ($existing !== null) {
            return $existing;
        }

        $isReviewer = InspectionReviewers::canReview($author);
        $original = null;
        $captain5 = false;
        if (! empty($data['corrects_message_id'])) {
            $original = ApparatusIssueMessage::query()->find($data['corrects_message_id']);
            if ($original === null) {
                throw $this->missing($isReviewer, 'The message to correct does not exist.');
            }
            if (! $isReviewer && (int) $original->author_user_id !== (int) $author->getKey()) {
                throw new AuthorizationException('Only the author or a reviewer may correct a message.');
            }
            // A correction lives in the same thread, with the same audience, as the original.
            $data['inspection_id'] = $original->apparatus_inspection_id;
            $data['defect_id'] = $original->apparatus_defect_id;
            $data['visibility'] = $original->visibility;
        }

        $inspectionId = isset($data['inspection_id']) ? (int) $data['inspection_id'] : null;
        $defectId = isset($data['defect_id']) ? (int) $data['defect_id'] : null;
        if ($inspectionId === null && $defectId === null) {
            throw ValidationException::withMessages(['inspection_id' => 'Choose an inspection or an issue to write to.']);
        }
        // Fail closed: a reviewer must choose the audience. Defaulting to "member" would publish an
        // internal note whenever a form forgot the field. A member can only ever write member-visible.
        if ($isReviewer && empty($data['visibility'])) {
            throw ValidationException::withMessages(['visibility' => 'Choose who can see this message: the member or internal only.']);
        }
        $visibility = $data['visibility'] ?? ApparatusIssueMessage::VISIBILITY_MEMBER;

        $inspection = $inspectionId !== null ? ApparatusInspection::query()->find($inspectionId) : null;
        $defect = $defectId !== null ? ApparatusDefect::query()->find($defectId) : null;
        if (($inspectionId !== null && $inspection === null) || ($defectId !== null && $defect === null)) {
            throw $this->missing($isReviewer, 'The inspection or issue does not exist.');
        }
        // Captain 5 authorship is opt-in (only the Captain 5 workspace asks for it), so an officer who
        // also holds a Captain 5 role still replies as a crew member on their own inspection.
        if (! $isReviewer && (bool) ($data['as_captain5'] ?? false) && $this->captain5Writes($author, $inspection, $defect)) {
            // Captain 5 speaks for the department on in-scope threads and, like a reviewer, must choose the audience.
            $captain5 = true;
            if ($original === null && empty($data['visibility'])) {
                throw ValidationException::withMessages(['visibility' => 'Choose who can see this message: the member or internal only.']);
            }
        } elseif (! $isReviewer && $visibility !== ApparatusIssueMessage::VISIBILITY_MEMBER) {
            throw new AuthorizationException('Members cannot write internal notes.');
        }
        $this->authorizeThread($author, $isReviewer || $captain5, $inspection, $defect);
        $inspection?->assertOperational();

        // Snapshot who the author spoke as. A correction keeps the corrected message's context.
        $authorContext = $original !== null
            ? ($original->author_context ?: ApparatusIssueMessage::CONTEXT_MEMBER)
            : ($isReviewer ? ApparatusIssueMessage::CONTEXT_LOGISTICS : ($captain5 ? ApparatusIssueMessage::CONTEXT_CAPTAIN5 : ApparatusIssueMessage::CONTEXT_MEMBER));

        $message = ApparatusIssueMessage::query()->create([
            'apparatus_inspection_id' => $inspection?->getKey(),
            'apparatus_defect_id' => $defect?->getKey(),
            'author_user_id' => $author->getKey(),
            'author_context' => $authorContext,
            'visibility' => $visibility,
            'body' => trim((string) $data['body']),
            'client_message_id' => $clientId,
            'corrects_message_id' => $original?->getKey(),
        ]);

        if ($defect !== null && ! $isReviewer) {
            $this->participants->enroll($defect, $author, $captain5 ? 'captain5' : 'replied', $inspection);
        }
        if ($notify) {
            $this->notifications->messagePosted($message, $data['kind'] ?? null, $inspection);
        }

        return $message;
    }

    private function existing(User $author, ?string $clientMessageId): ?ApparatusIssueMessage
    {
        if ($clientMessageId === null) {
            return null;
        }
        $existing = ApparatusIssueMessage::query()->where('client_message_id', $clientMessageId)->first();
        if ($existing === null) {
            return null;
        }
        if ((int) $existing->author_user_id !== (int) $author->getKey()) {
            throw new AuthorizationException('That client message id belongs to another author.');
        }

        return $existing;
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function authorizeThread(User $user, bool $isReviewer, ?ApparatusInspection $inspection, ?ApparatusDefect $defect): void
    {
        if ($inspection !== null && $defect !== null && ! $this->sharesPhysicalApparatus($inspection, $defect)) {
            if ($isReviewer) {
                throw ValidationException::withMessages(['defect_id' => 'That issue does not belong to this inspection\'s vehicle.']);
            }

            throw new AuthorizationException('That issue does not belong to this inspection.');
        }
        if ($isReviewer) {
            return;
        }

        if ($inspection !== null && ! $this->history->owns($user, $inspection)) {
            throw new AuthorizationException('You can only use the thread of an inspection you submitted.');
        }
        if ($defect !== null && ! $this->mayFollowDefect($user, $defect)) {
            throw new AuthorizationException('You can only use the thread of an issue you reported or follow.');
        }
    }

    /** The physical truck an inspection's findings belong to (COALESCE(processed_apparatus_id, apparatus_id)). */
    private function sharesPhysicalApparatus(ApparatusInspection $inspection, ApparatusDefect $defect): bool
    {
        return (int) ($inspection->processed_apparatus_id ?? $inspection->apparatus_id) === (int) $defect->apparatus_id;
    }

    /** A participant, or the owner of an inspection that reported/observed the issue. */
    private function mayFollowDefect(User $user, ApparatusDefect $defect): bool
    {
        if ($this->participants->isParticipant($defect, $user)) {
            return true;
        }

        $inspectionIds = DB::table('apparatus_defect_observations')
            ->where('apparatus_defect_id', $defect->getKey())
            ->pluck('apparatus_inspection_id')
            ->push($defect->apparatus_inspection_id, $defect->first_reported_inspection_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $inspectionIds !== []
            && $this->history->ownedQuery($user)->whereIn('apparatus_inspections.id', $inspectionIds)->exists();
    }

    /** A Captain 5 officer may read this thread (in-scope issue, or a Rescue / in-scope inspection). */
    private function captain5Reads(User $user, ?ApparatusInspection $inspection, ?ApparatusDefect $defect): bool
    {
        return $this->captain5Access->decide($user)->canViewData && $this->captain5Covers($inspection, $defect);
    }

    /** A Captain 5 actor (not a reviewer, who already writes everywhere) may write to this thread. */
    private function captain5Writes(User $user, ?ApparatusInspection $inspection, ?ApparatusDefect $defect): bool
    {
        if ($inspection === null && $defect === null) {
            return false;
        }
        $decision = $this->captain5Access->decide($user);

        return $decision->canAct && ! $decision->isReviewer && $this->captain5Covers($inspection, $defect);
    }

    private function captain5Covers(?ApparatusInspection $inspection, ?ApparatusDefect $defect): bool
    {
        if ($defect !== null) {
            return $this->captain5Scope->inScope($defect);
        }
        if ($inspection === null) {
            return false;
        }

        // The general thread of an Engine/Ladder inspection is Fleet's too; Captain 5 talks about a
        // medical item on that item's own issue thread instead.
        return $this->captain5Scope->inspectionKind($inspection) === Captain5IssueScope::KIND_RESCUE;
    }

    private function missing(bool $isReviewer, string $message): \Throwable
    {
        // Members get the same answer for "does not exist" and "not yours": no id oracle.
        return $isReviewer ? new ModelNotFoundException($message) : new AuthorizationException('Not available.');
    }
}
