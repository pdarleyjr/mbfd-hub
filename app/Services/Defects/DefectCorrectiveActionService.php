<?php

declare(strict_types=1);

namespace App\Services\Defects;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Services\ApparatusDefectServiceTicketService;
use App\Services\Captain5\Captain5ActorContext;
use App\Services\Captain5\Captain5IssueScope;
use App\Services\Captain5\Captain5Notifier;
use App\Services\Inspections\InspectionNotificationDispatcher;
use App\Support\Fleet\DefectActionStatus;
use App\Support\Fleet\DefectNoActionClosure;
use App\Support\Fleet\DefectSeverityCorrection;
use App\Support\Fleet\PostgresLockRetry;
use Carbon\Carbon;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Logistics' answer to a persistent equipment issue
 * (docs/daily-inspection-continuity.md section 4.3).
 *
 * Every public method has the shape
 *   (int $defectId, User $actor, array $data = [], ?string $idempotencyKey = null): ApparatusDefect
 * and
 *  - authorizes `manageCorrectiveAction` for the actor,
 *  - locks the defect row (apparatus first when the action touches the apparatus,
 *    matching the global lock order inspection -> apparatus -> exceptions -> defects),
 *  - validates and appends exactly ONE ApparatusDefectEvent,
 *  - turns an optional `member_message` into a member-visible ApparatusIssueMessage,
 *  - queues notifications after commit through InspectionNotificationDispatcher,
 *  - is idempotent: retrying with the same key returns the stored outcome without a
 *    second event, message or notification, and an action that finds the issue already
 *    in the requested end state is a no-op that returns the current state (so two
 *    administrators resolving together produce one resolved event).
 *
 * Internal notes live only on the event row and are never copied to a message or notification.
 *
 * forCaptain5() returns a copy for the Captain 5 EMS Operations Center: the same engine, but
 * authorized by Captain 5 scope and operation (docs/captain5-ems-operations-center.md section 5)
 * instead of the reviewer rule, with the acting authority snapshotted on every event.
 */
final class DefectCorrectiveActionService
{
    /** @var list<string> */
    public const RESOLUTION_TYPES = [
        'replacement_installed', 'spare_issued', 'repair_completed', 'item_located', 'report_corrected', 'other',
    ];

    /**
     * What a NEW resolution may be. `report_corrected` still exists on old rows (and still renders),
     * but "the report was wrong" is now Close — No Action Required, which does not claim a repair.
     *
     * @var list<string>
     */
    public const NEW_RESOLUTION_TYPES = [
        'replacement_installed', 'spare_issued', 'repair_completed', 'item_located', 'other',
    ];

    /** @var list<string> */
    public const ACTION_STATUSES = [
        DefectActionStatus::ACTION_PENDING,
        DefectActionStatus::REPLACEMENT_AVAILABLE,
        DefectActionStatus::REPAIR_SCHEDULED,
    ];

    /** @var list<string> */
    public const IMPACTS = ['non_blocking', 'needs_repair', 'needs_admin_review', 'out_of_service'];

    /** @var list<string> keys kept in apparatus_defects.action_details */
    public const DETAIL_KEYS = [
        'parts_ordered', 'vendor_reference', 'expected_delivery', 'repair_appointment',
        'assigned_to', 'assigned_department', 'pickup_instructions',
    ];

    private const RESOLVING_EVENTS = ['resolved', 'correction_verified'];

    private ?Captain5ActorContext $captain5 = null;

    private ?string $expectedState = null;

    public function __construct(
        private readonly DefectParticipants $participants,
        private readonly InspectionNotificationDispatcher $notifications,
        private readonly ApparatusDefectServiceTicketService $tickets,
        private readonly Captain5IssueScope $captain5Scope,
        private readonly Captain5Notifier $captain5Notifier,
        private readonly NoActionClosureGuard $noActionGuard,
    ) {}

    /** A copy that acts as Captain 5 under the given authority snapshot. */
    public function forCaptain5(Captain5ActorContext $context): self
    {
        $copy = clone $this;
        $copy->captain5 = $context;

        return $copy;
    }

