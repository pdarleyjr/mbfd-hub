<?php

declare(strict_types=1);

namespace App\Services\Defects;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionConcern;
use App\Models\User;

/**
 * Concerns, damage and notes a member sends with a Daily Checkout (docs/apparatus-issue-history.md section 3).
 *
 * Runs inside the submission transaction with the apparatus locked, around the checklist findings pass:
 * begin() stores the rows and prepares the new issues, finish() adds to issues already on record and links
 * every issue row to its defect. A note is only its row.
 */
final class InspectionConcernRecorder
{
    public const COMPARTMENT_ID = 'member-concerns';

    public const DEFAULT_LOCATION = 'Member-reported concerns';

    private const ITEM_NAME_LENGTH = 120;

    public function __construct(private readonly DefectContinuityService $defects) {}

    /** @param  list<array<string, mixed>>  $submitted  the validated concerns, photos already stored as photo_path */
    public function begin(ApparatusInspection $inspection, Apparatus $apparatus, array $submitted): ConcernBatch
    {
        if ($submitted === []) {
            return new ConcernBatch;
        }
        $reporter = $this->reporterName($inspection);
        $rows = [];
        foreach ($submitted as $concern) {
            $clientId = strtolower((string) $concern['client_id']);
            if (isset($rows[$clientId])) {
                continue;
            }
            $rows[$clientId] = [
                'row' => ApparatusInspectionConcern::query()->create([
                    'apparatus_id' => $apparatus->id,
                    'apparatus_inspection_id' => $inspection->id,
                    'reporter_user_id' => $inspection->actor_user_id,
                    'reporter_name' => $reporter,
                    'client_id' => $clientId,
                    'kind' => $concern['kind'],
                    'description' => trim((string) $concern['description']),
                    'location' => $this->location($concern['location'] ?? null),
                    'photo_path' => $concern['photo_path'] ?? null,
                ]),
                'existing' => $concern['kind'] === ApparatusInspectionConcern::KIND_ISSUE && isset($concern['existing_issue_id'])
                    ? (int) $concern['existing_issue_id'] : null,
            ];
        }

        $open = $this->defects->lockOpenDefectIds(
            (int) $apparatus->id,
            array_values(array_filter(array_column($rows, 'existing'))),
        );
        $compartments = $newIssues = $confirmations = [];
        foreach ($rows as $entry) {
            /** @var ApparatusInspectionConcern $row */
            $row = $entry['row'];
            if ($row->kind !== ApparatusInspectionConcern::KIND_ISSUE) {
                continue;
            }
            // Closed, or on another truck, or no such issue: the report becomes a new issue and is never lost.
            if ($entry['existing'] !== null && in_array($entry['existing'], $open, true)) {
                $confirmations[] = ['row' => $row, 'defect_id' => $entry['existing']];

                continue;
            }
            $newIssues[] = $row;
            $compartments[] = $this->pseudoCompartment($row);
        }
        // Defects were locked in ascending order, so they are confirmed in that order too.
        usort($confirmations, static fn (array $a, array $b): int => $a['defect_id'] <=> $b['defect_id']);

        return new ConcernBatch($compartments, $newIssues, $confirmations);
    }

    public function finish(ConcernBatch $batch, ApparatusInspection $inspection, Apparatus $apparatus, FindingsResult $findings): FindingsResult
    {
        $confirmed = [];
        foreach ($batch->confirmations as $confirmation) {
            $defect = $this->defects->recordConcernConfirmation(
                $inspection,
                $confirmation['defect_id'],
                $confirmation['row']->description,
                $confirmation['row']->photo_path,
            );
            $confirmation['row']->linkToDefect($defect);
            $confirmed[] = (int) $defect->id;
        }
        $this->linkNewIssues($batch->newIssues, (int) $apparatus->id);

        return new FindingsResult(
            $findings->newDefectIds,
            array_values(array_unique([...$findings->confirmedDefectIds, ...$confirmed])),
            $findings->appearsCorrectedDefectIds,
            $findings->significantNewOrEscalatedDefectIds,
        );
    }

    /**
     * A concern has no checklist item. It is reported as damage to an item named by its description, in a
     * compartment named by its location, so it flows through the same findings pass as a checklist finding.
     *
     * @return array<string, mixed>
     */
    private function pseudoCompartment(ApparatusInspectionConcern $row): array
    {
        return [
            'id' => self::COMPARTMENT_ID,
            'name' => $this->compartmentName($row),
            'items' => [[
                'id' => 'concern-'.$row->client_id,
                'name' => $this->itemName($row),
                'status' => 'Damaged',
                'notes' => $row->description,
                'photo_path' => $row->photo_path,
            ]],
        ];
    }

    /**
     * The defect each new issue became, or joined: by its own key, else by the names two concerns share.
     *
     * @param  list<ApparatusInspectionConcern>  $rows
     */
    private function linkNewIssues(array $rows, int $apparatusId): void
    {
        if ($rows === []) {
            return;
        }
        $keys = array_map(static fn (ApparatusInspectionConcern $row): string => 'concern-'.$row->client_id, $rows);
        $defects = ApparatusDefect::query()
            ->where('apparatus_id', $apparatusId)->where('resolved', false)
            ->where(function ($query) use ($keys, $rows): void {
                $query->whereIn('item_key', $keys)
                    ->orWhere(function ($names) use ($rows): void {
                        $names->whereIn('compartment', array_values(array_unique(array_map($this->compartmentName(...), $rows))))
                            ->whereIn('item', array_values(array_unique(array_map($this->itemName(...), $rows))));
                    });
            })
            ->orderBy('id')->get();
        foreach ($rows as $row) {
            $key = 'concern-'.$row->client_id;
            $defect = $defects->first(static fn (ApparatusDefect $candidate): bool => $candidate->item_key === $key)
                ?? $defects->first(fn (ApparatusDefect $candidate): bool => $candidate->compartment === $this->compartmentName($row)
                    && $candidate->item === $this->itemName($row));
            if ($defect instanceof ApparatusDefect) {
                $row->linkToDefect($defect);
            }
        }
    }

    private function compartmentName(ApparatusInspectionConcern $row): string
    {
        return $row->location ?? self::DEFAULT_LOCATION;
    }

    private function itemName(ApparatusInspectionConcern $row): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $row->description)), 0, self::ITEM_NAME_LENGTH);
    }

    private function location(mixed $location): ?string
    {
        $location = is_string($location) ? trim($location) : '';

        return $location === '' ? null : $location;
    }

    /** The name members see for this reporter, snapshotted: display name, then account name, then the name on the checkout. */
    private function reporterName(ApparatusInspection $inspection): ?string
    {
        $user = $inspection->actor_user_id !== null ? User::query()->find($inspection->actor_user_id, ['id', 'name', 'display_name']) : null;
        $name = $user instanceof User ? trim((string) ($user->display_name ?: $user->name)) : '';
        $name = $name !== '' ? $name : trim((string) $inspection->operator_name);

        return $name === '' ? null : mb_substr($name, 0, 255);
    }
}
