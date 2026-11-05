<?php

declare(strict_types=1);

namespace App\Services\Defects;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusDefectParticipant;
use App\Models\User;
use App\Support\Fleet\DefectActionStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns several open reports of the same thing on one vehicle into ONE issue (the survivor) that every
 * member who reported it follows and that is resolved once.
 *
 * What moves to the survivor: the observations (one per inspection, so an inspection the survivor already
 * observed keeps its own and the duplicate's stays where it was), the members (a member already on the
 * survivor keeps one row, with the earliest and latest participation of both), the issue messages, a
 * service ticket the survivor lacks, and stable keys the survivor lacks. The survivor keeps the earliest
 * first report, the latest observation and the highest severity.
 *
 * What stays: each duplicate's own event history, concern rows and processing-exception evidence (all
 * append-only by design); the duplicate's duplicate_of_defect_id leads from them to the survivor.
 *
 * Each duplicate is closed as DISMISSED with resolution_type merged_duplicate (it was not repaired, so it
 * never counts as a fix) and reads "Merged". Nothing is sent to anyone: the survivor's members carry on.
 *
 * Lock order (the global one): apparatus row, then every involved defect in ascending id order.
 */
final class DefectConsolidationService
{
    public const RESOLUTION_TYPE = DefectActionStatus::MERGED_DUPLICATE;

    /**
     * @param  iterable<ApparatusDefect|int>  $duplicates
     * @return array{survivor: ApparatusDefect, merged: list<int>, skipped: list<int>} skipped = already merged into this survivor
     *
     * @throws ValidationException when the merge is not allowed (closed survivor, other apparatus, closed duplicate)
     */
    public function merge(ApparatusDefect $survivor, iterable $duplicates, User $actor, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Say why these reports are the same issue.']);
        }
        $duplicateIds = [];
        foreach ($duplicates as $duplicate) {
            $id = (int) ($duplicate instanceof ApparatusDefect ? $duplicate->getKey() : $duplicate);
            if ($id > 0 && $id !== (int) $survivor->getKey()) {
                $duplicateIds[$id] = $id;
            }
        }
        $duplicateIds = array_values($duplicateIds);
        sort($duplicateIds);

