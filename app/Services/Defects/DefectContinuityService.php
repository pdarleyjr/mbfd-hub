<?php

declare(strict_types=1);

namespace App\Services\Defects;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusInspection;
use App\Services\InspectionPaperFields;

/**
 * One persistent issue history per physical vehicle item
 * (docs/daily-inspection-continuity.md section 4.1).
 */
final class DefectContinuityService
{
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
        $new = $confirmed = $corrected = [];
        $paperFields = app(InspectionPaperFields::class);
        $openDefects = $this->lockOpenDefects((int) $physicalApparatus->id, $observations, $definitions);
        foreach ($observations as $compartment) {
            foreach ($compartment['items'] ?? [] as $item) {
                $identity = $definitions[$compartment['id']][$item['id'] ?? ''] ?? null;
                $compartmentNames = $this->lookupNames($identity['compartment_names'] ?? [$compartment['name']]);
                $itemNames = $this->lookupNames($identity['item_names'] ?? [$item['name']]);
                $existing = null;
                foreach ($openDefects as $candidate) {
                    if (! $candidate->resolved
                        && $candidate->compartment !== null && in_array((string) $candidate->compartment, $compartmentNames, true)
                        && $candidate->item !== null && in_array((string) $candidate->item, $itemNames, true)
                        && ($identity === null || count($paperFields->matchingEquipment($definitions, $candidate->compartment, $candidate->item)) === 1)) {
                        $existing = $candidate;
                        break;
                    }
                }
                $isIssue = in_array($item['status'], ['Missing', 'Damaged'], true);
                if (! $isIssue && $existing === null) {
                    continue;
                }
                $photoPath = $item['photo_path'] ?? null;
                foreach ($evidence['defects'] ?? [] as $finding) {
                    if ($finding['compartment'] === $compartment['name'] && $finding['item'] === $item['name']) {
                        $photoPath = $finding['photo_path'] ?? null;
                        break;
                    }
                }
                $defect = $existing ?? ApparatusDefect::recordDefect(
                    $physicalApparatus->id, $compartment['name'], $item['name'], $item['status'],
                    $item['notes'] ?? null, $photoPath, $inspection->id,
                );
                if ($existing && $isIssue && $defect->issue_type !== strtolower($item['status'])) {
                    $defect->update(['issue_type' => strtolower($item['status'])]);
                }
                // Later items must see this row exactly as a fresh per-item
                // query would, including a defect created a moment ago.
                $openDefects[$defect->id] = $defect;
                ksort($openDefects);
                ApparatusDefectObservation::query()->create([
                    'apparatus_defect_id' => $defect->id,
                    'apparatus_inspection_id' => $inspection->id,
                    'actor_user_id' => $inspection->actor_user_id,
                    'observation' => $isIssue ? ($existing ? 'confirmed_again' : 'first_reported') : 'appears_corrected',
                    'reported_status' => $item['status'],
                    'notes' => $item['notes'] ?? null,
                    'photo_path' => $photoPath,
                ]);
                if (! $isIssue) {
                    $corrected[] = (int) $defect->id;
                } elseif ($existing) {
                    $confirmed[] = (int) $defect->id;
                } else {
                    $new[] = (int) $defect->id;
                }
                // A finding is never itself permission to take a unit OOS or
                // return it to service. Its authorized disposition remains a
                // later, explicit administrative decision; an unclassified
                // finding alone is not a processing exception.
            }
        }

        return new FindingsResult($new, $confirmed, $corrected, []);
    }

    /**
     * Load and lock, in one query, every unresolved defect any submitted item
     * could match. The apparatus row is already locked, so this replaces one
     * locking query per checklist item (often 100+) while preserving which
     * rows are considered and their id order.
     *
     * @param  array<int, array<string, mixed>>  $observations
     * @param  array<string, array<string, array<string, mixed>>>  $definitions
     * @return array<int, ApparatusDefect>
     */
    private function lockOpenDefects(int $apparatusId, array $observations, array $definitions): array
    {
        $compartmentNames = [];
        $itemNames = [];
        foreach ($observations as $compartment) {
            foreach ($compartment['items'] ?? [] as $item) {
                $identity = $definitions[$compartment['id']][$item['id'] ?? ''] ?? null;
                array_push($compartmentNames, ...$this->lookupNames($identity['compartment_names'] ?? [$compartment['name']]));
                array_push($itemNames, ...$this->lookupNames($identity['item_names'] ?? [$item['name']]));
            }
        }
        if ($compartmentNames === [] || $itemNames === []) {
            return [];
        }

        return ApparatusDefect::query()
            ->where('apparatus_id', $apparatusId)
            ->whereIn('compartment', array_values(array_unique($compartmentNames)))
            ->whereIn('item', array_values(array_unique($itemNames)))
            ->where('resolved', false)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();
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
