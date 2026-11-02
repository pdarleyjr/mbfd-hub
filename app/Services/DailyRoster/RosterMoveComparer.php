<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterAssignment;
use App\Models\DailyRoster\RosterSection;
use App\Models\DailyRoster\RosterSnapshot;
use Carbon\CarbonImmutable;

/**
 * Who, among the members on duty right now, has a different rest of the shift on the new roster. On duty
 * means the roster they were last shown has them working this moment, so someone due in at 20:00 is not
 * told at 15:00, and someone who finished at 20:00 is not told at 21:00.
 *
 * Only the seat and the times matter. A row whose code the Hub does not recognize still counts as where
 * the member is, so an unknown code cannot look like being taken off the roster, and the Hub learning what a
 * code means is not a move either. Hours already worked are ignored, so correcting them is not news.
 */
final class RosterMoveComparer
{
    private const PLACING = [RosterAssignment::AVAILABILITY_WORKING, RosterAssignment::AVAILABILITY_UNKNOWN];

    /** @return array<string, RosterMove> By employee ID */
    public function compare(RosterSnapshot $from, RosterSnapshot $to, CarbonImmutable $now): array
    {
        $onDuty = [];
        $before = $this->remaining($from, $now, $onDuty);
        $ignored = [];
        $after = $this->remaining($to, $now, $ignored);

        $moves = [];
        foreach (array_keys($onDuty) as $employeeId) {
            $was = $before[$employeeId] ?? [];
            $is = $after[$employeeId] ?? [];
            if (array_keys($was) !== array_keys($is)) {
                $moves[(string) $employeeId] = new RosterMove(array_values($was), array_values($is), $now);
            }
        }

        return $moves === [] ? [] : array_diff_key($moves, $this->printedTheSame($from, $to, array_map('strval', array_keys($moves))));
    }

    /**
     * Members whose rows TeleStaff printed identically on both rosters. Their placement can still differ, because
     * it rests on what the Hub takes a code to mean: a release that teaches it a code moves a row from unknown to
     * not working (MILA and three others on 2026-11-01), and that member has not gone anywhere.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, true>
     */
    private function printedTheSame(RosterSnapshot $from, RosterSnapshot $to, array $employeeIds): array
    {
        $before = $this->printedRows($from, $employeeIds);
        $after = $this->printedRows($to, $employeeIds);

        $same = [];
        foreach ($employeeIds as $employeeId) {
            if (($before[$employeeId] ?? []) === ($after[$employeeId] ?? [])) {
                $same[$employeeId] = true;
            }
        }

        return $same;
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, list<string>>
     */
    private function printedRows(RosterSnapshot $snapshot, array $employeeIds): array
    {
        $units = RosterSection::query()->where('roster_snapshot_id', $snapshot->getKey())->pluck('unit_label', 'id');

        $printed = [];
        foreach (RosterAssignment::query()->where('roster_snapshot_id', $snapshot->getKey())->whereIn('employee_id', $employeeIds)->get() as $row) {
            $printed[(string) $row->employee_id][] = implode('|', [$units->get($row->roster_section_id), $row->seat_rank, $row->raw_code, $row->raw_from, $row->raw_through]);
        }

        return array_map(static function (array $rows): array {
            sort($rows);

            return $rows;
        }, $printed);
    }

    /**
     * What each member still has to work.
     *
     * @param  array<string, true>  $onDuty  Filled with the members working this moment
     * @return array<string, array<string, RosterMovePlacement>>
     */
    private function remaining(RosterSnapshot $snapshot, CarbonImmutable $now, array &$onDuty): array
    {
        $units = RosterSection::query()->where('roster_snapshot_id', $snapshot->getKey())->pluck('unit_label', 'id');
        $rows = RosterAssignment::query()
            ->where('roster_snapshot_id', $snapshot->getKey())
            ->where('kind', RosterAssignment::KIND_EMPLOYEE)
            ->whereIn('availability', self::PLACING)
            ->whereNotNull('employee_id')
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->orderBy('position')
            ->get();

        $placements = [];
        foreach ($rows as $row) {
            if ($row->ends_at->lessThanOrEqualTo($now)) {
                continue;
            }
            $employeeId = (string) $row->employee_id;
            if ($row->starts_at->lessThanOrEqualTo($now)) {
                $onDuty[$employeeId] = true;
            }
            $placement = new RosterMovePlacement(
                trim((string) $units->get($row->roster_section_id)),
                ltrim(trim((string) $row->seat_rank), '.'),
                $row->starts_at->greaterThan($now) ? $row->starts_at : $now,
                $row->ends_at,
            );
            $placements[$employeeId][$placement->key()] = $placement;
        }

        return array_map(self::merged(...), $placements);
    }

    /**
     * Back-to-back rows on one seat are one stretch of work, however TeleStaff splits them: a code change
     * partway through a day (08:00 to 14:00 and 14:00 to 20:00) is not a move.
     *
     * @param  array<string, RosterMovePlacement>  $placements
     * @return array<string, RosterMovePlacement> Keyed and in start order, so two rosters compare directly
     */
    private static function merged(array $placements): array
    {
        usort($placements, static fn (RosterMovePlacement $a, RosterMovePlacement $b): int => [$a->startsAt->getTimestamp(), $a->endsAt->getTimestamp(), $a->seat()] <=> [$b->startsAt->getTimestamp(), $b->endsAt->getTimestamp(), $b->seat()]);

        $stretches = [];
        $latestOnSeat = [];
        foreach ($placements as $placement) {
            $index = $latestOnSeat[$placement->seat()] ?? null;
            if ($index === null || $stretches[$index]->endsAt->lessThan($placement->startsAt)) {
                $latestOnSeat[$placement->seat()] = count($stretches);
                $stretches[] = $placement;

                continue;
            }
            $current = $stretches[$index];
            $stretches[$index] = new RosterMovePlacement($current->unit, $current->rank, $current->startsAt, $current->endsAt->max($placement->endsAt));
        }

        $keyed = [];
        foreach ($stretches as $stretch) {
            $keyed[$stretch->key()] = $stretch;
        }

        return $keyed;
    }
}
