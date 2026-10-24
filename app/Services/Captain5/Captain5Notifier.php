<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Filament\Employee\Pages\MyVehicleInspections;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\Captain5\Captain5ActingRequest;
use App\Models\CommandCommunicationPost;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Defects\FindingsResult;
use App\Services\Inspections\EmployeeInspectionHistoryService;
use App\Services\Inspections\InspectionNotificationDispatcher;
use App\Services\Security\Captain5Access;
use App\Support\Fleet\DefectActionStatus;
use App\Support\Fleet\InspectionReviewers;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Captain 5 notifications (docs/captain5-ems-operations-center.md section 7).
 *
 * Same contract as InspectionNotificationDispatcher: called inside the domain transaction,
 * one ledger row per (event key, recipient) in `inspection_notification_deliveries`, delivery
 * after commit, never throws into the domain flow, member-visible text only. Event keys start
 * with `captain5:` and inspections:retry-notifications routes them back to redeliver().
 *
 * Routing is deliberately narrow: the on-duty Captain 5 hears about new in-scope findings; an
 * issue update reaches the original reporter, the current duty day's crew of that unit and the
 * people actively working the issue, never every former observer.
 */
final class Captain5Notifier
{
    public const KIND_NEW_FINDINGS = 'captain5_new_findings';

    public const KIND_ESCALATED = 'captain5_significant_damage';

    public const KIND_ISSUE_UPDATE = 'captain5_issue_update';

    public const KIND_ISSUE_RESOLVED = 'captain5_issue_resolved';

    public const KIND_MESSAGE = 'captain5_message';

    public const KIND_MEMBER_REPLY = 'captain5_member_reply';

    public const KIND_COMMUNICATION = 'captain5_communication';

    public const KIND_ACTING_REQUEST = 'captain5_acting_request';

    public const KIND_ACTING_DECISION = 'captain5_acting_decision';

    public const WORKSPACE_PATH = '/daily/captain-5';

    /** Captain 5 events that tell the crew something changed even without a typed message. */
    private const NOTIFYING_EVENTS = ['replacement_available', 'resolved', 'correction_verified', 'dismissed'];

    /** Participants who are actively working an issue (not every former observer). */
    private const ACTIVE_SOURCES = ['replied', 'assigned', 'captain5'];

    private const EXCERPT_LENGTH = 240;

    /** @var array{user: int, key: string, sent: bool}|null */
    private ?array $replay = null;

    public function __construct(
        private readonly Captain5DutyRoster $roster,
        private readonly Captain5IssueScope $scope,
        private readonly InspectionNotificationDispatcher $dispatcher,
        private readonly Captain5Access $access,
    ) {}