    /**
     * A copy that refuses to act unless the issue is still in the state the caller saw
     * (optimistic concurrency: `{action_status}:{last event id}`). A mismatch throws
     * StaleDefectStateException before anything is written.
     */
    public function expectingState(?string $stateToken): self
    {
        $copy = clone $this;
        $copy->expectedState = $stateToken === null || trim($stateToken) === '' ? null : trim($stateToken);

        return $copy;
    }

    /** Current concurrency token of an issue. */
    public static function stateToken(ApparatusDefect $defect): string
    {
        $lastEvent = (int) ApparatusDefectEvent::query()->changingTheIssue()->where('apparatus_defect_id', $defect->getKey())->max('id');

        return ($defect->action_status ?: DefectActionStatus::REPORTED).':'.$lastEvent;
    }

    /**
     * Reviewers: the unchanged class-level rule, checked before the id is looked up.
     * Captain 5: the operation must be one Captain 5 may perform and the issue must be in
     * Captain 5 scope; anything else (including an unknown id) is the same "not available".
     */
    private function authorizedProbe(string $operation, int $defectId, User $actor): ApparatusDefect
    {
        if ($this->captain5 === null) {
            Gate::forUser($actor)->authorize('manageCorrectiveAction', ApparatusDefect::class);

            return ApparatusDefect::query()->findOrFail($defectId);
        }

        if (! $this->captain5->mayPerform($operation)) {
            throw new AuthorizationException(match ($operation) {
                'dismiss' => 'Only the on-duty Captain 5, the EMS Captain or Logistics can close an issue with no action required.',
                'correct_severity' => 'Ask Logistics to correct the severity.',
                default => 'Captain 5 cannot perform this action.',
            });
        }
        $probe = ApparatusDefect::query()->find($defectId);
        if ($probe === null || ! $this->captain5Scope->inScope($probe)) {
            throw new AuthorizationException('Not available.');
        }

        return $probe;
    }

    /** @param array{member_message?: ?string, internal_note?: ?string} $data */
    public function acknowledge(int $defectId, User $actor, array $data = [], ?string $idempotencyKey = null): ApparatusDefect
    {
        $data = $this->validate($data, $this->messageRules());

        return $this->run('acknowledge', $defectId, $actor, $idempotencyKey, false, function (ApparatusDefect $defect) use ($actor, $data): ?array {
            $this->assertOpen($defect);
            if ($defect->action_status !== DefectActionStatus::REPORTED) {
                // Already acknowledged (or further along). Text the administrator typed is still
                // kept, as a plain update with no status change; otherwise there is nothing to add.
                if ($data['member_message'] === null && $data['internal_note'] === null) {
                    return null;
                }

                return $this->spec('action_updated', (string) $defect->action_status, (string) $defect->action_status, $data);
            }
            $from = (string) $defect->action_status;
            $defect->action_status = DefectActionStatus::ACKNOWLEDGED;
            $defect->acknowledged_at = now();
            $defect->acknowledged_by_user_id = $actor->getKey();
            $defect->save();

            return $this->spec('acknowledged', $from, DefectActionStatus::ACKNOWLEDGED, $data);
        });
    }