        return DB::transaction(function () use ($survivor, $duplicateIds, $actor, $reason): array {
            Apparatus::query()->whereKey($survivor->apparatus_id)->lockForUpdate()->first(['id']);
            $locked = ApparatusDefect::query()
                ->whereIn('id', [(int) $survivor->getKey(), ...$duplicateIds])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $keep = $locked->get((int) $survivor->getKey());
            if (! $keep instanceof ApparatusDefect || (bool) $keep->resolved) {
                throw ValidationException::withMessages(['survivor' => 'The issue to keep must still be open.']);
            }

            $merged = $skipped = [];
            $toMerge = $this->checkedDuplicates($keep, $locked, $duplicateIds, $skipped);
            foreach ($toMerge as $duplicate) {
                $this->absorb($keep, $duplicate, $actor, $reason);
                $merged[] = (int) $duplicate->getKey();
            }
            if ($merged !== []) {
                $keep->save();
                ApparatusDefectEvent::query()->create([
                    'apparatus_defect_id' => $keep->getKey(),
                    'actor_user_id' => $actor->getKey(),
                    'event_type' => 'duplicates_merged',
                    'details' => ['duplicate_defect_ids' => $merged, 'reason' => $reason],
                ]);
            }

            return ['survivor' => $keep->refresh(), 'merged' => $merged, 'skipped' => $skipped];
        }, 3);
    }

    /**
     * The duplicates that still need merging, after refusing anything that cannot be one.
     *
     * @param  Collection<int, ApparatusDefect>  $locked
     * @param  list<int>  $duplicateIds
     * @param  list<int>  $skipped  filled with the ids already merged into this survivor (a rerun)
     * @return list<ApparatusDefect>
     */
    private function checkedDuplicates(ApparatusDefect $survivor, Collection $locked, array $duplicateIds, array &$skipped): array
    {
        $pending = [];
        foreach ($duplicateIds as $id) {
            $duplicate = $locked->get($id);
            if (! $duplicate instanceof ApparatusDefect) {
                throw ValidationException::withMessages(['duplicates' => "Issue #{$id} does not exist."]);
            }
            if ((int) $duplicate->apparatus_id !== (int) $survivor->apparatus_id) {
                throw ValidationException::withMessages(['duplicates' => "Issue #{$id} is on another apparatus. Only reports on the same vehicle can be merged."]);
            }
            if ((int) $duplicate->duplicate_of_defect_id === (int) $survivor->getKey()) {
                $skipped[] = $id;

                continue;
            }
            if ((bool) $duplicate->resolved) {
                throw ValidationException::withMessages(['duplicates' => "Issue #{$id} is already closed. Only open reports can be merged."]);
            }
            $pending[] = $duplicate;
        }
        // Every pair, not just each duplicate against the survivor: the survivor takes over a missing key,
        // so two duplicates on different checklist items would end up joined through it.
        $members = [$survivor, ...$pending];
        foreach ($members as $i => $a) {
            foreach (array_slice($members, $i + 1) as $b) {
                if (self::conflicting($a, $b)) {
                    throw ValidationException::withMessages(['duplicates' => "Issues #{$a->id} and #{$b->id} are different checklist items ({$a->compartment_key} / {$a->item_key} and {$b->compartment_key} / {$b->item_key}). They cannot be merged."]);
                }
            }
        }

        return $pending;
    }

    /**
     * The checklist item a defect is about (compartment_key|item_key), or null when it has none. A member
     * concern's key names the report, not an item, so it is no identity: concerns never conflict by key.
     */
    public static function identityKey(ApparatusDefect $defect): ?string
    {
        if ($defect->item_key === null || $defect->compartment_key === InspectionConcernRecorder::COMPARTMENT_ID) {
            return null;
        }

        return $defect->compartment_key.'|'.$defect->item_key;
    }

    /** Two defects about different checklist items: both have an identity and the identities differ. */
    public static function conflicting(ApparatusDefect $a, ApparatusDefect $b): bool
    {
        $left = self::identityKey($a);
        $right = self::identityKey($b);

        return $left !== null && $right !== null && $left !== $right;
    }

    /** Moves one duplicate's history onto the survivor (in memory; the caller saves it) and closes the duplicate. */
    private function absorb(ApparatusDefect $survivor, ApparatusDefect $duplicate, User $actor, string $reason): void
    {
        $survivorId = (int) $survivor->getKey();
        $duplicateId = (int) $duplicate->getKey();
        $observations = $this->moveObservations($survivorId, $duplicateId);
        $participants = $this->moveParticipants($survivorId, $duplicateId);
        // Issue messages are append-only for their text and author; only the issue they hang on changes here.
        $messages = DB::table('apparatus_issue_messages')->where('apparatus_defect_id', $duplicateId)
            ->update(['apparatus_defect_id' => $survivorId]);

        $this->carryOver($survivor, $duplicate);
        $previous = (string) ($duplicate->action_status ?: DefectActionStatus::REPORTED);
        $duplicate->forceFill([
            'action_status' => DefectActionStatus::DISMISSED,
            'resolution_type' => self::RESOLUTION_TYPE,
            'resolution_notes' => sprintf('Merged into issue #%d (%s / %s). %s', $survivorId, (string) $survivor->compartment, (string) $survivor->item, $reason),
            'duplicate_of_defect_id' => $survivorId,
            'resolved_by_user_id' => $actor->getKey(),
        ])->save();
        ApparatusDefectEvent::query()->create([
            'apparatus_defect_id' => $duplicateId,
            'actor_user_id' => $actor->getKey(),
            'event_type' => 'merged_into',
            'from_status' => $previous,
            'to_status' => DefectActionStatus::DISMISSED,
            'details' => [
                'survivor_defect_id' => $survivorId,
                'reason' => $reason,
                'resolution_type' => self::RESOLUTION_TYPE,
                'previous_action_status' => $previous,
                'moved_observations' => $observations,
                'moved_participants' => $participants,
                'moved_messages' => $messages,
            ],
            'idempotency_key' => 'defect-merge:'.$duplicateId.':'.$survivorId,
        ]);

        // The duplicate is closed now, so the survivor can take over its stable keys without
        // tripping the one-open-row-per-key index.
        if ($survivor->item_key === null && self::identityKey($duplicate) !== null) {
            $survivor->compartment_key = $duplicate->compartment_key;
            $survivor->item_key = $duplicate->item_key;
        }
    }

    /** Observations are one per defect and inspection: an inspection the survivor already observed keeps its own. */
    private function moveObservations(int $survivorId, int $duplicateId): int
    {
        $seen = DB::table('apparatus_defect_observations')->where('apparatus_defect_id', $survivorId)->pluck('apparatus_inspection_id');

        // Append-only for what was observed; only the issue the observation belongs to changes here.
        return DB::table('apparatus_defect_observations')
            ->where('apparatus_defect_id', $duplicateId)
            ->when($seen->isNotEmpty(), static fn ($query) => $query->whereNotIn('apparatus_inspection_id', $seen->all()))
            ->update(['apparatus_defect_id' => $survivorId]);
    }

    /**
     * Every member of the duplicate follows the survivor. A member on both keeps the survivor's row with the
     * earliest and latest participation of the two; the duplicate's row stays (participants are never deleted).
     */
    private function moveParticipants(int $survivorId, int $duplicateId): int
    {
        $onSurvivor = ApparatusDefectParticipant::query()->where('apparatus_defect_id', $survivorId)->get()->keyBy('user_id');
        $moved = 0;
        foreach (ApparatusDefectParticipant::query()->where('apparatus_defect_id', $duplicateId)->orderBy('id')->get() as $row) {
            $existing = $onSurvivor->get($row->user_id);
            if ($existing === null) {
                $row->forceFill(['apparatus_defect_id' => $survivorId])->save();
                $moved++;

                continue;
            }
            $changes = ['employee_id' => $existing->employee_id ?? $row->employee_id];
            if ($this->isEarlier($row->first_participated_at, $existing->first_participated_at)) {
                $changes['first_participated_at'] = $row->first_participated_at;
                $changes['first_inspection_id'] = $row->first_inspection_id;
            }
            if ($this->isLater($row->last_participated_at, $existing->last_participated_at)) {
                $changes['last_participated_at'] = $row->last_participated_at;
                $changes['last_inspection_id'] = $row->last_inspection_id;
            }
            $existing->forceFill($changes)->save();
        }

        return $moved;
    }

    /** Earliest first report, latest observation, highest severity, and what the survivor lacks. */
    private function carryOver(ApparatusDefect $survivor, ApparatusDefect $duplicate): void
    {
        $survivorFirst = $survivor->first_reported_at ?? $survivor->created_at;
        $duplicateFirst = $duplicate->first_reported_at ?? $duplicate->created_at;
        if ($this->isEarlier($duplicateFirst, $survivorFirst)) {
            $survivor->first_reported_at = $duplicateFirst;
            $survivor->first_reported_inspection_id = $duplicate->first_reported_inspection_id ?? $survivor->first_reported_inspection_id;
            $survivor->first_reported_by_user_id = $duplicate->first_reported_by_user_id ?? $survivor->first_reported_by_user_id;
        }
        if ($this->isLater($duplicate->last_observed_at, $survivor->last_observed_at)) {
            $survivor->last_observed_at = $duplicate->last_observed_at;
            $survivor->last_observed_inspection_id = $duplicate->last_observed_inspection_id ?? $survivor->last_observed_inspection_id;
        }
        if ($duplicate->severity === 'significant') {
            $survivor->severity = 'significant';
        }
        $survivor->service_ticket_id ??= $duplicate->service_ticket_id;
        $survivor->operational_impact ??= $duplicate->operational_impact;
        $survivor->photo_path ??= $duplicate->photo_path;
    }

    /** True when $candidate is a time and $current is unset or later than it. */
    private function isEarlier(mixed $candidate, mixed $current): bool
    {
        if ($candidate === null) {
            return false;
        }

        return $current === null || Carbon::parse($candidate)->lt(Carbon::parse($current));
    }

    /** True when $candidate is a time and $current is unset or earlier than it. */
    private function isLater(mixed $candidate, mixed $current): bool
    {
        if ($candidate === null) {
            return false;
        }

        return $current === null || Carbon::parse($candidate)->gt(Carbon::parse($current));
    }
}