    /** New in-scope findings of one submitted inspection: one notification to the on-duty Captain 5. */
    public function newFindings(ApparatusInspection $inspection, FindingsResult $findings): void
    {
        $this->guard('newFindings', function () use ($inspection, $findings): void {
            $ids = array_values(array_unique([...$findings->newDefectIds, ...$findings->significantNewOrEscalatedDefectIds]));
            if ($ids === []) {
                return;
            }
            // Nobody to tell (no eligible Captain 5 at all): skip the scope work on the submit path.
            $onDuty = $this->onDutyRecipients();
            if ($onDuty->isEmpty()) {
                return;
            }
            $defects = $this->scope->filter(ApparatusDefect::query()->whereIn('id', $ids)->orderBy('id')->get());
            if ($defects->isEmpty()) {
                return;
            }
            $unit = $this->unitLabel(Apparatus::query()->find($inspection->processed_apparatus_id ?? $inspection->apparatus_id), $inspection->designation_at_time);
            $operator = trim((string) $inspection->operator_name) ?: 'A member';
            $items = $defects->map(fn (ApparatusDefect $defect): string => $this->issuePhrase($defect))->take(4)->implode(', ');
            $more = $defects->count() > 4 ? ' and '.($defects->count() - 4).' more' : '';
            $significant = $defects->filter(fn (ApparatusDefect $defect): bool => in_array((int) $defect->getKey(), $findings->significantNewOrEscalatedDefectIds, true));

            // ONE notification per inspection for the on-duty Captain 5 (significant damage is its headline).
            $this->queue(
                'captain5:inspection:'.$inspection->getKey().':findings',
                self::KIND_NEW_FINDINGS,
                $onDuty,
                fn (): array => [
                    'title' => $significant->isNotEmpty()
                        ? "{$unit}: significant damage to EMS equipment"
                        : "{$unit}: new EMS equipment issue".($defects->count() > 1 ? 's' : ''),
                    'body' => "{$operator} reported {$items}{$more}.",
                    'url' => $this->workspaceUrl((int) ($significant->first() ?? $defects->first())->getKey()),
                ],
            );

            // The EMS Captain hears about significant damage only, and never twice (not if they are also on duty).
            $onDutyIds = $onDuty->map(fn (User $user): int => (int) $user->getKey())->all();
            $ems = $this->eligible($this->roster->emsCaptainUsers())->reject(fn (User $user): bool => in_array((int) $user->getKey(), $onDutyIds, true));
            if ($significant->isNotEmpty() && $ems->isNotEmpty()) {
                $this->queue(
                    'captain5:inspection:'.$inspection->getKey().':significant',
                    self::KIND_ESCALATED,
                    $ems,
                    fn (): array => [
                        'title' => "{$unit}: significant damage to EMS equipment",
                        'body' => "{$operator} reported significant damage: ".$significant->map(fn (ApparatusDefect $d): string => $this->issuePhrase($d))->implode(', ').'.',
                        'url' => $this->workspaceUrl((int) $significant->first()->getKey()),
                    ],
                );
            }
        });
    }

    /** A Captain 5 action on an issue (called by DefectCorrectiveActionService in Captain 5 mode). */
    public function issueUpdated(ApparatusDefect $defect, ApparatusDefectEvent $event): void
    {
        $this->guard('issueUpdated', function () use ($defect, $event): void {
            $message = trim((string) $event->member_message);
            $resolving = in_array($event->event_type, ['resolved', 'correction_verified'], true);
            $ordered = $event->event_type === 'action_updated' && $event->to_status === DefectActionStatus::ACTION_PENDING
                && $event->from_status !== DefectActionStatus::ACTION_PENDING;
            $reopened = $event->event_type === 'reopened';
            if ($message === '' && ! $ordered && ! $reopened && ! in_array($event->event_type, self::NOTIFYING_EVENTS, true)) {
                return; // acknowledgements and notes stay in history
            }
            $actor = $event->actor_user_id !== null ? User::query()->find($event->actor_user_id) : null;
            $unit = $this->unitLabel($defect->getRelationValue('apparatus'));
            if ($event->event_type === 'dismissed') {
                $this->closedNoAction($defect, $event, $unit, $actor);

                return;
            }
            if ($reopened) {
                $this->reopenedIssue($defect, $event, $unit, $actor);

                return;
            }
            $label = DefectActionStatus::label($event->to_status ?: $defect->action_status);
            $phrase = $this->issuePhrase($defect);
            $body = $resolving
                ? "Captain 5 marked the {$phrase} resolved.".($message !== '' ? ' '.$this->excerpt($message) : '')
                : "The {$phrase}: {$label}.".($message !== '' ? ' '.$this->excerpt($message) : '');

            $this->queue(
                'captain5:defect:'.$defect->getKey().':'.($resolving ? 'resolved' : 'update').':'.$event->getKey(),
                $resolving ? self::KIND_ISSUE_RESOLVED : self::KIND_ISSUE_UPDATE,
                $this->involved($defect),
                fn (User $recipient): array => [
                    'title' => $resolving ? "{$unit}: issue resolved" : "{$unit}: update from Captain 5",
                    'body' => $body,
                    'url' => $this->urlFor($recipient, $defect),
                ],
                $actor,
            );
        });
    }

