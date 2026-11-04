<?php

declare(strict_types=1);

namespace App\Services\Defects;

use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\User;
use App\Services\Captain5\Captain5IssueScope;
use App\Services\Captain5\MedicalScope;
use App\Support\Fleet\DefectActionStatus;
use App\Support\Fleet\PostgresLockRetry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The one routing decision for an equipment issue (docs/unified-activity-and-ownership.md section 1): exactly one of
 * Captain 5 or Logistics works it. The rule follows the equipment (Captain5IssueScope::classify, the reviewed medical
 * mapping), never the apparatus type; unknown or ambiguous equipment goes to Logistics and waits in the Medical scope
 * review. A person's transfer ("manual") is kept until a person moves it again.
 *
 * This class writes apparatus_defects.responsible_owner and nothing else does. Callers authorize transfers.
 */
final class DefectOwnership
{
    public const BASIS_MEDICAL = 'medical_item';

    public const BASIS_NOT_MEDICAL = 'not_medical';

    public const BASIS_UNCLASSIFIED = 'unclassified';

    public const BASIS_NOT_EQUIPMENT = 'not_equipment';

    public const BASIS_STRETCHER = 'stretcher_report';

    public const BASIS_MANUAL = 'manual';

    public const EVENT_TRANSFERRED = 'owner_transferred';

    public const OWNERS = [ApparatusDefect::OWNER_CAPTAIN5, ApparatusDefect::OWNER_LOGISTICS];

    public function __construct(private readonly Captain5IssueScope $scope) {}

    /** What the rules say, ignoring any person's transfer. */
    public function decide(ApparatusDefect $defect): OwnerDecision
    {
        $result = $this->scope->classify($defect);
        if ($result->reason === Captain5IssueScope::REASON_STRETCHER_REPORT) {
            return new OwnerDecision(ApparatusDefect::OWNER_CAPTAIN5, self::BASIS_STRETCHER, $result->checklistType, $result->itemKey);
        }
        if ($result->reason === 'non_equipment') {
            return new OwnerDecision(ApparatusDefect::OWNER_LOGISTICS, self::BASIS_NOT_EQUIPMENT, $result->checklistType, $result->itemKey);
        }

        return match ($result->classification) {
            MedicalScope::MEDICAL => new OwnerDecision(ApparatusDefect::OWNER_CAPTAIN5, self::BASIS_MEDICAL, $result->checklistType, $result->itemKey),
            MedicalScope::NOT_MEDICAL => new OwnerDecision(ApparatusDefect::OWNER_LOGISTICS, self::BASIS_NOT_MEDICAL, $result->checklistType, $result->itemKey),
            default => new OwnerDecision(ApparatusDefect::OWNER_LOGISTICS, self::BASIS_UNCLASSIFIED, $result->checklistType, $result->itemKey),
        };
    }

    /** Whether Captain 5 is responsible (the stored decision, or the rule for a row not projected yet). */
    public static function isCaptain5Owned(ApparatusDefect $defect): bool
    {
        return app(self::class)->ownerOf($defect) === ApparatusDefect::OWNER_CAPTAIN5;
    }

    /** The responsible owner: the stored decision, or the rule's answer for a row not projected yet. */
    public function ownerOf(ApparatusDefect $defect): string
    {
        $stored = $defect->getAttribute('responsible_owner');

        return in_array($stored, self::OWNERS, true) ? $stored : $this->decide($defect)->owner;
    }

    /**
     * Stores the rule's owner (the system decides; a manual transfer is kept). A change of an already-stored owner is a
     * real transfer and leaves an owner_transferred event; the first projection is quiet. Returns whether it changed.
     */
    public function project(ApparatusDefect $defect, string $reason = 'classification'): bool
    {
        if ($defect->getAttribute('owner_basis') === self::BASIS_MANUAL && in_array($defect->getAttribute('responsible_owner'), self::OWNERS, true)) {
            return false;
        }
        if (self::hasUnadoptedClear($defect)) {
            // Cleared by the previous image (before this release, or during a rollback): honor that person's choice.
            $this->adoptLegacyClear($defect);

            return true;
        }
        $decision = $this->decide($defect);
        $previous = $defect->getAttribute('responsible_owner');
        if ($previous === $decision->owner && $defect->getAttribute('owner_basis') === $decision->basis) {
            return false;
        }

        $this->write($defect, $decision->owner, $decision->basis, null);
        if (in_array($previous, self::OWNERS, true) && $previous !== $decision->owner) {
            $this->recordTransfer($defect, null, $previous, $decision->owner, $decision->basis, ['reason' => $reason], null, null);
        }

        return true;
    }

