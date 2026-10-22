<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Filament\Employee\Pages\MyVehicleInspections;
use App\Filament\Employee\Pages\MyVehicleInspectionView;
use App\Filament\Resources\InspectionResource;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Notifications\Inspections\InspectionNotificationKind as Kind;
use App\Support\Fleet\DefectActionStatus;
use App\Support\Fleet\InspectionReviewers;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ledger-deduplicated, after-commit, queued notifications for the daily
 * inspection workflow (docs/daily-inspection-continuity.md section 4.5).
 *
 * Contract with callers:
 *  - Call inside the domain transaction. The ledger rows (one per event_key and
 *    recipient, enforced by a unique index) join that transaction, so a rolled
 *    back domain change leaves no ledger row and sends nothing. Delivery itself
 *    runs in DB::afterCommit.
 *  - Nothing here ever throws into the domain flow. A failure is logged and the
 *    ledger row is marked `failed`.
 *  - Only member-visible text is ever used. Internal notes are not accepted.
 *  - `observed_again` never notifies: a known unresolved issue does not repeat-alert.
 */
final class InspectionNotificationDispatcher
{
    public const TIMEZONE = 'America/New_York';

    private const EXCERPT_LENGTH = 240;

    /** @var array{user: int, sent: bool}|null set only while redeliver() replays one ledger row */
    private ?array $replay = null;

    /** A new blocking hold needs a reviewer's decision. */
    public function reviewRequired(ApparatusInspection $inspection): void
    {
        $this->guard('reviewRequired', function () use ($inspection): void {
            $holds = ApparatusInspectionException::query()
                ->where('apparatus_inspection_id', $inspection->getKey())
                ->where('blocks_acceptance', true)
                ->whereNotIn('status', ['resolved', 'dismissed'])
                ->orderBy('id')
                ->get(['id', 'reason']);
            $reasons = $holds->pluck('reason')->map(fn ($reason): string => $this->holdReason((string) $reason))->unique()->values();
            $unit = $this->unitLabel($this->physicalApparatus($inspection), $inspection->designation_at_time);
            $operator = trim((string) $inspection->operator_name) ?: 'A member';
            $body = $reasons->isEmpty()
                ? "{$operator} submitted a Daily Checkout for {$unit} that needs approval."
                : "{$operator} submitted a Daily Checkout for {$unit} that needs approval: ".$reasons->implode('; ').'.';

            $this->queue(
                'inspection:'.$inspection->getKey().':review_required:'.(int) $holds->max('id'),
                Kind::REVIEW_REQUIRED,
                InspectionReviewers::all(),
                fn (User $recipient): array => [
                    'title' => "{$unit}: inspection needs approval",
                    'body' => $body,
                    'url' => $this->adminUrl((int) $inspection->getKey(), 'approval'),
                ],
            );
        });
    }

    /** Significant damage was newly reported or an existing issue was escalated to significant. */
    public function issueEscalated(ApparatusDefect $defect, ApparatusInspection $inspection): void
    {
        $this->guard('issueEscalated', function () use ($defect, $inspection): void {
            $unit = $this->unitLabel($this->physicalApparatus($inspection), $inspection->designation_at_time);
            $operator = trim((string) $inspection->operator_name) ?: 'A member';
            $where = trim((string) $defect->compartment);
            $item = trim((string) $defect->item).($where !== '' ? " ({$where})" : '');

            $this->queue(
                'defect:'.$defect->getKey().':escalated:'.$inspection->getKey(),
                Kind::ISSUE_ESCALATED,
                InspectionReviewers::all(),
                fn (User $recipient): array => [
                    'title' => "{$unit}: significant damage reported",
                    'body' => "{$operator} reported significant damage to {$item} on ".$this->date($inspection->completed_at ?? $inspection->created_at).'.',
                    'url' => $this->adminUrl((int) $inspection->getKey(), 'issue-'.$defect->getKey()),
                ],
            );
        });
    }