    /**
     * An issue closed with no action required from the Captain 5 door. The reporter and every
     * participant are always told (the same people the Logistics door tells), plus the crew and
     * Captain 5 officers on this issue, and each person gets exactly ONE alert: this method is the
     * only sender for a Captain 5 close, so InspectionNotificationDispatcher stays out of it.
     */
    private function closedNoAction(ApparatusDefect $defect, ApparatusDefectEvent $event, string $unit, ?User $actor): void
    {
        $this->queue(
            'captain5:defect:'.$defect->getKey().':closed:'.$event->getKey(),
            self::KIND_ISSUE_UPDATE,
            $this->involved($defect)->merge($this->dispatcher->defectFollowers($defect)),
            fn (User $recipient): array => [
                'title' => "{$unit}: issue closed, no action required",
                'body' => $this->dispatcher->closedNoActionBody($defect, $event),
                'url' => $this->urlFor($recipient, $defect),
            ],
            $actor,
        );
    }

    /**
     * A closed issue reopened from the Captain 5 door. Everyone who was told it was closed is told it
     * is open again (one alert each, never the person who reopened it), as the Logistics door does.
     */
    private function reopenedIssue(ApparatusDefect $defect, ApparatusDefectEvent $event, string $unit, ?User $actor): void
    {
        $was = $event->from_status === DefectActionStatus::DISMISSED ? 'closed with no action required' : 'marked resolved';
        $message = trim((string) $event->member_message);
        $body = "The {$this->issuePhrase($defect)} was {$was}, and Captain 5 reopened it. It is being looked at again."
            .($message !== '' ? ' '.$this->excerpt($message) : '');

        $this->queue(
            'captain5:defect:'.$defect->getKey().':reopened:'.$event->getKey(),
            self::KIND_ISSUE_UPDATE,
            $this->involved($defect)->merge($this->dispatcher->defectFollowers($defect)),
            fn (User $recipient): array => [
                'title' => "{$unit}: issue reopened",
                'body' => $body,
                'url' => $this->urlFor($recipient, $defect),
            ],
            $actor,
        );
    }

    /** A Captain 5 message (member-visible) on an issue or inspection thread. */
    public function messagePosted(ApparatusIssueMessage $message, ?string $kind = null, ?ApparatusInspection $inspection = null): void
    {
        $this->guard('messagePosted', function () use ($message, $kind, $inspection): void {
            if ($message->visibility !== ApparatusIssueMessage::VISIBILITY_MEMBER) {
                return;
            }
            $author = $message->author_user_id !== null ? User::query()->find($message->author_user_id) : null;
            $defect = $message->apparatus_defect_id !== null ? ApparatusDefect::query()->find($message->apparatus_defect_id) : null;
            $inspection ??= $message->apparatus_inspection_id !== null ? ApparatusInspection::query()->find($message->apparatus_inspection_id) : null;
            $excerpt = $this->excerpt((string) $message->body);
            $clarify = $kind === 'clarification_requested';

            if ($defect !== null) {
                $unit = $this->unitLabel($defect->getRelationValue('apparatus'));
                $this->queue(
                    'captain5:message:'.$message->getKey().':notify',
                    self::KIND_MESSAGE,
                    $this->involved($defect),
                    fn (User $recipient): array => [
                        'title' => "{$unit}: message from Captain 5",
                        'body' => 'About the '.$this->issuePhrase($defect).": {$excerpt}",
                        'url' => $this->urlFor($recipient, $defect),
                    ],
                    $author,
                );

                return;
            }
            if ($inspection === null) {
                return;
            }
            $unit = $this->unitLabel(Apparatus::query()->find($inspection->processed_apparatus_id ?? $inspection->apparatus_id), $inspection->designation_at_time);
            $this->queue(
                'captain5:message:'.$message->getKey().':notify',
                self::KIND_MESSAGE,
                $this->inspectionOwners($inspection),
                fn (User $recipient): array => [
                    'title' => $clarify ? "{$unit}: Captain 5 needs more information" : "{$unit}: message from Captain 5",
                    'body' => ($clarify ? 'Captain 5 asked: ' : 'Captain 5 wrote: ').$excerpt,
                    'url' => $this->dispatcher->memberInspectionUrl((int) $inspection->getKey(), 'messages'),
                ],
                $author,
            );
        });
    }

