<?php

declare(strict_types=1);

namespace App\Services\Defects;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusDefectParticipant;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Services\InspectionPaperFields;
use App\Support\Fleet\DefectActionStatus;
use App\Support\HubTimezone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One persistent issue history per physical vehicle item
 * (docs/daily-inspection-continuity.md section 4.1).
 *
 * Lock order: the caller has already locked the apparatus row; this service then
 * locks every candidate unresolved defect in ascending id order.
 */
final class DefectContinuityService
{
    private const ISSUE_STATUSES = ['Missing', 'Damaged'];

    /**
     * Called inside the submission transaction; the apparatus row is already locked.
     *
     * @param  array<int, array<string, mixed>>  $observations  inspection result compartments (+ scheduled duties pseudo-compartment)
     * @param  array<string, array<string, array<string, mixed>>>  $definitions  InspectionPaperFields::equipmentDefinitions()
     * @param  array<string, mixed>  $evidence  the inspection's pending_effects (defect photo paths)
     */
    public function recordFindings(
        ApparatusInspection $inspection,
        Apparatus $physicalApparatus,
        array $observations,
        array $definitions,
        array $evidence,
    ): FindingsResult {
        $inspection->assertOperational();
        $entries = $this->entries($observations, $definitions, $evidence);
        if ($entries === []) {
            return new FindingsResult;
        }
        $open = $this->lockOpenDefects((int) $physicalApparatus->id, $entries);
        $targets = $this->assignTargets($entries, $open, $definitions);
        $recurrences = $this->recurrenceCandidates((int) $physicalApparatus->id, $targets, $definitions);

        $new = $confirmed = $corrected = $significant = $participation = [];
        foreach ($targets as $target) {
            $entry = $this->representative($target['entries']);
            $defect = $target['defect'];
            $created = false;
            if ($defect === null) {
                if (! $entry['is_issue']) {
                    continue;
                }
                [$defect, $created] = $this->createOrFind($physicalApparatus, $inspection, $entry, $recurrences[$target['group']] ?? null);
            }
            $significantDamage = $entry['significant'] && $entry['status'] === 'Damaged';
            if ($created) {
                $observation = 'first_reported';
                $new[] = (int) $defect->id;
                if ($significantDamage) {
                    $significant[] = (int) $defect->id;
                }
                $this->event($defect, $inspection, 'reported', ['to_status' => DefectActionStatus::REPORTED, 'details' => $this->details($entry)]);
                $participation[(int) $defect->id] = 'reported';
            } elseif ($entry['is_issue']) {
                $observation = 'confirmed_again';
                $confirmed[] = (int) $defect->id;
                $changes = $this->observedChanges($defect, $inspection, $entry);
                if ($significantDamage && $defect->severity !== 'significant') {
                    $changes['severity'] = 'significant';
                    $significant[] = (int) $defect->id;
                }
                $defect->forceFill($changes)->save();
                $this->event($defect, $inspection, 'observed_again', ['details' => $this->details($entry)]);
                if (isset($changes['severity'])) {
                    $this->event($defect, $inspection, 'escalated', ['details' => ['severity' => 'significant']]);
                }
                $participation[(int) $defect->id] = 'confirmed';
            } else {
                $observation = 'appears_corrected';
                $corrected[] = (int) $defect->id;
                $defect->forceFill($this->observedChanges($defect, $inspection, $entry))->save();
                $this->event($defect, $inspection, 'appears_corrected', ['details' => $this->details($entry)]);
                $participation[(int) $defect->id] = 'verified_corrected';
            }
            ApparatusDefectObservation::query()->create([
                'apparatus_defect_id' => $defect->id,
                'apparatus_inspection_id' => $inspection->id,
                'actor_user_id' => $inspection->actor_user_id,
                'observation' => $observation,
                'reported_status' => $entry['status'],
                'notes' => $entry['notes'],
                'photo_path' => $entry['photo_path'],
            ]);
            // A finding is never itself permission to take a unit OOS or
            // return it to service. Its authorized disposition remains a
            // later, explicit administrative decision; an unclassified
            // finding alone is not a processing exception.
        }
        $this->enrollParticipants($inspection, $participation);

        return new FindingsResult($new, $confirmed, $corrected, array_values(array_unique($significant)));
    }