    /**
     * A person moves the issue to the other queue (Captain 5 "Clear, this is for Logistics" / "Bring back", Logistics
     * "Send to Captain 5"). Same rules as the corrective-action engine: row lock, idempotency key, optional state token,
     * closed issues refused. The caller has already authorized $actor.
     *
     * @param  array<string, mixed>  $audit  actor context written to details.actor_context
     *
     * @throws ValidationException|StaleDefectStateException
     */
    public function transfer(User $actor, int $defectId, string $to, ?string $expectedState, string $key, ?string $note, array $audit = []): ApparatusDefect
    {
        if (! in_array($to, self::OWNERS, true)) {
            throw ValidationException::withMessages(['owner' => 'Choose Captain 5 or Logistics.']);
        }
        $storedKey = 'defect:'.$defectId.':owner:'.$to.':'.$key;

        return PostgresLockRetry::run(fn (): ApparatusDefect => DB::transaction(function () use ($actor, $defectId, $to, $expectedState, $storedKey, $note, $audit): ApparatusDefect {
            $defect = ApparatusDefect::query()->lockForUpdate()->findOrFail($defectId);
            if (ApparatusDefectEvent::query()->where('idempotency_key', $storedKey)->exists()) {
                return $defect; // a retry of a transfer that already took effect
            }
            if ($expectedState !== null && ! hash_equals(DefectCorrectiveActionService::stateToken($defect), $expectedState)) {
                throw new StaleDefectStateException($defect);
            }
            if (DefectActionStatus::isClosed($defect->action_status)) {
                throw ValidationException::withMessages(['defect' => 'This issue is closed. It is under History now.']);
            }
            if ($to === ApparatusDefect::OWNER_LOGISTICS && (string) $defect->compartment_key === ApparatusDefect::STRETCHER_REPORT_COMPARTMENT) {
                throw ValidationException::withMessages(['defect' => 'Stretcher reports always stay with Captain 5.']);
            }
            $from = $this->ownerOf($defect);
            if ($from === $to) {
                return $defect; // already in that queue
            }

            $this->write($defect, $to, self::BASIS_MANUAL, $actor);
            $trimmed = $note === null ? '' : trim($note);
            $this->recordTransfer($defect, $actor, $from, $to, self::BASIS_MANUAL, $audit === [] ? [] : ['actor_context' => $audit], $trimmed === '' ? null : $trimmed, $storedKey);

            return $defect->refresh();
        }, 3));
    }

    /**
     * Re-applies the rules to open issues (after a template review, an administrator's item decision, or the release
     * backfill). Manual decisions are kept. With $dryRun nothing is written.
     *
     * @param  (callable(ApparatusDefect, OwnerDecision, ?string): void)|null  $report  called for each issue whose stored owner differs
     * @return array{examined: int, changed: int}
     */
    public function reproject(bool $dryRun, ?callable $report = null, ?string $checklistType = null, ?string $itemKey = null): array
    {
        $examined = $changed = 0;
        ApparatusDefect::query()->where('resolved', false)
            // Older rows have no stored key; classify() finds theirs by name, so they are compared below.
            ->when($itemKey !== null, fn ($query) => $query->where(fn ($key) => $key->where('item_key', $itemKey)->orWhereNull('item_key')))
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($dryRun, $report, $checklistType, $itemKey, &$examined, &$changed): void {
                foreach ($chunk as $defect) {
                    $decision = $this->decide($defect);
                    if (($checklistType !== null && $decision->checklistType !== $checklistType) || ($itemKey !== null && $decision->itemKey !== $itemKey)) {
                        continue;
                    }
                    $examined++;
                    $stored = $defect->getAttribute('responsible_owner');
                    $differs = $defect->getAttribute('owner_basis') !== self::BASIS_MANUAL
                        && ($stored !== $decision->owner || $defect->getAttribute('owner_basis') !== $decision->basis);
                    if (! $differs) {
                        continue;
                    }
                    if ($report !== null) {
                        $report($defect, $decision, is_string($stored) ? $stored : null);
                    }
                    if (! $dryRun && DB::transaction(fn (): bool => $this->project(ApparatusDefect::query()->lockForUpdate()->findOrFail($defect->getKey()), 'reprojection'))) {
                        $changed++;
                    }
                }
            });