    /**
     * A member replied on an in-scope issue: the Captain 5 officers working it hear about it
     * (reviewers already get their own copy from InspectionNotificationDispatcher).
     */
    public function memberReplied(ApparatusIssueMessage $message): void
    {
        $this->guard('memberReplied', function () use ($message): void {
            if ($message->visibility !== ApparatusIssueMessage::VISIBILITY_MEMBER || $message->apparatus_defect_id === null) {
                return;
            }
            $defect = ApparatusDefect::query()->find($message->apparatus_defect_id);
            if ($defect === null || ! $this->scope->inScope($defect)) {
                return;
            }
            $author = $message->author_user_id !== null ? User::query()->find($message->author_user_id) : null;
            $name = trim((string) ($author?->display_name ?: $author?->name)) ?: 'A member';
            $unit = $this->unitLabel($defect->getRelationValue('apparatus'));
            $officers = $this->eligible($this->captain5Actors($defect))
                ->reject(fn (User $user): bool => InspectionReviewers::canReview($user));
            if ($officers->isEmpty()) {
                $officers = $this->onDutyRecipients();
            }

            $this->queue(
                'captain5:message:'.$message->getKey().':reply',
                self::KIND_MEMBER_REPLY,
                $officers,
                fn (): array => [
                    'title' => "{$unit}: member reply",
                    'body' => "{$name} replied about the ".$this->issuePhrase($defect).': '.$this->excerpt((string) $message->body),
                    'url' => $this->workspaceUrl((int) $defect->getKey()),
                ],
                $author,
            );
        });
    }

    /**
     * An administrator posted to the Captain 5 channel: only the on-duty Captain 5 is notified
     * immediately (every other eligible officer sees it in the feed).
     *
     * @param  array{public_id: string, subject: ?string, body: string, author_user_id: int, audience: string}  $post
     */
    public function communicationPosted(array $post): void
    {
        $this->guard('communicationPosted', function () use ($post): void {
            $author = User::query()->find($post['author_user_id']);
            $subject = trim((string) ($post['subject'] ?? ''));
            $this->queue(
                'captain5:communication:'.$post['public_id'],
                self::KIND_COMMUNICATION,
                $this->onDutyRecipients(),
                fn (): array => [
                    'title' => $subject !== '' ? 'Admin message: '.Str::limit($subject, 80) : 'New message from Administration',
                    'body' => $this->excerpt((string) $post['body']),
                    'url' => self::WORKSPACE_PATH.'?tab=messages',
                ],
                $author,
            );
        });
    }

    /** A member asked to be shown as (or to end) the acting Captain 5: Captain 5 administrators approve. */
    public function actingRequested(Captain5ActingRequest $request): void
    {
        $this->guard('actingRequested', function () use ($request): void {
            $requester = User::query()->find($request->requested_by_user_id);
            $name = trim((string) ($requester?->display_name ?: $requester?->name)) ?: 'A member';
            $approvers = User::query()
                ->where(fn ($query) => $query->whereHas('roles', fn ($roles) => $roles->where('name', 'super_admin'))
                    ->orWhereHas('permissions', fn ($permissions) => $permissions->where('name', Captain5Access::PERMISSION_ADMIN)))
                ->get()->filter(fn (User $user): bool => $this->access->canConfigure($user))->values();
            $what = $request->kind === Captain5ActingRequest::KIND_END
                ? "{$name} asked to end the current acting Captain 5 display."
                : "{$name} asked to be shown as acting Captain 5 until ".$this->localTime($request->ends_at).'.';

            $this->queue('captain5:acting:'.$request->getKey().':requested', self::KIND_ACTING_REQUEST, $approvers, fn (): array => [
                'title' => 'Acting Captain 5: approval needed',
                'body' => $what,
                'url' => self::WORKSPACE_PATH.'?tab=today',
            ], $requester);
        });
    }