    /**
     * Legacy replay entry point (ApparatusDefect::recordDefect): records one finding by
     * exact names without ever rewriting the origin report, its notes, photo or status.
     */
    public function recordLegacyFinding(
        int $apparatusId,
        string $compartment,
        string $item,
        string $status,
        ?string $notes,
        ?string $photoPath,
        ?int $inspectionId,
    ): ApparatusDefect {
        $issueType = strtolower($status);
        $inspection = $inspectionId !== null ? ApparatusInspection::query()->find($inspectionId) : null;
        $inspection?->assertOperational();
        $existing = ApparatusDefect::query()
            ->where('apparatus_id', $apparatusId)->where('compartment', $compartment)->where('item', $item)
            ->unresolved()->lockForUpdate()->first();

        if ($existing !== null) {
            $history = $existing->defect_history ?? [];
            $history[] = [
                'status' => $existing->status,
                'notes' => $existing->notes,
                'photo' => $existing->photo,
                'reported_at' => $existing->created_at?->toISOString(),
            ];
            $existing->forceFill([
                'issue_type' => $issueType,
                'notes' => filled($existing->notes) ? $existing->notes : $notes,
                'photo_path' => filled($existing->photo_path) ? $existing->photo_path : $photoPath,
                'defect_history' => $history,
                'last_observed_inspection_id' => $inspectionId ?? $existing->last_observed_inspection_id,
                'last_observed_at' => now(),
            ])->save();
            $this->event($existing, $inspection, 'observed_again', ['details' => ['status' => $status, 'notes' => $notes]]);
            $this->enrollParticipants($inspection, [(int) $existing->id => 'confirmed']);

            return $existing;
        }

        $entry = ['compartment' => $compartment, 'item' => $item, 'status' => $status, 'notes' => $notes, 'photo_path' => $photoPath,
            'significant' => false, 'compartment_id' => null, 'item_id' => null];
        $prior = ApparatusDefect::query()->where('apparatus_id', $apparatusId)->where('compartment', $compartment)
            ->where('item', $item)->where('resolved', true)->orderByDesc('id')->value('id');
        $apparatus = new Apparatus;
        $apparatus->id = $apparatusId;
        [$defect] = $this->createOrFind($apparatus, $inspection, $entry, $prior !== null ? (int) $prior : null);
        $this->event($defect, $inspection, 'reported', ['to_status' => DefectActionStatus::REPORTED, 'details' => $this->details($entry)]);
        $this->enrollParticipants($inspection, [(int) $defect->id => 'reported']);

        return $defect;
    }

    /**
     * Open issues for the Daily Checkout "previously reported" display. Member-safe: no
     * personnel, internal notes, photos or private storage paths.
     *
     * @param  array<string, mixed>  $checklist
     * @return list<array<string, mixed>>
     */
    public function openFindings(Apparatus $apparatus, array $checklist): array
    {
        $definitions = app(InspectionPaperFields::class)->equipmentDefinitions($checklist);
        $flat = [];
        foreach ($definitions as $compartmentId => $items) {
            foreach ($items as $itemId => $identity) {
                $flat[] = ['compartment_id' => (string) $compartmentId, 'item_id' => (string) $itemId, 'identity' => $identity];
            }
        }
        $defects = $apparatus->openDefects()->with(['latestObservation', 'serviceTicket'])->orderBy('id')->get();
        $updates = $this->latestMemberUpdates($defects->pluck('id')->all());

        return $defects->map(static function (ApparatusDefect $defect) use ($flat, $updates): array {
            $matches = array_values(array_filter($flat, static fn (array $row): bool => in_array($defect->compartment, $row['identity']['compartment_names'], true)
                && in_array($defect->item, $row['identity']['item_names'], true)));
            $match = count($matches) === 1 ? $matches[0] : null;
            $identity = $match['identity'] ?? null;

            return [
                'id' => $defect->id,
                'compartment' => $identity['compartment'] ?? $defect->compartment,
                'item' => $identity['item'] ?? $defect->item,
                'issue_type' => $defect->issue_type,
                'operational_impact' => $defect->operational_impact,
                'last_observation' => $defect->latestObservation?->observation,
                'last_observed_at' => $defect->latestObservation?->created_at?->toIso8601String(),
                'service_status' => $defect->serviceTicket?->status,
                // Where the item is listed now (an approved inventory move keeps its id and adds the old
                // compartment as an alias); the stored keys only when the current checklist is ambiguous.
                'compartment_id' => $match['compartment_id'] ?? $defect->compartment_key,
                'item_id' => $match['item_id'] ?? $defect->item_key,
                'action_status' => $defect->action_status,
                'action_label' => DefectActionStatus::label($defect->action_status),
                'severity' => $defect->severity,
                'first_reported_at' => $defect->first_reported_at?->toIso8601String(),
                'latest_member_update' => $updates[$defect->id] ?? null,
            ];
        })->all();
    }