    /** Tells the submitter how the inspection was decided. $decision: approved|rejected. */
    public function inspectionDecided(ApparatusInspection $inspection, string $decision): void
    {
        $this->guard('inspectionDecided', function () use ($inspection, $decision): void {
            $decision = match (strtolower($decision)) {
                'approved', 'accepted', 'approve' => 'approved',
                'rejected', 'reject' => 'rejected',
                default => null,
            };
            if ($decision === null) {
                return;
            }
            $unit = $this->unitLabel($this->physicalApparatus($inspection), $inspection->designation_at_time);
            $submitted = $this->date($inspection->completed_at ?? $inspection->created_at);
            $body = $decision === 'approved'
                ? "Your Daily Checkout of {$unit} submitted on {$submitted} was approved."
                : "Your Daily Checkout of {$unit} submitted on {$submitted} was rejected. Open it for details or contact Logistics.";

            $this->queue(
                'inspection:'.$inspection->getKey().':decided:'.$decision,
                Kind::INSPECTION_DECIDED,
                $this->inspectionOwners($inspection),
                fn (User $recipient): array => [
                    'title' => "{$unit}: inspection {$decision}",
                    'body' => $body,
                    'url' => $this->inspectionUrlFor($recipient, (int) $inspection->getKey()),
                ],
            );
        });
    }

    /** A reviewer asked the submitter for more information (member-visible message). */
    public function clarificationRequested(ApparatusInspection $inspection, ApparatusIssueMessage $message): void
    {
        $this->messagePosted($message, Kind::CLARIFICATION_REQUESTED, $inspection);
    }

    /**
     * Routes a newly stored message. Internal messages never notify anyone.
     * $kind lets a caller label a reviewer message to the inspection owner as a clarification request.
     */
    public function messagePosted(ApparatusIssueMessage $message, ?string $kind = null, ?ApparatusInspection $inspection = null): void
    {
        $this->guard('messagePosted', function () use ($message, $kind, $inspection): void {
            if ($message->visibility !== ApparatusIssueMessage::VISIBILITY_MEMBER) {
                return;
            }
            $author = $message->author_user_id !== null ? User::query()->find($message->author_user_id) : null;
            $defect = $message->apparatus_defect_id !== null ? ApparatusDefect::query()->find($message->apparatus_defect_id) : null;
            $inspection ??= $message->apparatus_inspection_id !== null
                ? ApparatusInspection::query()->find($message->apparatus_inspection_id)
                : null;
            $excerpt = $this->excerpt($message->body);
            $eventKey = 'message:'.$message->getKey().':notify';

            if ($author !== null && ! InspectionReviewers::canReview($author)) {
                $this->memberReply($eventKey, $author, $defect, $inspection, $excerpt);

                return;
            }

            if ($defect !== null) {
                $unit = $this->unitLabel($defect->apparatus);
                $this->queue(
                    $eventKey,
                    Kind::ISSUE_MEMBER_UPDATE,
                    $this->defectFollowers($defect),
                    fn (User $recipient): array => [
                        'title' => "{$unit}: update on a reported issue",
                        'body' => 'Logistics wrote about the '.$this->issuePhrase($defect).": {$excerpt}",
                        'url' => $this->urlFor($recipient, $defect),
                    ],
                    $author,
                );

                return;
            }

            if ($inspection === null) {
                return;
            }
            $unit = $this->unitLabel($this->physicalApparatus($inspection), $inspection->designation_at_time);
            $clarify = $kind === Kind::CLARIFICATION_REQUESTED;
            $this->queue(
                $eventKey,
                $clarify ? Kind::CLARIFICATION_REQUESTED : Kind::INSPECTION_MESSAGE,
                $this->inspectionOwners($inspection),
                fn (User $recipient): array => [
                    'title' => $clarify ? "{$unit}: more information needed" : "{$unit}: message about your inspection",
                    'body' => ($clarify ? 'Logistics asked: ' : 'Logistics wrote: ').$excerpt,
                    'url' => $this->inspectionUrlFor($recipient, (int) $inspection->getKey(), 'messages'),
                ],
                $author,
            );
        });
    }