    /** Tells the requester how their acting Captain 5 request was decided. */
    public function actingDecided(Captain5ActingRequest $request): void
    {
        $this->guard('actingDecided', function () use ($request): void {
            $approved = $request->status === Captain5ActingRequest::APPROVED;
            $decider = $request->decided_by_user_id !== null ? User::query()->find($request->decided_by_user_id) : null;
            $body = match (true) {
                $approved && $request->kind === Captain5ActingRequest::KIND_END => 'The acting Captain 5 display was ended.',
                $approved => 'You are shown as acting Captain 5 until '.$this->localTime($request->ends_at).'.',
                default => 'Your acting Captain 5 request was not approved.'.($request->decision_note ? ' '.$this->excerpt($request->decision_note) : ''),
            };
            $this->queue('captain5:acting:'.$request->getKey().':decided', self::KIND_ACTING_DECISION, User::query()->whereKey($request->requested_by_user_id)->get(), fn (): array => [
                'title' => $approved ? 'Acting Captain 5 approved' : 'Acting Captain 5 not approved',
                'body' => $body,
                'url' => self::WORKSPACE_PATH,
            ], $decider);
        });
    }

    private function localTime(?\DateTimeInterface $moment): string
    {
        return $moment !== null ? \Carbon\CarbonImmutable::instance($moment)->setTimezone('America/New_York')->format('D g:i A') : 'the end of the shift';
    }

    /** Replays one queued ledger row (inspections:retry-notifications). */
    public function redeliver(string $eventKey, string $kind, int $recipientUserId): bool
    {
        $this->replay = ['user' => $recipientUserId, 'key' => $eventKey, 'sent' => false];

        try {
            $parts = explode(':', $eventKey);
            $subject = $parts[1] ?? '';
            $id = (int) ($parts[2] ?? 0);
            $tail = $parts[3] ?? '';
            match (true) {
                $subject === 'communication' => $this->replayCommunication($parts[2] ?? ''),
                $subject === 'acting' && $tail === 'requested' => ($a = Captain5ActingRequest::query()->find($id)) !== null ? $this->actingRequested($a) : null,
                $subject === 'acting' && $tail === 'decided' => ($a = Captain5ActingRequest::query()->find($id)) !== null ? $this->actingDecided($a) : null,
                $subject === 'inspection' => $this->replayFindings($id),
                $subject === 'defect' => $this->withModels($id, (int) ($parts[4] ?? 0), fn (ApparatusDefect $d, ApparatusDefectEvent $e) => $this->issueUpdated($d, $e)),
                $subject === 'message' && $tail === 'notify' => ($m = ApparatusIssueMessage::query()->find($id)) !== null
                    ? $this->messagePosted($m, $m->apparatus_defect_id === null && $kind === self::KIND_MESSAGE ? 'clarification_requested' : null) : null,
                $subject === 'message' && $tail === 'reply' => ($m = ApparatusIssueMessage::query()->find($id)) !== null ? $this->memberReplied($m) : null,
                default => Log::warning('Captain 5 notification retry: event cannot be rebuilt', ['event_key' => $eventKey]),
            };

            return $this->replay['sent'];
        } finally {
            $this->replay = null;
        }
    }

    public function workspaceUrl(?int $defectId = null): string
    {
        return self::WORKSPACE_PATH.($defectId !== null ? '?issue='.$defectId : '');
    }

    // ---------------------------------------------------------------- recipients

    /**
     * The on-duty Captain 5 (or the seat holders when unresolved), limited to people who still pass
     * Captain5Access right now: a denied, departed or disabled person is never notified.
     *
     * @return Collection<int, User>
     */
    private function onDutyRecipients(): Collection
    {
        $recipients = $this->eligible($this->roster->notificationRecipients());

        return $recipients->isNotEmpty() ? $recipients : $this->eligible($this->roster->usersFor($this->roster->captain5Seats()->keys()));
    }

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    private function eligible(Collection $users): Collection
    {
        return $users->filter(fn (User $user): bool => $this->access->decide($user)->canViewData)->values();
    }

    /**
     * People who acted or wrote on this issue as Captain 5 (from the audit snapshots, so it does not
     * depend on how they were first enrolled as a participant).
     *
     * @return Collection<int, User>
     */
    private function captain5Actors(ApparatusDefect $defect): Collection
    {
        $ids = DB::table('apparatus_issue_messages')->where('apparatus_defect_id', $defect->getKey())
            ->where('author_context', ApparatusIssueMessage::CONTEXT_CAPTAIN5)->whereNotNull('author_user_id')->pluck('author_user_id')
            ->merge(ApparatusDefectEvent::query()->where('apparatus_defect_id', $defect->getKey())->whereNotNull('actor_user_id')
                ->get(['actor_user_id', 'details'])
                ->filter(fn (ApparatusDefectEvent $event): bool => ($event->details['actor_context']['role'] ?? null) === 'captain5')
                ->pluck('actor_user_id'))
            ->map(fn ($id): int => (int) $id)->unique()->values()->all();

        return $ids === [] ? collect() : User::query()->whereIn('id', $ids)->get();
    }