    /**
     * Flatten submitted compartments into one entry per item, with stable keys and photo evidence.
     *
     * @return list<array<string, mixed>>
     */
    private function entries(array $observations, array $definitions, array $evidence): array
    {
        $entries = [];
        foreach ($observations as $compartment) {
            foreach ($compartment['items'] ?? [] as $item) {
                $identity = $definitions[$compartment['id'] ?? ''][$item['id'] ?? ''] ?? null;
                $photoPath = $item['photo_path'] ?? null;
                foreach ($evidence['defects'] ?? [] as $finding) {
                    if ($finding['compartment'] === $compartment['name'] && $finding['item'] === $item['name']) {
                        $photoPath = $finding['photo_path'] ?? null;
                        break;
                    }
                }
                $hasKeys = isset($compartment['id'], $item['id']) && is_scalar($compartment['id']) && is_scalar($item['id']);
                $entries[] = [
                    'compartment_id' => $hasKeys ? mb_substr((string) $compartment['id'], 0, 128) : null,
                    'item_id' => $hasKeys ? mb_substr((string) $item['id'], 0, 128) : null,
                    'compartment' => $compartment['name'],
                    'item' => $item['name'],
                    'status' => $item['status'],
                    'is_issue' => in_array($item['status'], self::ISSUE_STATUSES, true),
                    'significant' => ($item['significant'] ?? false) === true,
                    'notes' => $item['notes'] ?? null,
                    'photo_path' => $photoPath,
                    'identity' => $identity,
                    'compartment_names' => $this->lookupNames($identity['compartment_names'] ?? [$compartment['name']]),
                    'item_names' => $this->lookupNames($identity['item_names'] ?? [$item['name']]),
                ];
            }
        }

        return $entries;
    }