    /** A defect reached a closed-as-fixed outcome (resolved or correction verified). */
    public function issueResolved(ApparatusDefect $defect, ApparatusDefectEvent $event): void
    {
        $this->guard('issueResolved', function () use ($defect, $event): void {
            $unit = $this->unitLabel($defect->apparatus);
            $reportedOn = $this->date($defect->first_reported_at ?? $defect->reported_date ?? $defect->created_at);
            $resolvedOn = $this->date($event->created_at);
            $type = (string) (($event->details['resolution_type'] ?? null) ?: $defect->resolution_type);
            $body = sprintf(
                'The %s reported on %s %s. Logistics marked the issue resolved on %s.',
                $this->issuePhrase($defect),
                $reportedOn,
                $this->resolutionPhrase($type),
                $resolvedOn,
            );
            $actor = $event->actor_user_id !== null ? User::query()->find($event->actor_user_id) : null;

            $this->queue(
                'defect:'.$defect->getKey().':resolved:'.$event->getKey(),
                Kind::ISSUE_RESOLVED,
                $this->defectFollowers($defect),
                fn (User $recipient): array => [
                    'title' => "{$unit}: previously reported issue resolved",
                    'body' => $body,
                    'url' => $this->urlFor($recipient, $defect),
                ],
                $actor,
            );
        });
    }

    /** A member-visible status or message update on an issue (not a resolution). */
    public function issueMemberUpdate(ApparatusDefect $defect, ApparatusDefectEvent $event): void
    {
        $this->guard('issueMemberUpdate', function () use ($defect, $event): void {
            $message = trim((string) $event->member_message);
            if ($message === '') {
                return;
            }
            $unit = $this->unitLabel($defect->apparatus);
            $label = DefectActionStatus::label($event->to_status ?: $defect->action_status);
            $actor = $event->actor_user_id !== null ? User::query()->find($event->actor_user_id) : null;

            $this->queue(
                'defect:'.$defect->getKey().':update:'.$event->getKey(),
                Kind::ISSUE_MEMBER_UPDATE,
                $this->defectFollowers($defect),
                fn (User $recipient): array => [
                    'title' => "{$unit}: update on a reported issue",
                    'body' => 'The '.$this->issuePhrase($defect).": {$label}. ".$this->excerpt($message),
                    'url' => $this->urlFor($recipient, $defect),
                ],
                $actor,
            );
        });
    }

    // ------------------------------------------------------------------ delivery