    /**
     * Original reporter + current duty-day crew of the unit + people actively working the issue.
     *
     * @return Collection<int, User>
     */
    public function involved(ApparatusDefect $defect): Collection
    {
        $ids = [];
        if ($defect->first_reported_by_user_id !== null) {
            $ids[] = (int) $defect->first_reported_by_user_id;
        }
        $people = $ids === [] ? collect() : User::query()->whereIn('id', $ids)->get();

        return $people
            ->merge($this->currentCrew((int) $defect->apparatus_id))
            ->merge($this->participantsWithSource($defect, self::ACTIVE_SOURCES))
            ->merge($this->eligible($this->captain5Actors($defect)));
    }

    /**
     * Members who submitted a Daily Checkout of this physical unit during the current duty day.
     *
     * @return Collection<int, User>
     */
    public function currentCrew(int $apparatusId): Collection
    {
        [$start, $end] = $this->roster->calendar()->window($this->roster->calendar()->dutyDate());
        $inspections = ApparatusInspection::query()->operational()
            ->whereRaw('COALESCE(processed_apparatus_id, apparatus_id) = ?', [$apparatusId])
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->get(['id', 'actor_user_id', 'employee_id']);
        if ($inspections->isEmpty()) {
            return collect();
        }

        return User::query()
            ->where(function ($query) use ($inspections): void {
                $query->whereIn('id', $inspections->pluck('actor_user_id')->filter()->all())
                    ->orWhereIn('employee_profile_id', $inspections->pluck('employee_id')->filter()->all());
            })
            ->get();
    }

