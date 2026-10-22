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
use App\Services\Inspections\InspectionNotificationDispatcher;
use App\Support\Fleet\DefectActionStatus;
use App\Support\Fleet\PostgresLockRetry;
use Carbon\Carbon;
use Closure;
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
 */
final class DefectCorrectiveActionService
{
    /** @var list<string> */
    public const RESOLUTION_TYPES = [
        'replacement_installed', 'spare_issued', 'repair_completed', 'item_located', 'report_corrected', 'other',
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

    public function __construct(
        private readonly DefectParticipants $participants,
        private readonly InspectionNotificationDispatcher $notifications,
        private readonly ApparatusDefectServiceTicketService $tickets,
    ) {}

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
            'resolution_type' => ['required', Rule::in(self::RESOLUTION_TYPES)],
            'resolution_notes' => ['required', 'string', 'max:5000'],
            ...$this->messageRules(),
        ]);

        return $this->run('resolve', $defectId, $actor, $idempotencyKey, false, function (ApparatusDefect $defect) use ($actor, $data): ?array {
            if ($defect->action_status === DefectActionStatus::RESOLVED) {
                return null; // another administrator already resolved it
            }
            $this->assertOpen($defect, 'This issue was dismissed. Reopen it before resolving it.');

            return $this->close($defect, $actor, DefectActionStatus::RESOLVED, 'resolved', $data['resolution_type'], $data['resolution_notes'], $data);
        });
    }

    /** @param array{reason?: string, member_message?: ?string, internal_note?: ?string} $data */
    public function dismiss(int $defectId, User $actor, array $data, ?string $idempotencyKey = null): ApparatusDefect
    {
        $data = $this->validate($data, [
            'reason' => ['required', 'string', 'max:5000'],
            ...$this->messageRules(),
        ]);

        return $this->run('dismiss', $defectId, $actor, $idempotencyKey, false, function (ApparatusDefect $defect) use ($actor, $data): ?array {
            if ($defect->action_status === DefectActionStatus::DISMISSED) {
                return null;
            }
            $this->assertOpen($defect, 'This issue was already resolved. Reopen it before dismissing it.');

            return $this->close($defect, $actor, DefectActionStatus::DISMISSED, 'dismissed', null, $data['reason'], $data, ['reason' => trim($data['reason'])]);
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
            'resolution_type' => ['nullable', Rule::in(self::RESOLUTION_TYPES)],
            ...$this->messageRules(),
        ]);

        return $this->run('verify_correction', $defectId, $actor, $idempotencyKey, false, function (ApparatusDefect $defect) use ($actor, $data): ?array {
            if ($defect->action_status === DefectActionStatus::RESOLVED) {
                return null;
            }
            $this->assertOpen($defect, 'This issue was dismissed. Reopen it before verifying a correction.');

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
                $latest->apparatus_inspection_id !== null ? (int) $latest->apparatus_inspection_id : null,
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
        Gate::forUser($actor)->authorize('manageCorrectiveAction', ApparatusDefect::class);
        $probe = ApparatusDefect::query()->findOrFail($defectId);
        // Scoped to the defect AND the operation, so one form-load key cannot turn a later,
        // different action into a silent no-op. Longest case stays under the 100-char column.
        $storedKey = $idempotencyKey === null ? null : 'defect:'.$defectId.':'.$operation.':'.$idempotencyKey;

        return PostgresLockRetry::run(fn (): ApparatusDefect => DB::transaction(function () use ($probe, $actor, $storedKey, $lockApparatusFirst, $mutate): ApparatusDefect {
            if ($lockApparatusFirst) {
                Apparatus::query()->lockForUpdate()->find($probe->apparatus_id);
            }
            $defect = ApparatusDefect::query()->lockForUpdate()->findOrFail($probe->getKey());

            if ($storedKey !== null && ApparatusDefectEvent::query()->where('idempotency_key', $storedKey)->exists()) {
                return $defect; // a retry of an action that already took effect
            }

            $spec = $mutate($defect);
            if ($spec === null) {
                return $defect->refresh();
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
                    'visibility' => ApparatusIssueMessage::VISIBILITY_MEMBER,
                    'body' => $spec['member_message'],
                ]);
            }

            $defect->refresh();
            if (in_array($spec['type'], self::RESOLVING_EVENTS, true)) {
                $this->notifications->issueResolved($defect, $event);
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
     * @return array<string, mixed> validated, with blank strings normalized to null
     */
    private function validate(array $data, array $rules): array
    {
        $validated = Validator::make($data, $rules)->validate();
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
                'expected_delivery' => Carbon::parse((string) $value, 'America/New_York')->toDateString(),
                'repair_appointment' => Carbon::parse((string) $value, 'America/New_York')->toIso8601String(),
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