    /**
     * @param  array{action_status?: string, details?: array<string, mixed>, member_message?: ?string, internal_note?: ?string}  $data
     */
    public function updateAction(int $defectId, User $actor, array $data, ?string $idempotencyKey = null): ApparatusDefect
    {
        $data = $this->validate($data, [
            'action_status' => ['required', Rule::in(self::ACTION_STATUSES)],
            'details' => ['nullable', 'array'],
            'details.parts_ordered' => ['nullable'],
            'details.vendor_reference' => ['nullable', 'string', 'max:255'],
            'details.expected_delivery' => ['nullable', 'date'],
            'details.repair_appointment' => ['nullable', 'date'],
            'details.assigned_to' => ['nullable'],
            'details.assigned_department' => ['nullable', 'string', 'max:255'],
            'details.pickup_instructions' => ['nullable', 'string', 'max:2000'],
            ...$this->messageRules(),
        ]);
        $details = $this->normalizeDetails($data['details'] ?? []);
        if ($this->captain5 !== null && array_diff(array_keys($details), Captain5ActorContext::DETAIL_KEYS) !== []) {
            throw ValidationException::withMessages([
                'details' => 'Captain 5 can record ordering, vendor reference, expected delivery and pickup details only.',
            ]);
        }

        return $this->run('update_action', $defectId, $actor, $idempotencyKey, false, function (ApparatusDefect $defect) use ($actor, $data, $details): ?array {
            $this->assertOpen($defect);
            $from = (string) $defect->action_status;
            $to = (string) $data['action_status'];
            $current = is_array($defect->action_details) ? $defect->action_details : [];
            $merged = $this->mergeDetails($current, $details);

            if ($from === $to && $merged === $current && $data['member_message'] === null && $data['internal_note'] === null) {
                return null;
            }

            $defect->action_status = $to;
            $defect->action_details = $merged === [] ? null : $merged;
            if ($defect->acknowledged_at === null) {
                $defect->acknowledged_at = now();
                $defect->acknowledged_by_user_id = $actor->getKey();
            }
            $defect->save();

            $assignee = $this->assigneeId($merged);
            if ($assignee !== null && ($user = User::query()->find($assignee)) instanceof User) {
                $this->participants->enroll($defect, $user, 'assigned');
            }

            $type = match ($to) {
                DefectActionStatus::REPLACEMENT_AVAILABLE => 'replacement_available',
                DefectActionStatus::REPAIR_SCHEDULED => 'repair_scheduled',
                default => 'action_updated',
            };

            return $this->spec($type, $from, $to, $data, [
                'details' => $merged,
                'changed_details' => array_keys($details),
            ]);
        });
    }

    /** Shorthand for updateAction(action_status: replacement_available). */
    public function markReplacementAvailable(int $defectId, User $actor, array $data = [], ?string $idempotencyKey = null): ApparatusDefect
    {
        return $this->updateAction($defectId, $actor, [...$data, 'action_status' => DefectActionStatus::REPLACEMENT_AVAILABLE], $idempotencyKey);
    }

    /** Shorthand for updateAction(action_status: repair_scheduled). */
    public function scheduleRepair(int $defectId, User $actor, array $data = [], ?string $idempotencyKey = null): ApparatusDefect
    {
        return $this->updateAction($defectId, $actor, [...$data, 'action_status' => DefectActionStatus::REPAIR_SCHEDULED], $idempotencyKey);
    }

    /**
     * @param  array{resolution_type?: string, resolution_notes?: string, member_message?: ?string, internal_note?: ?string}  $data
     */
    public function resolve(int $defectId, User $actor, array $data, ?string $idempotencyKey = null): ApparatusDefect
    {
        $data = $this->validate($data, [
            'resolution_type' => ['required', Rule::in(self::NEW_RESOLUTION_TYPES)],
            'resolution_notes' => ['required', 'string', 'max:5000'],
            ...$this->messageRules(),
        ], [
            'resolution_type.in' => 'Choose how it was fixed. If the report was entered incorrectly, use '.DefectNoActionClosure::ACTION_LABEL.' instead.',
        ]);

        return $this->run('resolve', $defectId, $actor, $idempotencyKey, false, function (ApparatusDefect $defect) use ($actor, $data): ?array {
            if ($defect->action_status === DefectActionStatus::RESOLVED) {
                return null; // another administrator already resolved it
            }
            $this->assertOpen($defect, 'This issue was closed with no action required. Reopen it before resolving it.');

            return $this->close($defect, $actor, DefectActionStatus::RESOLVED, 'resolved', $data['resolution_type'], $data['resolution_notes'], $data);
        });
    }

