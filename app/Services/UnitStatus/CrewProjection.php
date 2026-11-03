<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use App\Models\DailyRoster\RosterAssignment;
use App\Services\DailyRoster\CurrentRosterReader;
use App\Services\DailyRoster\RosterFreshness;
use App\Services\DailyRoster\RosterFreshnessResolver;
use App\Services\DailyRoster\RosterSectionView;
use App\Support\UnitStatus\ShortName;
use App\Support\UnitStatus\UnitRegistry;
use App\Support\UnitStatus\UnitStatusTime;
use Carbon\CarbonImmutable;

/**
 * The crew on each unit, from the published TeleStaff roster only (CurrentRosterReader and RosterFreshnessResolver).
 * No workbook or email is read and yesterday's roster is never a fallback. Employee ids are used to de-duplicate
 * inside this class and are never returned.
 */
final class CrewProjection
{
    public function __construct(
        private readonly CurrentRosterReader $reader,
        private readonly RosterFreshnessResolver $freshness,
        private readonly UnitRegistry $registry,
    ) {}

    public function at(CarbonImmutable $now): CrewSnapshot
    {
        $status = $this->freshness->status($now);
        $view = $status->snapshotId !== null ? $this->reader->current($now) : null;
        $usable = $view !== null && $view->snapshot->id === $status->snapshotId;
        $sections = $usable ? $this->sectionsByUnit($view->sections) : [];

        $byUnit = [];
        foreach ($this->registry->units() as $unit) {
            $byUnit[$unit->code] = isset($sections[$unit->code]) ? $this->crewFor($sections[$unit->code], $now) : UnitCrew::notListed();
        }

        return new CrewSnapshot(
            $status->state,
            $status->dutyDate,
            $usable ? ($view->snapshot->shift_letter ?: null) : null,
            $status->asOf,
            $usable && $status->state !== RosterFreshness::NONE && $status->state !== RosterFreshness::AWAITING,
            $byUnit,
        );
    }

    /**
     * @param  list<RosterSectionView>  $sections
     * @return array<string, list<RosterAssignment>> unit code => assignments of every section carrying that label
     */
    private function sectionsByUnit(array $sections): array
    {
        $byUnit = [];
        foreach ($sections as $section) {
            $unit = $this->registry->matchRosterLabel($section->section->unit_label);
            if ($unit !== null) {
                $byUnit[$unit->code] = [...($byUnit[$unit->code] ?? []), ...$section->assignments];
            }
        }

        return $byUnit;
    }

    /** @param  list<RosterAssignment>  $assignments */
    private function crewFor(array $assignments, CarbonImmutable $now): UnitCrew
    {
        $onTruck = [];
        $later = [];
        $vacancies = 0;
        foreach ($assignments as $assignment) {
            if ($assignment->kind === RosterAssignment::KIND_VACANCY_OPEN) {
                $vacancies += $this->covers($assignment, $now) ? 1 : 0;

                continue;
            }
            if ($assignment->kind !== RosterAssignment::KIND_EMPLOYEE || $assignment->availability !== RosterAssignment::AVAILABILITY_WORKING) {
                continue;
            }
            $name = trim((string) $assignment->display_name);
            if ($name === '') {
                continue;
            }
            $person = $assignment->employee_id !== null && $assignment->employee_id !== '' ? 'id:'.$assignment->employee_id : 'name:'.mb_strtolower($name);
            $rank = $this->rank($assignment);
            $short = ShortName::from($rank, (string) $assignment->raw_name, $name);
            if ($this->covers($assignment, $now)) {
                $onTruck[$person] ??= ['rank' => $rank, 'name' => $name, 'short' => $short];
            } elseif ($assignment->starts_at !== null && $assignment->starts_at->greaterThan($now)) {
                $later[$person.'|'.$assignment->starts_at->getTimestamp()] ??= [
                    'rank' => $rank,
                    'name' => $name,
                    'short' => $short,
                    'from' => UnitStatusTime::clock($assignment->starts_at),
                    'through' => UnitStatusTime::clock($assignment->ends_at),
                ];
            }
        }

        return new UnitCrew(true, array_values($onTruck), array_values($later), $vacancies);
    }

    /** A null bound is open: starts_at <= now < ends_at. */
    private function covers(RosterAssignment $assignment, CarbonImmutable $now): bool
    {
        return ($assignment->starts_at === null || $assignment->starts_at->lessThanOrEqualTo($now))
            && ($assignment->ends_at === null || $assignment->ends_at->greaterThan($now));
    }

    private function rank(RosterAssignment $assignment): ?string
    {
        $rank = trim((string) ($assignment->rank_label ?? $assignment->seat_rank ?? ''));

        return $rank === '' ? null : $rank;
    }
}