    /**
     * @param  Collection<int, User>  $candidates
     * @param  Closure(User): array{title: string, body: string, url: string}  $content
     */
    private function queue(string $eventKey, string $kind, Collection $candidates, Closure $content, ?User $actor = null): void
    {
        if ($this->replay !== null) {
            $this->deliverReplay($kind, $candidates, $content, $actor);

            return;
        }

        $fresh = [];
        foreach ($this->distinctRecipients($candidates, $actor) as $recipient) {
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
                    $recipient->notify(new InspectionEventNotification(
                        $kind,
                        $payload['title'],
                        $payload['body'],
                        $payload['url'],
                        $eventKey,
                    ));
                    $this->markDelivery($eventKey, $recipient, 'dispatched');
                } catch (Throwable $exception) {
                    $this->markDelivery($eventKey, $recipient, 'failed');
                    Log::error('Inspection notification could not be dispatched', [
                        'event_key' => $eventKey,
                        'kind' => $kind,
                        'recipient_user_id' => $recipient->getKey(),
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * Re-sends ONE existing ledger row (event key + recipient) that never left `queued`, for
     * inspections:retry-notifications. The copy is rebuilt from current data by the same code
     * path as the first send; no ledger row is created or duplicated. Returns true only when
     * the notification was handed to the notification system for that recipient.
     */
    public function redeliver(string $eventKey, string $kind, int $recipientUserId): bool
    {
        $this->replay = ['user' => $recipientUserId, 'sent' => false];

        try {
            $parts = explode(':', $eventKey);
            $subjectId = (int) ($parts[1] ?? 0);
            $tail = $parts[3] ?? ($parts[2] ?? '');
            match (true) {
                $parts[0] === 'inspection' && ($parts[2] ?? '') === 'review_required' => $this->withInspection($subjectId, fn ($i) => $this->reviewRequired($i)),
                $parts[0] === 'inspection' && ($parts[2] ?? '') === 'decided' => $this->withInspection($subjectId, fn ($i) => $this->inspectionDecided($i, $tail)),
                $parts[0] === 'defect' && ($parts[2] ?? '') === 'escalated' => $this->withDefect($subjectId, fn ($d) => $this->withInspection((int) $tail, fn ($i) => $this->issueEscalated($d, $i))),
                $parts[0] === 'defect' && ($parts[2] ?? '') === 'resolved' => $this->withDefect($subjectId, fn ($d) => $this->withEvent((int) $tail, fn ($e) => $this->issueResolved($d, $e))),
                $parts[0] === 'defect' && ($parts[2] ?? '') === 'update' => $this->withDefect($subjectId, fn ($d) => $this->withEvent((int) $tail, fn ($e) => $this->issueMemberUpdate($d, $e))),
                $parts[0] === 'message' => $this->withMessage($subjectId, fn ($m) => $this->messagePosted($m, $kind === Kind::CLARIFICATION_REQUESTED ? $kind : null)),
                default => Log::warning('Inspection notification retry: unknown event key', ['event_key' => $eventKey]),
            };

            return $this->replay['sent'];
        } finally {
            $this->replay = null;
        }
    }

    /** @param Closure(ApparatusInspection): void $then */
    private function withInspection(int $id, Closure $then): void
    {
        if (($model = ApparatusInspection::query()->find($id)) !== null) {
            $then($model);
        }
    }

    /** @param Closure(ApparatusDefect): void $then */
    private function withDefect(int $id, Closure $then): void
    {
        if (($model = ApparatusDefect::query()->find($id)) !== null) {
            $then($model);
        }
    }

    /** @param Closure(ApparatusDefectEvent): void $then */
    private function withEvent(int $id, Closure $then): void
    {
        if (($model = ApparatusDefectEvent::query()->find($id)) !== null) {
            $then($model);
        }
    }

    /** @param Closure(ApparatusIssueMessage): void $then */
    private function withMessage(int $id, Closure $then): void
    {
        if (($model = ApparatusIssueMessage::query()->find($id)) !== null) {
            $then($model);
        }
    }

    /**
     * @param  Collection<int, User>  $candidates
     * @param  Closure(User): array{title: string, body: string, url: string}  $content
     */
    private function deliverReplay(string $kind, Collection $candidates, Closure $content, ?User $actor): void
    {
        $recipient = $this->distinctRecipients($candidates, $actor)
            ->first(fn (User $user): bool => (int) $user->getKey() === $this->replay['user']);
        if ($recipient === null) {
            return; // no longer an eligible recipient (inactive, now the actor, or no longer following)
        }

        try {
            $payload = $content($recipient);
            $recipient->notify(new InspectionEventNotification($kind, $payload['title'], $payload['body'], $payload['url']));
            $this->replay['sent'] = true;
        } catch (Throwable $exception) {
            Log::error('Inspection notification retry could not be dispatched', [
                'kind' => $kind,
                'recipient_user_id' => $recipient->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function markDelivery(string $eventKey, User $recipient, string $status): void
    {
        try {
            DB::table('inspection_notification_deliveries')
                ->where('event_key', $eventKey)
                ->where('recipient_user_id', $recipient->getKey())
                ->update(['status' => $status, 'updated_at' => now()]);
        } catch (Throwable $exception) {
            Log::error('Inspection notification ledger could not be updated', [
                'event_key' => $eventKey,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function guard(string $step, Closure $work): void
    {
        try {
            // A savepoint inside the caller's transaction: if one ledger statement fails, only the
            // ledger work rolls back and the surrounding PostgreSQL transaction is not left aborted.
            DB::transaction($work);
        } catch (Throwable $exception) {
            Log::error('Inspection notification could not be prepared', [
                'step' => $step,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    // ---------------------------------------------------------------- recipients

    /**
     * Active accounts only, never the acting person (any of their accounts), and one
     * account per employee: the most recently active one.
     *
     * @param  Collection<int, User>  $candidates
     * @return Collection<int, User>
     */
    public function distinctRecipients(Collection $candidates, ?User $actor = null): Collection
    {
        $actorPerson = $actor !== null ? $this->person($actor) : null;

        return $candidates
            ->filter(fn ($user): bool => $user instanceof User)
            ->unique(fn (User $user): int => (int) $user->getKey())
            ->reject(fn (User $user): bool => $actor !== null && (
                (int) $user->getKey() === (int) $actor->getKey() || $this->person($user) === $actorPerson
            ))
            ->filter(fn (User $user): bool => $user->isAuthenticationAllowed())
            ->groupBy(fn (User $user): string => $this->person($user))
            ->map(fn (Collection $accounts): User => $accounts
                ->sortByDesc(fn (User $user): string => sprintf(
                    '%s-%010d',
                    $user->last_login_at?->format('YmdHis') ?? '00000000000000',
                    (int) $user->getKey(),
                ))
                ->first())
            ->values();
    }

    /**
     * One key per human being (personnel number first, then profile, then the account).
     * users.employee_id and users.employee_profile_id are both unique today, so a duplicate
     * account cannot currently be stored; this keeps "one notification per person" true
     * if that ever changes (for example a re-keyed or merged identity).
     */
    private function person(User $user): string
    {
        $number = mb_strtolower(trim((string) $user->employee_id));
        if ($number !== '') {
            return 'emp:'.$number;
        }

        return $user->employee_profile_id !== null
            ? 'profile:'.$user->employee_profile_id
            : 'user:'.$user->getKey();
    }

    /** @return Collection<int, User> */
    private function inspectionOwners(ApparatusInspection $inspection): Collection
    {
        if ($inspection->actor_user_id === null && $inspection->employee_id === null) {
            return collect();
        }

        return User::query()
            ->where(function ($query) use ($inspection): void {
                if ($inspection->actor_user_id !== null) {
                    $query->orWhere('id', $inspection->actor_user_id);
                }
                if ($inspection->employee_id !== null) {
                    $query->orWhere('employee_profile_id', $inspection->employee_id);
                }
            })
            ->get();
    }

    /**
     * Original reporter, every participant, and the assigned admin.
     *
     * @return Collection<int, User>
     */
    private function defectFollowers(ApparatusDefect $defect): Collection
    {
        $ids = DB::table('apparatus_defect_participants')
            ->where('apparatus_defect_id', $defect->getKey())
            ->pluck('user_id')
            ->all();
        if ($defect->first_reported_by_user_id !== null) {
            $ids[] = $defect->first_reported_by_user_id;
        }
        $assigned = $defect->action_details['assigned_to'] ?? null;
        if (is_int($assigned)) { // only a stored integer is a user id; strings are free text
            $ids[] = $assigned;
        }

        return $ids === []
            ? collect()
            : User::query()->whereIn('id', array_values(array_unique(array_map('intval', $ids))))->get();
    }

    private function memberReply(
        string $eventKey,
        User $author,
        ?ApparatusDefect $defect,
        ?ApparatusInspection $inspection,
        string $excerpt,
    ): void {
        $inspectionId = $inspection?->getKey()
            ?? $defect?->last_observed_inspection_id
            ?? $defect?->first_reported_inspection_id
            ?? $defect?->apparatus_inspection_id;
        $unit = $defect !== null
            ? $this->unitLabel($defect->apparatus)
            : $this->unitLabel($inspection !== null ? $this->physicalApparatus($inspection) : null, $inspection?->designation_at_time);
        $name = trim((string) ($author->display_name ?: $author->name)) ?: 'A member';
        $about = $defect !== null ? ' about the '.$this->issuePhrase($defect) : '';

        $this->queue(
            $eventKey,
            Kind::MEMBER_REPLY,
            InspectionReviewers::forThread($defect, $inspection),
            fn (User $recipient): array => [
                'title' => "{$unit}: member reply",
                'body' => "{$name} replied{$about}: {$excerpt}",
                'url' => $inspectionId !== null
                    ? $this->adminUrl((int) $inspectionId, $defect !== null ? 'issue-'.$defect->getKey() : 'messages')
                    : $this->adminIndexUrl(),
            ],
            $author,
        );
    }

    // --------------------------------------------------------------------- links

    public function adminUrl(int $inspectionId, ?string $anchor = null): string
    {
        $url = InspectionResource::getUrl('view', ['record' => $inspectionId], false, 'admin');

        return $anchor !== null ? $url.'#'.$anchor : $url;
    }

    private function adminIndexUrl(): string
    {
        return InspectionResource::getUrl('index', [], false, 'admin');
    }

    /** Reviewers always land on the canonical admin page, members on the employee portal. */
    private function inspectionUrlFor(User $recipient, int $inspectionId, ?string $anchor = null): string
    {
        return InspectionReviewers::canReview($recipient)
            ? $this->adminUrl($inspectionId, $anchor)
            : $this->memberInspectionUrl($inspectionId, $anchor);
    }

    public function memberInspectionUrl(int $inspectionId, ?string $anchor = null): string
    {
        $url = MyVehicleInspectionView::getUrl(['inspection' => $inspectionId], false, 'employee');

        return $anchor !== null ? $url.'#'.$anchor : $url;
    }

    private function memberListUrl(): string
    {
        return MyVehicleInspections::getUrl([], false, 'employee');
    }

    /** Reviewers land on the canonical admin page; members on the inspection they own. */
    private function urlFor(User $recipient, ApparatusDefect $defect): string
    {
        if (InspectionReviewers::canReview($recipient)) {
            $inspectionId = $defect->last_observed_inspection_id
                ?? $defect->first_reported_inspection_id
                ?? $defect->apparatus_inspection_id;

            return $inspectionId !== null
                ? $this->adminUrl((int) $inspectionId, 'issue-'.$defect->getKey())
                : $this->adminIndexUrl();
        }

        return $this->memberDefectUrl($recipient, $defect);
    }

    /**
     * The newest inspection of this member that observed (or originated) the
     * issue. A member can only open inspections they own, so fall back to the list.
     */
    private function memberDefectUrl(User $recipient, ApparatusDefect $defect): string
    {
        $candidateIds = DB::table('apparatus_defect_observations')
            ->where('apparatus_defect_id', $defect->getKey())
            ->pluck('apparatus_inspection_id')
            ->push($defect->apparatus_inspection_id, $defect->first_reported_inspection_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $ownedId = $candidateIds === []
            ? null
            : app(EmployeeInspectionHistoryService::class)->ownedQuery($recipient)
                ->whereIn('apparatus_inspections.id', $candidateIds)
                ->orderByDesc('apparatus_inspections.id')
                ->value('apparatus_inspections.id');

        return $ownedId !== null
            ? $this->memberInspectionUrl((int) $ownedId, 'issue-'.$defect->getKey())
            : $this->memberListUrl();
    }

    // ---------------------------------------------------------------------- copy

    private function holdReason(string $reason): string
    {
        return match ($reason) {
            'rollback' => 'the meter reading is lower than the last recorded reading',
            'member_flagged' => 'the member flagged it for further review',
            'new_significant_damage' => 'new significant damage was reported',
            default => 'it needs a reviewer decision',
        };
    }

    private function resolutionPhrase(string $type): string
    {
        return match ($type) {
            'replacement_installed' => 'has been replaced',
            'spare_issued' => 'has been replaced with a spare',
            'repair_completed' => 'has been repaired',
            'item_located' => 'has been located',
            'report_corrected' => 'was corrected in the record',
            default => 'has been resolved',
        };
    }

    /** "missing portable radio battery" (acronyms such as "SCBA" keep their case). */
    private function issuePhrase(ApparatusDefect $defect): string
    {
        $item = trim((string) $defect->item);
        $second = mb_substr($item, 1, 1);
        $isAcronym = $second !== '' && $second === mb_strtoupper($second) && $second !== mb_strtolower($second);
        if ($item !== '' && ! $isAcronym) {
            $item = Str::lcfirst($item);
        }
        $type = strtolower(trim((string) $defect->issue_type));

        return trim(($type !== '' ? $type.' ' : '').($item !== '' ? $item : 'item'));
    }

    private function unitLabel(?Apparatus $apparatus, ?string $fallback = null): string
    {
        $label = trim((string) ($apparatus?->name ?: $apparatus?->designation ?: $fallback));

        return $label !== '' ? $label : 'The unit';
    }

    private function physicalApparatus(ApparatusInspection $inspection): ?Apparatus
    {
        $id = $inspection->processed_apparatus_id ?? $inspection->apparatus_id;

        return $id !== null ? Apparatus::query()->find($id) : null;
    }

    private function date(mixed $moment): string
    {
        if (! $moment instanceof CarbonInterface) {
            return 'an earlier date';
        }

        return $moment->copy()->setTimezone(self::TIMEZONE)->format('M j');
    }

    private function excerpt(string $text): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        return Str::limit($clean, self::EXCERPT_LENGTH);
    }
}