    /** @param array{reason?: string, member_message?: ?string, internal_note?: ?string} $data */
    public function dismiss(int $defectId, User $actor, array $data, ?string $idempotencyKey = null): ApparatusDefect
    {
        // Who may close comes first, so a member (or an unauthorised officer) is refused whatever they typed.
        $this->authorizedProbe('dismiss', $defectId, $actor);
        $data = $this->validate($data, [
            'reason' => ['required', 'string', 'max:5000'],
            ...$this->messageRules(),
        ], ['reason.required' => (string) DefectNoActionClosure::reasonProblem('')]);
        // Enforced here, not in a form: every door (admin, Captain 5, any future caller) shares it.
        $problem = DefectNoActionClosure::reasonProblem($data['reason']);
        if ($problem !== null) {
            throw ValidationException::withMessages(['reason' => $problem]);
        }

        return $this->run('dismiss', $defectId, $actor, $idempotencyKey, false, function (ApparatusDefect $defect) use ($actor, $data): ?array {
            if ($defect->action_status === DefectActionStatus::DISMISSED) {
                return null;
            }
            $this->assertOpen($defect, 'This issue was already resolved. Reopen it before closing it with no action required.');
            $refusal = $this->noActionGuard->refusal($defect, $this->captain5 !== null);
            if ($refusal !== null) {
                throw ValidationException::withMessages(['defect' => $refusal]);
            }

            // Who and when are the event's actor_user_id and created_at; from_status and
            // `previous_action_status` record what the issue was before it was closed.
            return $this->close(
                $defect,
                $actor,
                DefectActionStatus::DISMISSED,
                'dismissed',
                null,
                $data['reason'],
                $data,
                ['reason' => trim($data['reason']), 'previous_action_status' => (string) $defect->action_status],
            );
        });
    }

    /**
     * Same row, same history: a resolved or dismissed issue becomes active again.
     * (A new failure episode after a verified resolution is a NEW row created by
     * DefectContinuityService, not a reopen.)
     *
     * @param  array{reason?: string, member_message?: ?string, internal_note?: ?string}  $data
     */
    public function reopen(int $defectId, User $actor, array $data, ?string $idempotencyKey = null): ApparatusDefect
    {
        $data = $this->validate($data, [
            'reason' => ['required', 'string', 'max:5000'],
            ...$this->messageRules(),
        ]);

        return $this->run('reopen', $defectId, $actor, $idempotencyKey, true, function (ApparatusDefect $defect) use ($data): ?array {
            if (! DefectActionStatus::isClosed($defect->action_status)) {
                return null; // already open (a concurrent reopen won)
            }
            $this->assertNoNewerOpenIssue($defect);

            $from = (string) $defect->action_status;
            $to = $defect->acknowledged_at !== null ? DefectActionStatus::ACKNOWLEDGED : DefectActionStatus::REPORTED;
            $previous = [
                'previous_resolution_type' => $defect->resolution_type,
                'previous_resolution_notes' => $defect->resolution_notes,
                'previous_resolved_by_user_id' => $defect->resolved_by_user_id,
                'previous_resolved_at' => $defect->resolved_at?->toIso8601String(),
            ];
            $defect->action_status = $to;
            $defect->resolved_by_user_id = null;
            $defect->resolution_type = null;
            $defect->resolution_notes = null;
            // Open again means back on every queue that covers it, Captain 5's included.
            $defect->captain5_cleared_at = null;
            $defect->captain5_cleared_by_user_id = null;
            $defect->save();

            return $this->spec('reopened', $from, $to, $data, ['reason' => trim($data['reason']), ...$previous]);
        });
    }

