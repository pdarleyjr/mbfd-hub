<?php

declare(strict_types=1);

namespace App\Services\Defects;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionConcern;
use App\Models\User;
use App\Support\Fleet\DefectNameKey;

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

        $rows = $this->matchOpenIssues((int) $apparatus->id, $rows);
        $open = $this->defects->lockOpenDefectIds(
            (int) $apparatus->id,
            array_values(array_unique(array_filter([...array_column($rows, 'existing'), ...array_column($rows, 'matched')]))),
        );
        $compartments = $newIssues = $confirmations = [];
        foreach ($rows as $entry) {
            /** @var ApparatusInspectionConcern $row */
            $row = $entry['row'];
            if ($row->kind !== ApparatusInspectionConcern::KIND_ISSUE) {
                continue;
            }
            if ($entry['existing'] !== null && in_array($entry['existing'], $open, true)) {
                $confirmations[] = ['row' => $row, 'defect_id' => $entry['existing'], 'matched' => false];

                continue;
            }
            // The same thing another member already reported on this truck joins that issue instead of opening a second one.
            if ($entry['matched'] !== null && in_array($entry['matched'], $open, true)) {
                $confirmations[] = ['row' => $row, 'defect_id' => $entry['matched'], 'matched' => true];

                continue;
            }
            // Closed, or on another truck, or no such issue, and nothing open by that name: the report becomes a new issue and is never lost.
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
                $confirmation['matched'],
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
     * For each issue the member did not tie to an open issue of this truck: the one open issue whose item is
     * this description in the same location (case and spacing aside; no location is the default concerns
     * location). Exact names only, and only when exactly one issue fits: a different location, an ambiguous or
     * a merely similar description stays a new issue, which a reviewer can still merge.
     *
     * Read under the apparatus lock the caller holds, so the set cannot change before lockOpenDefectIds().
     *
     * @param  array<string, array{row: ApparatusInspectionConcern, existing: ?int}>  $rows
     * @return array<string, array{row: ApparatusInspectionConcern, existing: ?int, matched: ?int}>
     */
    private function matchOpenIssues(int $apparatusId, array $rows): array
    {
        $open = null;
        foreach ($rows as $clientId => $entry) {
            $rows[$clientId]['matched'] = null;
            if ($entry['row']->kind !== ApparatusInspectionConcern::KIND_ISSUE) {
                continue;
            }
            $open ??= ApparatusDefect::query()->where('apparatus_id', $apparatusId)->where('resolved', false)
                ->orderBy('id')->get(['id', 'compartment', 'item']);
            if ($entry['existing'] !== null && $open->contains('id', $entry['existing'])) {
                continue;
            }
            $item = $this->itemName($entry['row']);
            // No location is the default concerns location: it joins only another unlocated report, never a located one.
            $location = $this->compartmentName($entry['row']);
            $fits = $open->filter(static fn (ApparatusDefect $defect): bool => DefectNameKey::same($defect->item, $item)
                && DefectNameKey::same($defect->compartment, $location));
            if ($fits->count() === 1) {
                $rows[$clientId]['matched'] = (int) $fits->first()->id;
            }
        }

        return $rows;
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
        // The findings pass joins names without case or spacing, so the link compares them the same way.
        $defects = ApparatusDefect::query()
            ->where('apparatus_id', $apparatusId)->where('resolved', false)
            ->orderBy('id')->get();
        foreach ($rows as $row) {
            $key = 'concern-'.$row->client_id;
            $defect = $defects->first(static fn (ApparatusDefect $candidate): bool => $candidate->item_key === $key)
                ?? $defects->first(fn (ApparatusDefect $candidate): bool => DefectNameKey::same($candidate->compartment, $this->compartmentName($row))
                    && DefectNameKey::same($candidate->item, $this->itemName($row)));
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