    /**
     * Load and lock, in one query and ascending id order, every unresolved defect any
     * submitted item could match by stable key or by name/alias.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<int, ApparatusDefect>
     */
    private function lockOpenDefects(int $apparatusId, array $entries): array
    {
        $itemKeys = array_values(array_unique(array_filter(array_column($entries, 'item_id'))));
        $compartmentNames = $itemNames = [];
        foreach ($entries as $entry) {
            array_push($compartmentNames, ...$entry['compartment_names']);
            array_push($itemNames, ...$entry['item_names']);
        }
        $compartmentNames = array_values(array_unique($compartmentNames));
        $itemNames = array_values(array_unique($itemNames));

        return ApparatusDefect::query()
            ->where('apparatus_id', $apparatusId)
            ->where('resolved', false)
            ->where(function ($query) use ($itemKeys, $compartmentNames, $itemNames): void {
                $query->where(function ($names) use ($compartmentNames, $itemNames): void {
                    $names->whereIn('compartment', $compartmentNames)->whereIn('item', $itemNames);
                });
                if ($itemKeys !== []) {
                    $query->orWhereIn('item_key', $itemKeys);
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * Resolve every entry to an existing defect (key first, then name/alias) or to a
     * to-be-created group, so one defect never receives two observations.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<int, ApparatusDefect>  $open
     * @return list<array{defect: ?ApparatusDefect, group: string, entries: list<array<string, mixed>>}>
     */
    private function assignTargets(array $entries, array $open, array $definitions): array
    {
        $paperFields = app(InspectionPaperFields::class);
        $byKey = [];
        foreach ($open as $defect) {
            if ($defect->item_key !== null) {
                $byKey[$defect->compartment_key.'|'.$defect->item_key] = $defect;
            }
        }
        $assigned = array_fill(0, count($entries), null);
        $claimed = [];
        foreach ($entries as $index => $entry) {
            if ($entry['item_id'] !== null && isset($byKey[$entry['compartment_id'].'|'.$entry['item_id']])) {
                $assigned[$index] = $byKey[$entry['compartment_id'].'|'.$entry['item_id']];
                $claimed[$assigned[$index]->id] = true;
            }
        }
        foreach ($entries as $index => $entry) {
            if ($assigned[$index] !== null) {
                continue;
            }
            foreach ($open as $candidate) {
                if (isset($claimed[$candidate->id])) {
                    continue;
                }
                if (in_array($candidate->compartment, $entry['compartment_names'], true)
                    && in_array($candidate->item, $entry['item_names'], true)
                    && ($entry['identity'] === null || count($paperFields->matchingEquipment($definitions, $candidate->compartment, $candidate->item)) === 1)) {
                    $assigned[$index] = $candidate;
                    $claimed[$candidate->id] = true;
                    break;
                }
            }
        }
        $targets = [];
        foreach ($entries as $index => $entry) {
            $defect = $assigned[$index];
            if ($defect === null && $entry['is_issue']) {
                // The unresolved-name unique index allows one open row per name pair:
                // an unmatched issue that shares exact names merges into that row.
                foreach ($open as $candidate) {
                    if ((string) $candidate->compartment === (string) $entry['compartment'] && (string) $candidate->item === (string) $entry['item']) {
                        $defect = $candidate;
                        break;
                    }
                }
            }
            if ($defect === null && ! $entry['is_issue']) {
                continue;
            }
            $group = $defect !== null ? 'd:'.$defect->id : 'n:'.$entry['compartment']."\0".$entry['item'];
            $targets[$group]['defect'] = $defect;
            $targets[$group]['group'] = $group;
            $targets[$group]['entries'][] = $entry;
        }

        return array_values($targets);
    }

    /**
     * The entry that represents a defect in this inspection: a reported issue beats a Present
     * answer, and significant damage beats routine.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function representative(array $entries): array
    {
        $best = $entries[0];
        foreach ($entries as $entry) {
            $rank = static fn (array $e): int => ($e['is_issue'] ? 2 : 0) + ($e['significant'] && $e['status'] === 'Damaged' ? 1 : 0);
            if ($rank($entry) > $rank($best)) {
                $best = $entry;
            }
        }

        return $best;
    }

    /**
     * Most recent resolved earlier episode for each to-be-created defect (recurrence link).
     *
     * @param  list<array{defect: ?ApparatusDefect, group: string, entries: list<array<string, mixed>>}>  $targets
     * @return array<string, int>
     */
    private function recurrenceCandidates(int $apparatusId, array $targets, array $definitions): array
    {
        $new = array_values(array_filter($targets, static fn (array $target): bool => $target['defect'] === null));
        if ($new === []) {
            return [];
        }
        $itemKeys = $compartmentNames = $itemNames = [];
        foreach ($new as $target) {
            foreach ($target['entries'] as $entry) {
                if ($entry['item_id'] !== null) {
                    $itemKeys[] = $entry['item_id'];
                }
                array_push($compartmentNames, ...$entry['compartment_names']);
                array_push($itemNames, ...$entry['item_names']);
            }
        }
        $resolved = ApparatusDefect::query()
            ->where('apparatus_id', $apparatusId)
            ->where('resolved', true)
            ->where(function ($query) use ($itemKeys, $compartmentNames, $itemNames): void {
                $query->where(function ($names) use ($compartmentNames, $itemNames): void {
                    $names->whereIn('compartment', array_values(array_unique($compartmentNames)))->whereIn('item', array_values(array_unique($itemNames)));
                });
                if ($itemKeys !== []) {
                    $query->orWhereIn('item_key', array_values(array_unique($itemKeys)));
                }
            })
            ->orderByDesc('id')
            ->get(['id', 'compartment', 'item', 'compartment_key', 'item_key']);
        $paperFields = app(InspectionPaperFields::class);
        $found = [];
        foreach ($new as $target) {
            $entry = $target['entries'][0];
            foreach ($resolved as $prior) {
                $keyMatch = $entry['item_id'] !== null && $prior->item_key === $entry['item_id'] && $prior->compartment_key === $entry['compartment_id'];
                $nameMatch = in_array((string) $prior->compartment, $entry['compartment_names'], true)
                    && in_array((string) $prior->item, $entry['item_names'], true)
                    && ($entry['identity'] === null || count($paperFields->matchingEquipment($definitions, (string) $prior->compartment, (string) $prior->item)) === 1);
                if ($keyMatch || $nameMatch) {
                    $found[$target['group']] = (int) $prior->id;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Create the defect inside a savepoint. If a concurrent writer already holds the open
     * row (partial unique index), return that row as an existing defect instead of failing
     * the whole submission.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: ApparatusDefect, 1: bool}
     */
    private function createOrFind(Apparatus $apparatus, ?ApparatusInspection $inspection, array $entry, ?int $recurrenceOf): array
    {
        $significant = $entry['significant'] && $entry['status'] === 'Damaged';
        $attributes = [
            'apparatus_id' => $apparatus->id,
            'compartment' => $entry['compartment'],
            'item' => $entry['item'],
            'status' => 'open',
            'action_status' => DefectActionStatus::REPORTED,
            'issue_type' => strtolower((string) $entry['status']),
            'notes' => $entry['notes'],
            'photo_path' => $entry['photo_path'],
            'apparatus_inspection_id' => $inspection?->id,
            'reported_date' => HubTimezone::today()->toDateString(),
            'compartment_key' => $entry['compartment_id'],
            'item_key' => $entry['item_id'],
            'severity' => $significant ? 'significant' : 'routine',
            'first_reported_inspection_id' => $inspection?->id,
            'first_reported_by_user_id' => $inspection?->actor_user_id,
            'first_reported_at' => now(),
            'last_observed_inspection_id' => $inspection?->id,
            'last_observed_at' => now(),
            'recurrence_of_defect_id' => $recurrenceOf,
        ];
        try {
            return [DB::transaction(static fn (): ApparatusDefect => ApparatusDefect::query()->create($attributes)), true];
        } catch (UniqueConstraintViolationException $exception) {
            $winner = ApparatusDefect::query()
                ->where('apparatus_id', $apparatus->id)->where('compartment', $entry['compartment'])->where('item', $entry['item'])
                ->where('resolved', false)->lockForUpdate()->first();
            if ($winner === null) {
                throw $exception;
            }

            return [$winner, false];
        }
    }

    /**
     * Bookkeeping applied to an existing defect on every observation. Never touches status.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function observedChanges(ApparatusDefect $defect, ApparatusInspection $inspection, array $entry): array
    {
        $changes = [
            'last_observed_inspection_id' => $inspection->id,
            'last_observed_at' => now(),
        ];
        if ($entry['is_issue'] && $defect->issue_type !== strtolower((string) $entry['status'])) {
            $changes['issue_type'] = strtolower((string) $entry['status']);
        }
        if ($defect->item_key === null && $entry['item_id'] !== null) {
            $changes['compartment_key'] = $entry['compartment_id'];
            $changes['item_key'] = $entry['item_id'];
        }

        return $changes;
    }

    /** @param array<string, mixed> $entry */
    private function details(array $entry): array
    {
        return array_filter([
            'reported_status' => $entry['status'],
            'notes' => $entry['notes'],
            'significant' => $entry['significant'] && $entry['status'] === 'Damaged' ? true : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $attributes */
    private function event(ApparatusDefect $defect, ?ApparatusInspection $inspection, string $type, array $attributes): void
    {
        ApparatusDefectEvent::query()->create([
            'apparatus_defect_id' => $defect->id,
            'apparatus_inspection_id' => $inspection?->id,
            'actor_user_id' => $inspection?->actor_user_id,
            'event_type' => $type,
            'from_status' => $attributes['from_status'] ?? null,
            'to_status' => $attributes['to_status'] ?? null,
            'details' => $attributes['details'] ?? null,
        ]);
    }

    /**
     * Enrol the submitting member once per defect; later participation only advances the
     * last_* markers. Legacy inspections without an actor enrol nobody.
     *
     * @param  array<int, string>  $sources  defect id => enrollment source
     */
    private function enrollParticipants(?ApparatusInspection $inspection, array $sources): void
    {
        $userId = $inspection?->actor_user_id;
        if ($inspection === null || $userId === null || $sources === []) {
            return;
        }
        $existing = ApparatusDefectParticipant::query()
            ->where('user_id', $userId)
            ->whereIn('apparatus_defect_id', array_keys($sources))
            ->get()->keyBy('apparatus_defect_id');
        foreach ($sources as $defectId => $source) {
            $row = $existing->get($defectId);
            if ($row === null) {
                ApparatusDefectParticipant::query()->create([
                    'apparatus_defect_id' => $defectId,
                    'user_id' => $userId,
                    'employee_id' => $inspection->employee_id,
                    'enrollment_source' => $source,
                    'first_inspection_id' => $inspection->id,
                    'last_inspection_id' => $inspection->id,
                    'first_participated_at' => now(),
                    'last_participated_at' => now(),
                ]);
            } else {
                $row->forceFill(['last_inspection_id' => $inspection->id, 'last_participated_at' => now()])->save();
            }
        }
    }

    /**
     * Latest member-visible update per defect: a member-visible message or an event's
     * member_message. Internal notes and internal messages are never read here.
     *
     * @param  list<int>  $defectIds
     * @return array<int, array{text: string, at: string}>
     */
    private function latestMemberUpdates(array $defectIds): array
    {
        if ($defectIds === []) {
            return [];
        }
        $candidates = new Collection;
        // Only the department (Logistics or Captain 5) speaks to the whole crew: a reply written by
        // another member is never shown here. The author context is snapshotted when the message was
        // written, so later role changes cannot relabel history.
        ApparatusIssueMessage::query()->whereIn('apparatus_defect_id', $defectIds)->memberVisible()->fromStaff()
            ->get(['id', 'apparatus_defect_id', 'body', 'created_at'])
            ->each(fn ($message) => $candidates->push(['defect' => (int) $message->apparatus_defect_id, 'text' => (string) $message->body, 'at' => $message->created_at, 'order' => 'm'.str_pad((string) $message->id, 12, '0', STR_PAD_LEFT)]));
        ApparatusDefectEvent::query()->whereIn('apparatus_defect_id', $defectIds)->whereNotNull('member_message')
            ->get(['id', 'apparatus_defect_id', 'member_message', 'created_at'])
            ->each(fn ($event) => $candidates->push(['defect' => (int) $event->apparatus_defect_id, 'text' => (string) $event->member_message, 'at' => $event->created_at, 'order' => 'e'.str_pad((string) $event->id, 12, '0', STR_PAD_LEFT)]));

        $latest = [];
        foreach ($candidates->sortBy([fn ($a, $b) => $a['at'] <=> $b['at'], fn ($a, $b) => strcmp($a['order'], $b['order'])]) as $row) {
            $latest[$row['defect']] = ['text' => $row['text'], 'at' => $row['at']->toIso8601String()];
        }

        return $latest;
    }

    /**
     * SQL IN never matches NULL; scalar names compare as their string form.
     *
     * @param  array<int, mixed>  $names
     * @return array<int, string>
     */
    private function lookupNames(array $names): array
    {
        return array_values(array_map(
            static fn (mixed $name): string => (string) $name,
            array_filter($names, static fn (mixed $name): bool => is_scalar($name)),
        ));
    }
}