    /**
     * Logistics confirms a member's "item is present" observation. Only available when
     * the latest observation of this issue is `appears_corrected`.
     *
     * @param  array{notes?: ?string, resolution_type?: ?string, member_message?: ?string, internal_note?: ?string}  $data
     */
    public function verifyCorrection(int $defectId, User $actor, array $data = [], ?string $idempotencyKey = null): ApparatusDefect
    {
        $data = $this->validate($data, [
            'notes' => ['nullable', 'string', 'max:5000'],
            'resolution_type' => ['nullable', Rule::in(self::NEW_RESOLUTION_TYPES)],
            ...$this->messageRules(),
        ], [
            'resolution_type.in' => 'Choose how it was corrected. If the report was entered incorrectly, use '.DefectNoActionClosure::ACTION_LABEL.' instead.',
        ]);

        return $this->run('verify_correction', $defectId, $actor, $idempotencyKey, false, function (ApparatusDefect $defect) use ($actor, $data): ?array {
            if ($defect->action_status === DefectActionStatus::RESOLVED) {
                return null;
            }
            $this->assertOpen($defect, 'This issue was closed with no action required. Reopen it before verifying a correction.');

            $latest = ApparatusDefectObservation::query()
                ->where('apparatus_defect_id', $defect->getKey())
                ->orderByDesc('id')
                ->first();
            if ($latest === null || $latest->observation !== 'appears_corrected') {
                throw ValidationException::withMessages([
                    'defect' => 'The latest inspection did not report this item as present, so there is no correction to verify.',
                ]);
            }

            return $this->close(
                $defect,
                $actor,
                DefectActionStatus::RESOLVED,
                'correction_verified',
                $data['resolution_type'] ?? 'item_located',
                $data['notes'] ?? 'Correction verified: the latest inspection reported the item as present.',
                $data,
                ['verified_observation_id' => $latest->getKey()],
                $latest->apparatus_inspection_id,
            );
        });
    }

    /**
     * Classifies operational impact. `out_of_service` also needs `update` on the apparatus and
     * sets its status explicitly; no other value ever changes (or returns) the unit's status.
     *
     * @param  array{operational_impact?: string, member_message?: ?string, internal_note?: ?string}  $data
     */
    public function classifyImpact(int $defectId, User $actor, array $data, ?string $idempotencyKey = null): ApparatusDefect
    {
        $data = $this->validate($data, [
            'operational_impact' => ['required', Rule::in(self::IMPACTS)],
            ...$this->messageRules(),
        ]);

        return $this->run('classify_impact', $defectId, $actor, $idempotencyKey, true, function (ApparatusDefect $defect) use ($actor, $data): ?array {
            $this->assertOpen($defect);
            $impact = (string) $data['operational_impact'];
            $previous = $defect->operational_impact;
            if ($previous === $impact && ($impact !== 'out_of_service' || $this->apparatusIsOutOfService($defect))) {
                return null;
            }

            $statusChanged = false;
            if ($impact === 'out_of_service') {
                $apparatus = Apparatus::query()->findOrFail($defect->apparatus_id);
                Gate::forUser($actor)->authorize('update', $apparatus);
                if ($apparatus->getAttribute('status') !== 'Out of Service') {
                    $apparatus->update(['status' => 'Out of Service']);
                    $statusChanged = true;
                }
            }
            $defect->operational_impact = $impact;
            $defect->save();

            return $this->spec('impact_classified', (string) $defect->action_status, (string) $defect->action_status, $data, [
                'from_impact' => $previous,
                'to_impact' => $impact,
                'apparatus_status_changed' => $statusChanged,
            ]);
        });
    }

    /**
     * Corrects how serious the issue was recorded as ('routine' or 'significant'). Reviewers only:
     * Captain 5 is refused whatever its scope, and members never reach this class. The change, the
     * reason and who made it are an event, and the reporter and participants are told.
     *
     * @param  array{severity?: string, reason?: string, member_message?: ?string, internal_note?: ?string}  $data
     */
    public function correctSeverity(int $defectId, User $actor, array $data, ?string $idempotencyKey = null): ApparatusDefect
    {
        // Who may correct comes first, so a member (or Captain 5) is refused whatever they typed.
        $this->authorizedProbe('correct_severity', $defectId, $actor);
        $data = $this->validate($data, [
            'severity' => ['required', Rule::in([DefectSeverityCorrection::ROUTINE, DefectSeverityCorrection::SIGNIFICANT])],
            'reason' => ['required', 'string', 'max:5000'],
            ...$this->messageRules(),
        ], ['reason.required' => (string) DefectSeverityCorrection::reasonProblem('')]);
        $problem = DefectSeverityCorrection::reasonProblem($data['reason']);
        if ($problem !== null) {
            throw ValidationException::withMessages(['reason' => $problem]);
        }

        return $this->run('correct_severity', $defectId, $actor, $idempotencyKey, false, function (ApparatusDefect $defect) use ($data): ?array {
            $this->assertOpen($defect);
            $from = (string) ($defect->severity ?: DefectSeverityCorrection::ROUTINE);
            $to = (string) $data['severity'];
            if ($from === $to) {
                return null; // already at that severity (a concurrent correction won)
            }
            $defect->severity = $to;
            $defect->save();

            return $this->spec('severity_corrected', (string) $defect->action_status, (string) $defect->action_status, $data, [
                'from_severity' => $from,
                'to_severity' => $to,
                'reason' => trim($data['reason']),
            ]);
        });
    }