        return ['examined' => $examined, 'changed' => $changed];
    }

    /** A "cleared for Logistics" mark that no ownership decision has taken in yet (written by the previous image). */
    public static function hasUnadoptedClear(ApparatusDefect $defect): bool
    {
        $cleared = $defect->captain5_cleared_at;
        if ($cleared === null || $defect->getAttribute('owner_basis') === self::BASIS_MANUAL) {
            return false;
        }
        $set = $defect->owner_set_at;

        return $set === null || $cleared->greaterThan($set);
    }

    /**
     * Open issues whose stored owner is missing or out of date: not projected yet (rows the previous image wrote, a
     * projection that failed) or carrying an unadopted clear. The scheduled `defects:project-ownership --pending`
     * keeps this empty so every issue sits in exactly one queue.
     *
     * @return \Illuminate\Database\Eloquent\Builder<ApparatusDefect>
     */
    public static function pendingQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return ApparatusDefect::query()->where('resolved', false)->where(fn ($query) => $query
            ->whereNull('responsible_owner')
            ->orWhere(fn ($clear) => $clear->whereNotNull('captain5_cleared_at')
                ->where(fn ($basis) => $basis->whereNull('owner_basis')->orWhere('owner_basis', '<>', self::BASIS_MANUAL))
                ->where(fn ($set) => $set->whereNull('owner_set_at')->orWhereColumn('captain5_cleared_at', '>', 'owner_set_at'))));
    }

    /**
     * Release backfill for an open issue Captain 5 had cleared before ownership existed. If its equipment is Logistics'
     * anyway, the rule owns it and the stale "cleared" mark goes; if it is medical, the person's choice stands as a
     * manual Logistics decision (Captain 5 can still Bring it back). Quiet: the clear event already recorded the move.
     */
    public function adoptLegacyClear(ApparatusDefect $defect): string
    {
        $decision = $this->decide($defect);
        if ($decision->owner === ApparatusDefect::OWNER_LOGISTICS) {
            $this->write($defect, $decision->owner, $decision->basis, null);
            $defect->timestamps = false;
            $defect->forceFill(['captain5_cleared_at' => null, 'captain5_cleared_by_user_id' => null])->saveQuietly();
            $defect->timestamps = true;

            return 'rule';
        }
        $clearedBy = $defect->captain5_cleared_by_user_id;
        $defect->timestamps = false;
        $defect->forceFill([
            'responsible_owner' => ApparatusDefect::OWNER_LOGISTICS,
            'owner_basis' => self::BASIS_MANUAL,
            'owner_set_at' => $defect->captain5_cleared_at ?? now(),
            'owner_set_by_user_id' => $clearedBy,
        ])->saveQuietly();
        $defect->timestamps = true;

        return 'manual';
    }

    private function write(ApparatusDefect $defect, string $owner, string $basis, ?User $actor): void
    {
        $values = [
            'responsible_owner' => $owner,
            'owner_basis' => $basis,
            'owner_set_at' => now(),
            'owner_set_by_user_id' => $actor?->getKey(),
        ];
        if ($basis === self::BASIS_MANUAL) {
            // Kept in step for the previous image, which reads these for its "cleared for Logistics" list, and for
            // Captain 5's own "sent to Logistics" list that offers Bring back.
            $cleared = $owner === ApparatusDefect::OWNER_LOGISTICS;
            $values['captain5_cleared_at'] = $cleared ? now() : null;
            $values['captain5_cleared_by_user_id'] = $cleared ? $actor?->getKey() : null;
        }
        // Quiet and without touching updated_at: the move itself is recorded as an event.
        $defect->timestamps = false;
        $defect->forceFill($values)->saveQuietly();
        $defect->timestamps = true;
    }

    /** @param array<string, mixed> $extra */
    private function recordTransfer(ApparatusDefect $defect, ?User $actor, string $from, string $to, string $basis, array $extra, ?string $note, ?string $key): void
    {
        $status = (string) ($defect->action_status ?: DefectActionStatus::REPORTED);
        ApparatusDefectEvent::query()->create([
            'apparatus_defect_id' => $defect->getKey(),
            'apparatus_inspection_id' => null,
            'actor_user_id' => $actor?->getKey(),
            'event_type' => self::EVENT_TRANSFERRED,
            'from_status' => $status,
            'to_status' => $status,
            'member_message' => null,
            'internal_note' => $note,
            'details' => ['from' => $from, 'to' => $to, 'basis' => $basis, ...$extra],
            'idempotency_key' => $key ?? 'defect:'.$defect->getKey().':owner-rule:'.Str::uuid()->toString(),
        ]);
    }
}
