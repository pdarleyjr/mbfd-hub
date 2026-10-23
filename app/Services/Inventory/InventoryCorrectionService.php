<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInventoryChange;
use App\Models\ApparatusInventoryChangeSet;
use App\Models\ApparatusInventoryCorrection;
use App\Models\ApparatusInventoryCorrectionEvent as Event;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Inspections\InspectionNotificationDispatcher;
use App\Support\Fleet\InventoryCorrectionAccess;
use App\Support\Security\Base64Image;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The one write path for apparatus inventory corrections
 * (docs/apparatus-inventory-corrections.md). Every method authorizes, validates and
 * writes inside one transaction; notifications are ledgered there and sent after commit.
 *
 * Lock order: correction row -> apparatus rows (ascending id) -> inventory changes.
 */
final class InventoryCorrectionService
{
    public const MAX_MESSAGE = 2000;

    public const PHOTO_DISK = 'local';

    public function __construct(
        private readonly DailyCheckoutChecklistResolver $resolver,
        private readonly InventoryChangePlanner $planner,
        private readonly ChecklistRevisionStore $revisions,
        private readonly InspectionNotificationDispatcher $notifications,
    ) {}

    // ------------------------------------------------------------------ member

    /**
     * Records a member's report. Replaying the same client_submission_id with the same
     * payload returns the stored report (`duplicate` true); a different payload is refused.
     *
     * @param  array<string, mixed>  $data  validated request data
     * @return array{correction: ApparatusInventoryCorrection, duplicate: bool}
     */
    public function submit(User $reporter, ?int $employeeId, Apparatus $apparatus, array $data): array
    {
        $hash = $this->payloadHash($data);
        $existing = $this->existingSubmission($reporter, (string) $data['client_submission_id'], $hash);
        if ($existing !== null) {
            return ['correction' => $existing, 'duplicate' => true];
        }

        [$checklist, $checklistType] = $this->checklistSeenBy($apparatus, (string) $data['checklist_version']);
        $target = $this->target($checklist, $data);
        $photoPath = null;
        if (filled($data['photo'] ?? null)) {
            $photoPath = Base64Image::store((string) $data['photo'], 'inventory-corrections', 'correction', self::PHOTO_DISK)
                ?? throw ValidationException::withMessages(['photo' => 'The photo must be a valid JPEG, PNG, WebP, or GIF image.']);
        }

        try {
            $correction = DB::transaction(function () use ($reporter, $employeeId, $apparatus, $data, $hash, $checklistType, $target, $photoPath): ApparatusInventoryCorrection {
                $correction = ApparatusInventoryCorrection::query()->create([
                    'client_request_id' => strtolower((string) $data['client_submission_id']),
                    'payload_hash' => $hash,
                    'apparatus_id' => $apparatus->getKey(),
                    'inspection_draft_id' => isset($data['inspection_draft_id']) ? strtolower((string) $data['inspection_draft_id']) : null,
                    'reporter_user_id' => $reporter->getKey(),
                    'reporter_employee_id' => $employeeId,
                    'reason' => $data['reason'],
                    'compartment_key' => $target['compartment_id'],
                    'item_key' => $target['item_id'],
                    'equipment_key' => $target['equipment_key'],
                    'target_snapshot' => $target['snapshot'],
                    'checklist_type' => $checklistType,
                    'checklist_version' => strtolower((string) $data['checklist_version']),
                    'proposal' => $this->cleanProposal($data['proposal'] ?? []),
                    'member_note' => $this->optional($data['note'] ?? null),
                    'photo_path' => $photoPath,
                    'status' => ApparatusInventoryCorrection::STATUS_SUBMITTED,
                ]);
                $this->event($correction, $reporter, Event::SUBMITTED, Event::VISIBILITY_MEMBER, $correction->member_note);
                $this->linkToSubmittedInspection($correction);
                $this->notifications->inventoryCorrectionSubmitted($correction);

                return $correction;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $this->deletePhoto($photoPath);
            $winner = $this->existingSubmission($reporter, (string) $data['client_submission_id'], $hash);
            if ($winner === null) {
                throw $exception;
            }

            return ['correction' => $winner, 'duplicate' => true];
        } catch (Throwable $exception) {
            $this->deletePhoto($photoPath);
            throw $exception;
        }

        return ['correction' => $correction, 'duplicate' => false];
    }

    /** Links the member's corrections from one Daily Checkout draft to the inspection it became. */
    public function linkInspection(ApparatusInspection $inspection): void
    {
        $draftId = $inspection->inventory_draft_id;
        if (! is_string($draftId) || $draftId === '' || $inspection->actor_user_id === null) {
            return;
        }
        ApparatusInventoryCorrection::query()
            ->where('inspection_draft_id', strtolower($draftId))
            ->where('apparatus_id', $inspection->apparatus_id)
            ->where('reporter_user_id', $inspection->actor_user_id)
            ->whereNull('apparatus_inspection_id')
            ->orderBy('id')
            ->get()
            ->each(function (ApparatusInventoryCorrection $correction) use ($inspection): void {
                $correction->forceFill(['apparatus_inspection_id' => $inspection->getKey()])->save();
                $this->event($correction, null, Event::LINKED_TO_INSPECTION, Event::VISIBILITY_INTERNAL, null, ['inspection_id' => $inspection->getKey()]);
            });
    }

    // ---------------------------------------------------------------- messages

    /**
     * A message in the correction's thread. Logistics may write member-visible or internal
     * messages; the reporting member writes member-visible replies only. A member reply to a
     * clarification request returns the correction to the review queue.
     */
    public function postMessage(int $correctionId, User $author, string $body, string $visibility, ?string $idempotencyKey = null): Event
    {
        $body = $this->messageBody($body);

        return DB::transaction(function () use ($correctionId, $author, $body, $visibility, $idempotencyKey): Event {
            if (($replayed = $this->replayedEvent($idempotencyKey)) !== null) {
                return $replayed;
            }
            $correction = $this->lock($correctionId);
            $isReviewer = InventoryCorrectionAccess::canReview($author);
            if (! $isReviewer && ! InventoryCorrectionAccess::owns($author, $correction)) {
                throw new AuthorizationException;
            }
            if (! $isReviewer) {
                $event = $this->event($correction, $author, Event::MEMBER_REPLIED, Event::VISIBILITY_MEMBER, $body, null, $idempotencyKey, 'member');
                if ($correction->status === ApparatusInventoryCorrection::STATUS_CLARIFICATION) {
                    $this->transition($correction, ApparatusInventoryCorrection::STATUS_SUBMITTED);
                }
                $this->notifications->inventoryCorrectionReply($correction, $event);

                return $event;
            }

            $internal = $visibility === Event::VISIBILITY_INTERNAL;
            $event = $this->event(
                $correction,
                $author,
                $internal ? Event::INTERNAL_NOTE : Event::LOGISTICS_REPLIED,
                $internal ? Event::VISIBILITY_INTERNAL : Event::VISIBILITY_MEMBER,
                $body,
                null,
                $idempotencyKey,
            );
            if (! $internal) {
                $this->notifications->inventoryCorrectionUpdate($correction, $event);
            }

            return $event;
        });
    }

    public function requestClarification(int $correctionId, User $reviewer, string $question, ?int $expectedLock = null, ?string $idempotencyKey = null): ApparatusInventoryCorrection
    {
        $question = $this->messageBody($question);

        return DB::transaction(function () use ($correctionId, $reviewer, $question, $expectedLock, $idempotencyKey): ApparatusInventoryCorrection {
            if (($replayed = $this->replayedEvent($idempotencyKey)) !== null) {
                return $replayed->correction()->firstOrFail();
            }
            $correction = $this->lockForDecision($correctionId, $reviewer, $expectedLock);
            $this->transition($correction, ApparatusInventoryCorrection::STATUS_CLARIFICATION);
            $event = $this->event($correction, $reviewer, Event::CLARIFICATION_REQUESTED, Event::VISIBILITY_MEMBER, $question, null, $idempotencyKey);
            $this->notifications->inventoryCorrectionUpdate($correction, $event);

            return $correction;
        });
    }

    // --------------------------------------------------------------- decisions

    public function reject(int $correctionId, User $reviewer, string $reason, ?string $internalNote = null, ?int $expectedLock = null, ?string $idempotencyKey = null): ApparatusInventoryCorrection
    {
        $reason = $this->messageBody($reason);

        return DB::transaction(function () use ($correctionId, $reviewer, $reason, $internalNote, $expectedLock, $idempotencyKey): ApparatusInventoryCorrection {
            if (($replayed = $this->replayedEvent($idempotencyKey)) !== null) {
                return $replayed->correction()->firstOrFail();
            }
            $correction = $this->lockForDecision($correctionId, $reviewer, $expectedLock);
            $correction->forceFill([
                'decision_reason' => $reason,
                'decided_by_user_id' => $reviewer->getKey(),
                'decided_at' => now(),
            ]);
            $this->transition($correction, ApparatusInventoryCorrection::STATUS_REJECTED);
            $event = $this->event($correction, $reviewer, Event::REJECTED, Event::VISIBILITY_MEMBER, $reason, null, $idempotencyKey);
            if (filled($internalNote)) {
                $this->event($correction, $reviewer, Event::INTERNAL_NOTE, Event::VISIBILITY_INTERNAL, $this->messageBody((string) $internalNote));
            }
            $this->notifications->inventoryCorrectionUpdate($correction, $event);

            return $correction;
        });
    }

    /**
     * Impact preview for Logistics. Read-only.
     *
     * @param  array{op?: mixed, fields?: mixed}  $operation
     * @return array<string, mixed>
     */
    public function preview(int $correctionId, User $reviewer, array $operation, string $scope): array
    {
        $correction = ApparatusInventoryCorrection::query()->findOrFail($correctionId);
        $this->authorizeScope($reviewer, $scope);

        return $this->planner->plan($correction, $operation, $scope);
    }

    /**
     * Approves the correction with Logistics' final operation and applies it, all or nothing,
     * to the selected apparatus. A replay with the same idempotency key returns the first result.
     *
     * @param  array{op?: mixed, fields?: mixed}  $operation
     * @param  list<int|string>  $selectedApparatusIds
     */
    public function approve(
        int $correctionId,
        User $reviewer,
        array $operation,
        string $scope,
        array $selectedApparatusIds,
        string $previewFingerprint,
        ?int $expectedLock,
        string $idempotencyKey,
        ?string $memberMessage = null,
        ?string $internalNote = null,
    ): ApparatusInventoryChangeSet {
        $this->authorizeScope($reviewer, $scope);
        $memberMessage = filled($memberMessage) ? $this->messageBody((string) $memberMessage) : null;
        $internalNote = filled($internalNote) ? $this->messageBody((string) $internalNote) : null;

        return DB::transaction(function () use ($correctionId, $reviewer, $operation, $scope, $selectedApparatusIds, $previewFingerprint, $expectedLock, $idempotencyKey, $memberMessage, $internalNote): ApparatusInventoryChangeSet {
            $replay = ApparatusInventoryChangeSet::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($replay !== null) {
                if ((int) $replay->apparatus_inventory_correction_id !== $correctionId) {
                    throw ValidationException::withMessages(['decision' => 'This approval was already used for another correction. Reload and try again.']);
                }

                return $replay;
            }
            $correction = $this->lockForDecision($correctionId, $reviewer, $expectedLock);
            $plan = $this->planner->plan($correction, $operation, $scope, lock: true);
            if (! hash_equals($plan['fingerprint'], $previewFingerprint)) {
                throw new InventoryCorrectionConflict(
                    InventoryCorrectionConflict::STALE_PREVIEW,
                    'The inventory of an affected apparatus changed since you reviewed the impact. Review it again before approving.',
                );
            }
            $selected = $this->selectedUnits($plan, $selectedApparatusIds);

            $changeSet = ApparatusInventoryChangeSet::query()->create([
                'apparatus_inventory_correction_id' => $correction->getKey(),
                'scope' => $scope,
                'actor_user_id' => $reviewer->getKey(),
                'idempotency_key' => $idempotencyKey,
                'status' => ApparatusInventoryChangeSet::STATUS_APPLIED,
                'preview_fingerprint' => $plan['fingerprint'],
                'operation' => $plan['operation'],
                'results' => [],
            ]);

            $results = [];
            foreach ($plan['units'] as $unit) {
                $isSelected = isset($selected[$unit['apparatus_id']]);
                $results[] = [
                    'apparatus_id' => $unit['apparatus_id'],
                    'label' => $unit['label'],
                    'verdict' => $unit['verdict'],
                    'selected' => $isSelected,
                    ...($isSelected ? $this->applyToUnit($selected[$unit['apparatus_id']], $plan, $correction, $changeSet, $reviewer) : ['applied' => false]),
                ];
            }
            $changeSet->forceFill(['results' => $results])->save();

            $suggested = InventoryCorrectionReason::suggestedOperation($correction);
            $correction->forceFill([
                'decision' => [
                    'operation' => $plan['operation'],
                    'scope' => $scope,
                    'change_set_id' => $changeSet->getKey(),
                    'apparatus_ids' => array_keys($selected),
                    'modified' => $suggested != $plan['operation'],
                ],
                'decision_reason' => $memberMessage,
                'decided_by_user_id' => $reviewer->getKey(),
                'decided_at' => now(),
            ]);
            $this->transition($correction, ApparatusInventoryCorrection::STATUS_APPLIED);

            $this->event($correction, $reviewer, Event::APPROVED, Event::VISIBILITY_MEMBER, $memberMessage, [
                'operation' => $plan['operation'],
                'modified' => $suggested != $plan['operation'],
                'proposal' => $correction->proposal,
            ]);
            $applied = $this->event($correction, $reviewer, Event::APPLIED, Event::VISIBILITY_MEMBER, null, [
                'change_set_id' => $changeSet->getKey(),
                'scope' => $scope,
                'units' => array_values(array_map(static fn (array $result): string => $result['label'], array_filter($results, static fn (array $result): bool => $result['applied'] === true))),
                'results' => $results,
            ]);
            if ($internalNote !== null) {
                $this->event($correction, $reviewer, Event::INTERNAL_NOTE, Event::VISIBILITY_INTERNAL, $internalNote);
            }
            $this->notifications->inventoryCorrectionUpdate($correction, $applied);

            return $changeSet;
        });
    }

    /**
     * Reverses an applied correction on every unit it changed. Refused while a later change
     * on an affected item exists (reverse that one first), so history never forks.
     */
    public function reverse(int $correctionId, User $reviewer, string $reason, ?int $expectedLock = null, ?string $idempotencyKey = null): ApparatusInventoryChangeSet
    {
        $reason = $this->messageBody($reason);

        return DB::transaction(function () use ($correctionId, $reviewer, $reason, $expectedLock, $idempotencyKey): ApparatusInventoryChangeSet {
            if (($replayed = $this->replayedEvent($idempotencyKey)) !== null) {
                return ApparatusInventoryChangeSet::query()->findOrFail((int) ($replayed->details['change_set_id'] ?? 0));
            }
            $correction = $this->lock($correctionId);
            $this->authorizeReview($reviewer);
            $this->assertLockVersion($correction, $expectedLock);
            if ($correction->status !== ApparatusInventoryCorrection::STATUS_APPLIED) {
                throw ValidationException::withMessages(['decision' => 'Only an applied correction can be reversed.']);
            }
            $changeSet = ApparatusInventoryChangeSet::query()
                ->where('apparatus_inventory_correction_id', $correction->getKey())
                ->where('status', ApparatusInventoryChangeSet::STATUS_APPLIED)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->firstOrFail();
            if ($changeSet->scope !== InventoryChangePlanner::SCOPE_UNIT) {
                $this->authorizeScope($reviewer, (string) $changeSet->scope);
            }
            $changes = $changeSet->changes()->get();
            $apparatusIds = $changes->pluck('apparatus_id')->map(static fn ($id): int => (int) $id)->unique()->sort()->values()->all();
            $units = Apparatus::query()->whereIn('id', $apparatusIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($changes as $change) {
                $later = ApparatusInventoryChange::query()
                    ->where('apparatus_id', $change->apparatus_id)
                    ->where('item_key', $change->item_key)
                    ->where('id', '>', $change->getKey())
                    ->active()
                    ->orderBy('id')
                    ->value('id');
                if ($later !== null || $change->status !== ApparatusInventoryChange::STATUS_ACTIVE) {
                    throw new InventoryCorrectionConflict(
                        InventoryCorrectionConflict::DEPENDENT_CHANGE,
                        'A later approved change affects this item'.($later !== null ? " (change #{$later})" : '').'. Reverse that change first.',
                    );
                }
            }

            foreach ($apparatusIds as $apparatusId) {
                $apparatus = $units[$apparatusId];
                $this->revisions->remember($apparatus, $this->resolver->resolve($apparatus), $changeSet->getKey(), retire: true);
                foreach ($changes->where('apparatus_id', $apparatusId) as $change) {
                    $change->forceFill(['status' => ApparatusInventoryChange::STATUS_REVERSED, 'status_changed_at' => now()])->save();
                    ApparatusInventoryChange::query()->where('superseded_by_change_id', $change->getKey())->get()
                        ->each(static fn (ApparatusInventoryChange $restored) => $restored->forceFill([
                            'status' => ApparatusInventoryChange::STATUS_ACTIVE,
                            'superseded_by_change_id' => null,
                            'status_changed_at' => now(),
                        ])->save());
                }
                $after = $this->resolver->resolve($apparatus);
                if ($after['usable'] !== true || $after['overlay_conflicts'] !== []) {
                    throw ValidationException::withMessages(['decision' => "Reversing would leave {$apparatus->designation} with an invalid checklist. Nothing was changed."]);
                }
                $this->revisions->remember($apparatus, $after, $changeSet->getKey(), retire: false);
            }

            $changeSet->forceFill(['status' => ApparatusInventoryChangeSet::STATUS_REVERSED, 'reversed_by_user_id' => $reviewer->getKey(), 'reversed_at' => now()])->save();
            $this->transition($correction, ApparatusInventoryCorrection::STATUS_REVERSED);
            $event = $this->event($correction, $reviewer, Event::REVERSED, Event::VISIBILITY_MEMBER, $reason, ['change_set_id' => $changeSet->getKey()], $idempotencyKey);
            $this->notifications->inventoryCorrectionUpdate($correction, $event);

            return $changeSet;
        });
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Writes one unit's change, then proves the unit's next checklist actually contains it.
     *
     * @param  array<string, mixed>  $unit
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function applyToUnit(array $unit, array $plan, ApparatusInventoryCorrection $correction, ApparatusInventoryChangeSet $changeSet, User $reviewer): array
    {
        $apparatus = Apparatus::query()->findOrFail($unit['apparatus_id']);
        $before = $this->resolver->resolve($apparatus);
        $this->revisions->remember($apparatus, $before, $changeSet->getKey(), retire: true);

        $fields = $plan['operation']['fields'];
        if ($unit['destination_compartment_id'] !== null) {
            $fields['compartment_id'] = $unit['destination_compartment_id'];
        }
        $change = ApparatusInventoryChange::query()->create([
            'apparatus_id' => $apparatus->getKey(),
            'apparatus_inventory_correction_id' => $correction->getKey(),
            'apparatus_inventory_change_set_id' => $changeSet->getKey(),
            'operation' => $plan['operation']['op'],
            'item_key' => $unit['target_item_key'],
            'equipment_key' => (string) $plan['equipment_key'],
            'payload' => $fields,
            'before' => ['item' => $unit['before'], 'checklist_version' => $before['checklist_version']],
            'scope' => $plan['scope'],
            'status' => ApparatusInventoryChange::STATUS_ACTIVE,
            'created_by_user_id' => $reviewer->getKey(),
        ]);
        $this->supersedeEarlierChanges($change);

        $after = $this->resolver->resolve($apparatus);
        if ($after['usable'] !== true || ! in_array((int) $change->getKey(), $after['inventory_change_ids'], true)) {
            throw new InventoryOperationException('invalid_result', "The change could not be applied to {$unit['label']}. Nothing was changed.");
        }
        $this->revisions->remember($apparatus, $after, $changeSet->getKey(), retire: false);

        return [
            'applied' => true,
            'change_id' => (int) $change->getKey(),
            'checklist_version_before' => $before['checklist_version'],
            'checklist_version_after' => $after['checklist_version'],
        ];
    }

    /** An earlier modify whose fields the new change fully replaces (or any earlier modify, for a removal). */
    private function supersedeEarlierChanges(ApparatusInventoryChange $change): void
    {
        $newFields = array_keys($change->payload ?? []);
        ApparatusInventoryChange::query()
            ->where('apparatus_id', $change->apparatus_id)
            ->where('item_key', $change->item_key)
            ->where('id', '<', $change->getKey())
            ->where('operation', ApparatusInventoryChange::OP_MODIFY)
            ->active()
            ->orderBy('id')
            ->get()
            ->filter(static fn (ApparatusInventoryChange $earlier): bool => $change->operation === ApparatusInventoryChange::OP_REMOVE
                || ($change->operation === ApparatusInventoryChange::OP_MODIFY && array_diff(array_keys($earlier->payload ?? []), $newFields) === []))
            ->each(static fn (ApparatusInventoryChange $earlier) => $earlier->forceFill([
                'status' => ApparatusInventoryChange::STATUS_SUPERSEDED,
                'superseded_by_change_id' => $change->getKey(),
                'status_changed_at' => now(),
            ])->save());
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<int|string>  $requested
     * @return array<int, array<string, mixed>> apparatus id => unit plan, ascending
     */
    private function selectedUnits(array $plan, array $requested): array
    {
        $byId = [];
        foreach ($plan['units'] as $unit) {
            $byId[$unit['apparatus_id']] = $unit;
        }
        $ids = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $requested)));
        $source = (int) $plan['source_apparatus_id'];
        if (! in_array($source, $ids, true) || ($byId[$source]['verdict'] ?? null) !== InventoryChangePlanner::ELIGIBLE) {
            throw ValidationException::withMessages(['apparatus' => ($byId[$source] ?? null) === null || $byId[$source]['verdict'] === InventoryChangePlanner::ELIGIBLE
                ? 'The reporting apparatus must be included.'
                : 'This decision cannot be applied to the reporting apparatus: '.mb_strtolower((string) $byId[$source]['verdict_label']).'.']);
        }
        if ($plan['scope'] === InventoryChangePlanner::SCOPE_UNIT && $ids !== [$source]) {
            throw ValidationException::withMessages(['apparatus' => 'This decision applies to the reporting apparatus only.']);
        }
        $selected = [];
        foreach ($ids as $id) {
            $unit = $byId[$id] ?? null;
            if ($unit === null || $unit['includable'] !== true) {
                throw ValidationException::withMessages(['apparatus' => 'One of the selected apparatus cannot receive this change. Review the impact again.']);
            }
            $selected[$id] = $unit;
        }
        ksort($selected);

        return $selected;
    }

    /**
     * The checklist the member was looking at: the current one, or a recently replaced one.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function checklistSeenBy(Apparatus $apparatus, string $version): array
    {
        $resolution = $this->resolver->resolve($apparatus);
        if ($resolution['usable'] !== true) {
            throw ValidationException::withMessages(['checklist_version' => 'The Daily Checkout checklist is unavailable. Contact an officer.']);
        }
        if (hash_equals((string) $resolution['checklist_version'], strtolower($version))) {
            return [$resolution['checklist'], (string) $resolution['checklist_type']];
        }
        $retired = $this->revisions->recentlyRetired((int) $apparatus->getKey(), $version);
        if ($retired === null) {
            throw new InventoryCorrectionConflict(
                'checklist_changed',
                'This apparatus\'s checklist changed since you opened it. Reload the checklist and report the correction again.',
            );
        }

        return [$retired, (string) $resolution['checklist_type']];
    }

    /**
     * Validates what the member pointed at and snapshots it.
     *
     * @param  array<string, mixed>  $checklist
     * @param  array<string, mixed>  $data
     * @return array{compartment_id: string, item_id: string|null, equipment_key: string|null, snapshot: array<string, mixed>}
     */
    private function target(array $checklist, array $data): array
    {
        $compartmentId = (string) $data['compartment_id'];
        $compartment = collect($checklist['compartments'] ?? [])->first(static fn (array $entry): bool => (string) ($entry['id'] ?? '') === $compartmentId);
        if (! is_array($compartment)) {
            throw ValidationException::withMessages(['compartment_id' => 'That compartment is not on this apparatus\'s checklist.']);
        }
        $proposal = is_array($data['proposal'] ?? null) ? $data['proposal'] : [];
        $destination = $proposal['to_compartment_id'] ?? null;
        if (is_string($destination) && $destination !== '') {
            $valid = collect($checklist['compartments'])->contains(static fn (array $entry): bool => (string) ($entry['id'] ?? '') === $destination);
            if (! $valid || $destination === $compartmentId) {
                throw ValidationException::withMessages(['proposal.to_compartment_id' => 'Choose a different compartment on this apparatus, or describe where it is now.']);
            }
        }

        $snapshot = ['compartment_id' => $compartmentId, 'compartment' => InventoryOverlay::compartmentName($compartment)];
        if ($data['reason'] === InventoryCorrectionReason::NEW_ITEM) {
            return ['compartment_id' => $compartmentId, 'item_id' => null, 'equipment_key' => null, 'snapshot' => $snapshot];
        }

        $itemId = (string) ($data['item_id'] ?? '');
        $found = app(InventoryOverlay::class)->findItem($checklist, $itemId);
        if ($found === null || (string) $found['compartment']['id'] !== $compartmentId) {
            throw ValidationException::withMessages(['item_id' => 'That item is not on this apparatus\'s checklist.']);
        }
        $item = $found['item'];

        return [
            'compartment_id' => $compartmentId,
            'item_id' => $itemId,
            'equipment_key' => EquipmentIdentity::forItem($item),
            'snapshot' => [
                ...$snapshot,
                'item_id' => $itemId,
                'name' => (string) $item['name'],
                'expectedQuantity' => $item['expectedQuantity'] ?? null,
                'manufacturer' => $item['manufacturer'] ?? null,
                'model' => $item['model'] ?? null,
                'description' => $item['description'] ?? null,
            ],
        ];
    }

    private function existingSubmission(User $reporter, string $clientRequestId, string $hash): ?ApparatusInventoryCorrection
    {
        $existing = ApparatusInventoryCorrection::query()->where('client_request_id', strtolower($clientRequestId))->first();
        if ($existing === null) {
            return null;
        }
        if ((int) $existing->reporter_user_id !== (int) $reporter->getKey() || ! hash_equals($existing->payload_hash, $hash)) {
            throw new InventoryCorrectionConflict('replay_conflict', 'This correction was already sent with different details. Start a new report.');
        }

        return $existing;
    }

    private function linkToSubmittedInspection(ApparatusInventoryCorrection $correction): void
    {
        if ($correction->inspection_draft_id === null) {
            return;
        }
        $inspectionId = ApparatusInspection::query()
            ->where('inventory_draft_id', $correction->inspection_draft_id)
            ->where('apparatus_id', $correction->apparatus_id)
            ->where('actor_user_id', $correction->reporter_user_id)
            ->orderByDesc('id')
            ->value('id');
        if ($inspectionId !== null) {
            $correction->forceFill(['apparatus_inspection_id' => $inspectionId])->save();
        }
    }

    private function lock(int $correctionId): ApparatusInventoryCorrection
    {
        return ApparatusInventoryCorrection::query()->whereKey($correctionId)->lockForUpdate()->firstOrFail();
    }

    private function lockForDecision(int $correctionId, User $reviewer, ?int $expectedLock): ApparatusInventoryCorrection
    {
        $correction = $this->lock($correctionId);
        $this->authorizeReview($reviewer);
        if (! $correction->isOpen()) {
            throw ValidationException::withMessages(['decision' => 'This correction was already decided ('.mb_strtolower(ApparatusInventoryCorrection::statusLabel($correction->status)).').']);
        }
        $this->assertLockVersion($correction, $expectedLock);

        return $correction;
    }

    private function assertLockVersion(ApparatusInventoryCorrection $correction, ?int $expectedLock): void
    {
        if ($expectedLock !== null && $expectedLock !== (int) $correction->lock_version) {
            throw new InventoryCorrectionConflict(
                InventoryCorrectionConflict::STALE_CORRECTION,
                'Someone else updated this correction while you were reviewing it. Reload it and decide again.',
            );
        }
    }

    private function transition(ApparatusInventoryCorrection $correction, string $status): void
    {
        $correction->forceFill(['status' => $status, 'lock_version' => (int) $correction->lock_version + 1])->save();
    }

    private function authorizeReview(User $reviewer): void
    {
        if (! InventoryCorrectionAccess::canReview($reviewer)) {
            throw new AuthorizationException;
        }
    }

    private function authorizeScope(User $reviewer, string $scope): void
    {
        $this->authorizeReview($reviewer);
        if ($scope !== InventoryChangePlanner::SCOPE_UNIT && ! InventoryCorrectionAccess::canPropagate($reviewer)) {
            throw new AuthorizationException('Changing more than one apparatus requires Logistics authority.');
        }
    }

    private function replayedEvent(?string $idempotencyKey): ?Event
    {
        return $idempotencyKey !== null && $idempotencyKey !== ''
            ? Event::query()->where('idempotency_key', $idempotencyKey)->first()
            : null;
    }

    /** @param array<string, mixed>|null $details */
    private function event(
        ApparatusInventoryCorrection $correction,
        ?User $actor,
        string $type,
        string $visibility,
        ?string $body,
        ?array $details = null,
        ?string $idempotencyKey = null,
        ?string $authorContext = null,
    ): Event {
        return Event::query()->create([
            'apparatus_inventory_correction_id' => $correction->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'event_type' => $type,
            'visibility' => $visibility,
            'author_context' => $authorContext ?? ($actor !== null && InventoryCorrectionAccess::canReview($actor) ? 'logistics' : 'member'),
            'body' => $body,
            'details' => $details,
            'idempotency_key' => $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : null,
        ]);
    }

    private function messageBody(string $body): string
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::MAX_MESSAGE) {
            throw ValidationException::withMessages(['body' => 'Write a message of at most '.self::MAX_MESSAGE.' characters.']);
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function cleanProposal(array $proposal): array
    {
        $clean = [];
        foreach (['to_compartment_id', 'to_location_text', 'name', 'manufacturer', 'model', 'description'] as $key) {
            if (($value = $this->optional($proposal[$key] ?? null)) !== null) {
                $clean[$key] = $value;
            }
        }
        if (is_int($proposal['quantity'] ?? null)) {
            $clean['quantity'] = $proposal['quantity'];
        }

        return $clean;
    }

    private function optional(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : $text;
    }

    /** @param array<string, mixed> $data */
    private function payloadHash(array $data): string
    {
        if (filled($data['photo'] ?? null)) {
            $data['photo'] = hash('sha256', (string) $data['photo']);
        }
        $canonical = static function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', json_encode($canonical($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function deletePhoto(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::PHOTO_DISK)->delete($path);
        }
    }
}