    /**
     * Opens (or finds) the Fleet service ticket for this issue. A ticket's completion never
     * resolves the issue; that stays an explicit decision.
     *
     * @param  array{category?: string, priority?: string, title?: string, description?: string, member_message?: ?string, internal_note?: ?string}  $data
     */
    public function linkTicket(int $defectId, User $actor, array $data, ?string $idempotencyKey = null): ApparatusDefect
    {
        $data = $this->validate($data, [
            'category' => ['nullable', 'string', 'max:64'],
            'priority' => ['nullable', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            ...$this->messageRules(),
        ]);

        return $this->run('link_ticket', $defectId, $actor, $idempotencyKey, true, function (ApparatusDefect $defect) use ($actor, $data): ?array {
            if ($defect->service_ticket_id !== null) {
                return null;
            }
            $this->assertOpen($defect);

            // Anything the administrator left blank falls back to the prefilled defaults.
            $overrides = array_filter(
                array_intersect_key($data, array_flip(['category', 'priority', 'title', 'description'])),
                static fn ($value): bool => $value !== null && $value !== '',
            );
            $result = $this->tickets->create($defect, $actor, [...$this->tickets->defaults($defect), ...$overrides]);
            $defect->refresh();

            return $this->spec('ticket_linked', (string) $defect->action_status, (string) $defect->action_status, $data, [
                'service_ticket_id' => $result->ticket->getKey(),
                'ticket_number' => $result->ticket->ticket_number,
                'ticket_created' => $result->created,
            ]);
        });
    }

    // ------------------------------------------------------------------- engine

    /**
     * @param  Closure(ApparatusDefect): ?array{type: string, from: ?string, to: ?string, member_message: ?string, internal_note: ?string, details: array<string, mixed>, inspection_id: ?int}  $mutate
     *                                                                                                                                                                                                   returns null for a no-op (no event, no message, no notification)
     */
    private function run(string $operation, int $defectId, User $actor, ?string $idempotencyKey, bool $lockApparatusFirst, Closure $mutate): ApparatusDefect
    {
        if ($idempotencyKey !== null && (trim($idempotencyKey) === '' || mb_strlen($idempotencyKey) > 48)) {
            throw ValidationException::withMessages(['idempotency_key' => 'The idempotency key must be 1 to 48 characters.']);
        }
        $probe = $this->authorizedProbe($operation, $defectId, $actor);
        // Scoped to the defect AND the operation, so one form-load key cannot turn a later,
        // different action into a silent no-op. Longest case stays under the 100-char column.
        $storedKey = $idempotencyKey === null ? null : 'defect:'.$defectId.':'.$operation.':'.$idempotencyKey;
        $captain5 = $this->captain5;
        $expectedState = $this->expectedState;

        return PostgresLockRetry::run(fn (): ApparatusDefect => DB::transaction(function () use ($probe, $actor, $storedKey, $lockApparatusFirst, $mutate, $captain5, $expectedState): ApparatusDefect {
            if ($lockApparatusFirst) {
                Apparatus::query()->lockForUpdate()->find($probe->apparatus_id);
            }
            $defect = ApparatusDefect::query()->lockForUpdate()->findOrFail($probe->getKey());

            if ($storedKey !== null && ApparatusDefectEvent::query()->where('idempotency_key', $storedKey)->exists()) {
                return $defect; // a retry of an action that already took effect
            }
            if ($expectedState !== null && ! hash_equals(self::stateToken($defect), $expectedState)) {
                throw new StaleDefectStateException($defect);
            }

            $spec = $mutate($defect);
            if ($spec === null) {
                return $defect->refresh();
            }
            if ($captain5 !== null) {
                $spec['details']['actor_context'] = $captain5->toAudit();
            }

            $event = ApparatusDefectEvent::query()->create([
                'apparatus_defect_id' => $defect->getKey(),
                'apparatus_inspection_id' => $spec['inspection_id'],
                'actor_user_id' => $actor->getKey(),
                'event_type' => $spec['type'],
                'from_status' => $spec['from'],
                'to_status' => $spec['to'],
                'member_message' => $spec['member_message'],
                'internal_note' => $spec['internal_note'],
                'details' => $spec['details'] === [] ? null : $spec['details'],
                'idempotency_key' => $storedKey,
            ]);

            if ($spec['member_message'] !== null) {
                ApparatusIssueMessage::query()->create([
                    'apparatus_inspection_id' => null,
                    'apparatus_defect_id' => $defect->getKey(),
                    'author_user_id' => $actor->getKey(),
                    'author_context' => $captain5 !== null ? ApparatusIssueMessage::CONTEXT_CAPTAIN5 : ApparatusIssueMessage::CONTEXT_LOGISTICS,
                    'visibility' => ApparatusIssueMessage::VISIBILITY_MEMBER,
                    'body' => $spec['member_message'],
                ]);
            }

            $defect->refresh();
            if ($captain5 !== null) {
                $this->participants->enroll($defect, $actor, 'captain5');
                $this->captain5Notifier->issueUpdated($defect, $event);
            } elseif (in_array($spec['type'], self::RESOLVING_EVENTS, true)) {
                $this->notifications->issueResolved($defect, $event);
            } elseif ($spec['type'] === 'dismissed') {
                // Told from every door: the reason is the message, so nobody has to remember to type one.
                $this->notifications->issueClosedNoAction($defect, $event);
            } elseif ($spec['type'] === 'reopened') {
                // Whoever was told it was closed is told it is open again, typed message or not.
                $this->notifications->issueReopened($defect, $event);
            } elseif ($spec['type'] === 'severity_corrected') {
                $this->notifications->issueSeverityCorrected($defect, $event);
            } elseif ($spec['member_message'] !== null) {
                $this->notifications->issueMemberUpdate($defect, $event);
            }

            return $defect;
        }, 3));
    }

    /**
     * Shared resolved / dismissed / correction_verified transition.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function close(
        ApparatusDefect $defect,
        User $actor,
        string $to,
        string $eventType,
        ?string $resolutionType,
        string $notes,
        array $data,
        array $extra = [],
        ?int $inspectionId = null,
    ): array {
        $from = (string) $defect->action_status;
        $defect->action_status = $to;
        $defect->resolved_by_user_id = $actor->getKey();
        $defect->resolution_type = $resolutionType;
        $defect->resolution_notes = trim($notes);
        $defect->save();

        return $this->spec($eventType, $from, $to, $data, [
            'resolution_type' => $resolutionType,
            'resolution_notes' => trim($notes),
            ...$extra,
        ], $inspectionId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $details
     * @return array{type: string, from: ?string, to: ?string, member_message: ?string, internal_note: ?string, details: array<string, mixed>, inspection_id: ?int}
     */
    private function spec(string $type, ?string $from, ?string $to, array $data, array $details = [], ?int $inspectionId = null): array
    {
        return [
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'member_message' => $data['member_message'] ?? null,
            'internal_note' => $data['internal_note'] ?? null,
            'details' => $details,
            'inspection_id' => $inspectionId,
        ];
    }

    // --------------------------------------------------------------- validation

    /** @return array<string, list<mixed>> */
    private function messageRules(): array
    {
        return [
            'member_message' => ['nullable', 'string', 'max:2000'],
            'internal_note' => ['nullable', 'string', 'max:4000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<mixed>>  $rules
     * @param  array<string, string>  $messages
     * @return array<string, mixed> validated, with blank strings normalized to null
     */
    private function validate(array $data, array $rules, array $messages = []): array
    {
        $validated = Validator::make($data, $rules, $messages)->validate();
        foreach (['member_message', 'internal_note'] as $field) {
            $text = isset($validated[$field]) ? trim((string) $validated[$field]) : '';
            $validated[$field] = $text === '' ? null : $text;
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed> only known keys; null means "clear this value"
     */
    private function normalizeDetails(array $details): array
    {
        $clean = [];
        foreach (self::DETAIL_KEYS as $key) {
            if (! array_key_exists($key, $details)) {
                continue;
            }
            $value = $details[$key];
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === null || $value === '') {
                $clean[$key] = null;

                continue;
            }
            $clean[$key] = match ($key) {
                'expected_delivery' => Carbon::parse((string) $value, \App\Support\HubTimezone::NAME)->toDateString(),
                'repair_appointment' => Carbon::parse((string) $value, \App\Support\HubTimezone::NAME)->toIso8601String(),
                'parts_ordered' => is_bool($value) ? $value : $this->limitedString($key, $value, 255),
                'assigned_to' => $this->assignee($value),
                default => (string) $value,
            };
        }

        return $clean;
    }

    private function limitedString(string $key, mixed $value, int $max): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        if ($text === '' || mb_strlen($text) > $max) {
            throw ValidationException::withMessages(["details.{$key}" => "The {$key} must be text of at most {$max} characters."]);
        }

        return $text;
    }

    /**
     * A real PHP integer is a user id that must exist (stored as int, enrolled and notified);
     * every string is free text, even "4310", so a badge or PO number never picks a person.
     */
    private function assignee(mixed $value): int|string
    {
        if (is_int($value)) {
            if (! User::query()->whereKey($value)->exists()) {
                throw ValidationException::withMessages(['details.assigned_to' => 'The assigned person does not exist.']);
            }

            return $value;
        }

        return $this->limitedString('assigned_to', $value, 255);
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function mergeDetails(array $current, array $changes): array
    {
        $merged = array_filter(
            array_merge($current, $changes),
            static fn ($value): bool => $value !== null,
        );
        ksort($merged);

        return $merged;
    }

    /** @param array<string, mixed> $details */
    private function assigneeId(array $details): ?int
    {
        $assigned = $details['assigned_to'] ?? null;

        return is_int($assigned) ? $assigned : null;
    }

    // ------------------------------------------------------------------ guards

    private function assertOpen(ApparatusDefect $defect, string $message = 'This issue is closed. Reopen it first.'): void
    {
        if (DefectActionStatus::isClosed($defect->action_status)) {
            throw ValidationException::withMessages(['defect' => $message]);
        }
    }

    /** Reopening must not collide with the partial unique index on unresolved items. */
    private function assertNoNewerOpenIssue(ApparatusDefect $defect): void
    {
        $clash = ApparatusDefect::query()
            ->where('apparatus_id', $defect->apparatus_id)
            ->where('resolved', false)
            ->whereKeyNot($defect->getKey())
            ->where(function ($query) use ($defect): void {
                $query->where(fn ($same) => $same->where('compartment', $defect->compartment)->where('item', $defect->item));
                if ($defect->compartment_key !== null && $defect->item_key !== null) {
                    $query->orWhere(fn ($same) => $same->where('compartment_key', $defect->compartment_key)->where('item_key', $defect->item_key));
                }
            })
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages([
                'defect' => 'A newer open issue already exists for this item. Update that one instead of reopening this one.',
            ]);
        }
    }

    private function apparatusIsOutOfService(ApparatusDefect $defect): bool
    {
        return Apparatus::query()->whereKey($defect->apparatus_id)->value('status') === 'Out of Service';
    }
}
