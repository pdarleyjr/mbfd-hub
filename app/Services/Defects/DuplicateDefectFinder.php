<?php

declare(strict_types=1);

namespace App\Services\Defects;

use App\Models\ApparatusDefect;
use App\Support\Fleet\DefectNameKey;

/**
 * Open reports on one vehicle that are the same issue, for defects:consolidate-duplicates.
 *
 * Two open defects are the same issue when they share (exact, after DefectNameKey) the stable
 * compartment_key + item_key, or the compartment and item names. A member concern sent without a location
 * is in the default "Member-reported concerns" compartment, so it joins only another unlocated concern.
 *
 * Never joined: two defects whose checklist identities (DefectConsolidationService::identityKey) are both set
 * and differ, such as two "Hose" lines in one compartment. That holds for the whole group, not just a pair:
 * a defect joins a group only when it conflicts with none of its members, so no chain of matches can bridge
 * two different checklist items.
 *
 * The same item text in different locations is listed as a possible duplicate and never merged.
 */
final class DuplicateDefectFinder
{
    /**
     * @return array{
     *     groups: list<array{survivor: ApparatusDefect, duplicates: list<ApparatusDefect>}>,
     *     possible: list<list<ApparatusDefect>>
     * }  groups: oldest report first; possible: same item text in different locations, never merged
     */
    public function find(?int $apparatusId = null): array
    {
        $open = ApparatusDefect::query()
            ->where('resolved', false)
            ->when($apparatusId !== null, static fn ($query) => $query->where('apparatus_id', $apparatusId))
            ->orderBy('apparatus_id')->orderBy('id')
            ->get();

        $groups = $possible = [];
        foreach ($open->groupBy('apparatus_id') as $defects) {
            $sorted = $this->oldestFirst($defects->values()->all());
            $found = $this->groupsOnOneApparatus($sorted);
            array_push($groups, ...$found);
            array_push($possible, ...$this->possibleOnOneApparatus($sorted, $found));
        }

        return ['groups' => $groups, 'possible' => $possible];
    }

    /** @return list<array{survivor: ApparatusDefect, duplicates: list<ApparatusDefect>}> */
    public function groups(?int $apparatusId = null): array
    {
        return $this->find($apparatusId)['groups'];
    }

    /** One pair: same stable key, or same compartment and item names, and never two different checklist items. */
    public static function same(ApparatusDefect $a, ApparatusDefect $b): bool
    {
        if (DefectConsolidationService::conflicting($a, $b)) {
            return false;
        }
        if ($a->item_key !== null && $a->item_key === $b->item_key && $a->compartment_key === $b->compartment_key) {
            return true;
        }

        return DefectNameKey::normalize($a->item) !== ''
            && DefectNameKey::same($a->item, $b->item)
            && DefectNameKey::normalize($a->compartment) === DefectNameKey::normalize($b->compartment);
    }

    /**
     * Oldest first, each defect joins the first group it matches a member of and conflicts with no member of.
     *
     * @param  list<ApparatusDefect>  $defects
     * @return list<array{survivor: ApparatusDefect, duplicates: list<ApparatusDefect>}>
     */
    private function groupsOnOneApparatus(array $defects): array
    {
        /** @var list<list<ApparatusDefect>> $sets */
        $sets = [];
        foreach ($defects as $defect) {
            $placed = false;
            foreach ($sets as $index => $members) {
                $matches = array_filter($members, static fn (ApparatusDefect $member): bool => self::same($member, $defect));
                $conflicts = array_filter($members, static fn (ApparatusDefect $member): bool => DefectConsolidationService::conflicting($member, $defect));
                if ($matches !== [] && $conflicts === []) {
                    $sets[$index][] = $defect;
                    $placed = true;
                    break;
                }
            }
            if (! $placed) {
                $sets[] = [$defect];
            }
        }

        $groups = [];
        foreach ($sets as $members) {
            if (count($members) > 1) {
                $groups[] = ['survivor' => $members[0], 'duplicates' => array_slice($members, 1)];
            }
        }

        return $groups;
    }

    /**
     * The same item text in more than one place that did not become one group: for a person to look at.
     *
     * @param  list<ApparatusDefect>  $defects
     * @param  list<array{survivor: ApparatusDefect, duplicates: list<ApparatusDefect>}>  $groups
     * @return list<list<ApparatusDefect>>
     */
    private function possibleOnOneApparatus(array $defects, array $groups): array
    {
        $groupOf = [];
        foreach ($groups as $group) {
            foreach ([$group['survivor'], ...$group['duplicates']] as $member) {
                $groupOf[(int) $member->id] = (int) $group['survivor']->id;
            }
        }
        $byText = [];
        foreach ($defects as $defect) {
            $text = DefectNameKey::normalize($defect->item);
            if ($text !== '') {
                $byText[$text][] = $defect;
            }
        }
        $possible = [];
        foreach ($byText as $sameText) {
            $roots = array_unique(array_map(static fn (ApparatusDefect $defect): int => $groupOf[(int) $defect->id] ?? (int) $defect->id, $sameText));
            if (count($roots) > 1) {
                $possible[] = $sameText;
            }
        }

        return $possible;
    }

    /**
     * @param  list<ApparatusDefect>  $defects
     * @return list<ApparatusDefect>
     */
    private function oldestFirst(array $defects): array
    {
        usort($defects, static fn (ApparatusDefect $a, ApparatusDefect $b): int => [($a->first_reported_at ?? $a->created_at)?->getTimestamp(), (int) $a->id]
            <=> [($b->first_reported_at ?? $b->created_at)?->getTimestamp(), (int) $b->id]);

        return $defects;
    }
}