    /**
     * @param  list<string>  $sources
     * @return Collection<int, User>
     */
    private function participantsWithSource(ApparatusDefect $defect, array $sources): Collection
    {
        $ids = DB::table('apparatus_defect_participants')
            ->where('apparatus_defect_id', $defect->getKey())
            ->whereIn('enrollment_source', $sources)
            ->pluck('user_id')->all();

        return $ids === [] ? collect() : User::query()->whereIn('id', $ids)->get();
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

    // ------------------------------------------------------------------ delivery

    /**
     * @param  Collection<int, User>  $candidates
     * @param  Closure(User): array{title: string, body: string, url: string}  $content
     */
    private function queue(string $eventKey, string $kind, Collection $candidates, Closure $content, ?User $actor = null): void
    {
        // Passing the kind lets the dispatcher drop people who cannot receive this category before a
        // ledger row says "dispatched" for them.
        $recipients = $this->dispatcher->distinctRecipients($candidates, $actor, $kind, $eventKey);
        if ($this->replay !== null) {
            if ($eventKey !== $this->replay['key']) {
                return; // another notification built by the same method; not the row being retried
            }
            $recipient = $recipients->first(fn (User $user): bool => (int) $user->getKey() === $this->replay['user']);
            if ($recipient !== null) {
                try {
                    $payload = $content($recipient);
                    $recipient->notify(new InspectionEventNotification($kind, $payload['title'], $payload['body'], $payload['url'], $eventKey));
                    $this->replay['sent'] = true;
                } catch (Throwable $exception) {
                    Log::error('Captain 5 notification retry could not be dispatched', ['event_key' => $eventKey, 'exception' => $exception->getMessage()]);
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
                    Log::error('Captain 5 notification could not be dispatched', [
                        'event_key' => $eventKey,
                        'kind' => $kind,
                        'recipient_user_id' => $recipient->getKey(),
                        'exception' => $exception->getMessage(),
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
            Log::error('Captain 5 notification ledger could not be updated', ['event_key' => $eventKey, 'exception' => $exception->getMessage()]);
        }
    }

    private function guard(string $step, Closure $work): void
    {
        try {
            DB::transaction($work); // savepoint: a ledger failure never aborts the caller's transaction
        } catch (Throwable $exception) {
            Log::error('Captain 5 notification could not be prepared', ['step' => $step, 'exception' => $exception->getMessage()]);
        }
    }

    /** Rebuilds an administrator's Captain 5 channel post from its ULID (the event key's third part). */
    private function replayCommunication(string $publicId): void
    {
        $post = CommandCommunicationPost::query()->where('public_id', $publicId)->first();
        if ($post === null || $post->isDeleted()) {
            return;
        }
        $this->communicationPosted([
            'public_id' => $post->public_id,
            'subject' => $post->subject,
            'body' => $post->body,
            'author_user_id' => (int) $post->author_user_id,
            'audience' => $post->audience,
        ]);
    }

    private function replayFindings(int $inspectionId): void
    {
        $inspection = ApparatusInspection::query()->find($inspectionId);
        if ($inspection === null) {
            return;
        }
        $defectIds = DB::table('apparatus_defects')->where('first_reported_inspection_id', $inspectionId)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $significant = DB::table('apparatus_defects')->whereIn('id', $defectIds)->where('severity', 'significant')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->newFindings($inspection, new FindingsResult($defectIds, [], [], $significant));
    }

    /** @param Closure(ApparatusDefect, ApparatusDefectEvent): void $then */
    private function withModels(int $defectId, int $eventId, Closure $then): void
    {
        $defect = ApparatusDefect::query()->find($defectId);
        $event = ApparatusDefectEvent::query()->find($eventId);
        if ($defect !== null && $event !== null) {
            $then($defect, $event);
        }
    }

    // ---------------------------------------------------------------------- copy

    /** Captain 5 officers land on the workspace; reviewers on the admin page; members on their own inspection. */
    private function urlFor(User $recipient, ApparatusDefect $defect): string
    {
        if (InspectionReviewers::canReview($recipient)) {
            $inspectionId = $defect->last_observed_inspection_id ?? $defect->first_reported_inspection_id ?? $defect->apparatus_inspection_id;

            return $inspectionId !== null ? $this->dispatcher->adminUrl((int) $inspectionId, 'issue-'.$defect->getKey()) : $this->workspaceUrl((int) $defect->getKey());
        }
        if (DB::table('apparatus_defect_participants')->where('apparatus_defect_id', $defect->getKey())
            ->where('user_id', $recipient->getKey())->where('enrollment_source', 'captain5')->exists()
            && $this->access->decide($recipient)->canViewData) { // someone who lost Captain 5 access gets the member link, not a dead end
            return $this->workspaceUrl((int) $defect->getKey());
        }

        $candidateIds = DB::table('apparatus_defect_observations')->where('apparatus_defect_id', $defect->getKey())
            ->pluck('apparatus_inspection_id')->push($defect->apparatus_inspection_id, $defect->first_reported_inspection_id)
            ->filter()->unique()->values()->all();
        $owned = $candidateIds === [] ? null : app(EmployeeInspectionHistoryService::class)->ownedQuery($recipient)
            ->whereIn('apparatus_inspections.id', $candidateIds)->orderByDesc('apparatus_inspections.id')->value('apparatus_inspections.id');

        return $owned !== null
            ? $this->dispatcher->memberInspectionUrl((int) $owned, 'issue-'.$defect->getKey())
            : MyVehicleInspections::getUrl([], false, 'employee');
    }

    private function issuePhrase(ApparatusDefect $defect): string
    {
        $item = trim((string) $defect->item);
        $type = strtolower(trim((string) $defect->issue_type));

        return trim(($type !== '' ? $type.' ' : '').($item !== '' ? $item : 'item'));
    }

    private function unitLabel(?Apparatus $apparatus, ?string $fallback = null): string
    {
        $label = trim((string) ($apparatus?->name ?: $apparatus?->designation ?: $fallback));

        return $label !== '' ? $label : 'The unit';
    }

    private function excerpt(string $text): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', strip_tags($text))), self::EXCERPT_LENGTH);
    }
}
