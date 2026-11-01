<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterAssignment;
use App\Models\Employee;
use App\Services\Personnel\PersonnelSource;
use App\Support\Captain5\DutyCalendar;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;

/**
 * What the card behind a name on the roster shows. It answers only for someone on the roster being viewed,
 * so it cannot be walked to read the personnel list. Phone, account status and login details stay out; cell
 * text is untrusted and escaped by the view.
 */
final class RosterMemberCard
{
    public function __construct(
        private readonly CurrentRosterReader $reader,
        private readonly DutyCalendar $calendar,
        private readonly RosterStatusPresenter $statuses,
        private readonly PersonnelSource $personnel,
    ) {}

    /** @return array<string, mixed>|null Null when this ID is not on that roster */
    public function build(string $employeeId, ?string $requestedDate): ?array
    {
        $view = $this->rosterFor($requestedDate);
        if ($view === null) {
            return null;
        }

        $rows = [];
        $certifications = [];
        $rosterName = '';
        foreach ($view->sections as $sectionView) {
            foreach ($sectionView->assignments as $assignment) {
                if ($assignment->kind !== RosterAssignment::KIND_EMPLOYEE || (string) $assignment->employee_id !== $employeeId) {
                    continue;
                }
                $rosterName = $rosterName !== '' ? $rosterName : trim((string) ($assignment->display_name ?: $assignment->raw_name));
                $certifications = [...$certifications, ...array_map(static fn (mixed $value): string => trim((string) $value), (array) $assignment->certifications)];
                $rows[] = [
                    'unit' => trim((string) $sectionView->section->unit_label),
                    'station' => trim((string) $sectionView->section->parent_label),
                    'seat' => ltrim(trim((string) $assignment->seat_rank), '.'),
                    'pill' => $this->statuses->present($assignment),
                    'from' => (string) $assignment->raw_from,
                    'through' => (string) $assignment->raw_through,
                ];
            }
        }
        if ($rows === []) {
            return null;
        }

        $employee = Employee::query()->where('employee_id', $employeeId)->first();
        $linked = $employee instanceof Employee && (string) $employee->employee_id === $employeeId && $employee->roster_status !== 'departed';
        $profileName = $linked ? trim((string) ($employee->display_name ?: $employee->name)) : '';

        return [
            'name' => $profileName !== '' ? $profileName : $rosterName,
            'rank' => $linked ? trim((string) $employee->rank) : '',
            'employeeId' => $employeeId,
            'rosterDate' => CarbonImmutable::createFromFormat('!Y-m-d', $view->snapshot->duty_date->format('Y-m-d'), HubTimezone::NAME)->format('l, F j'),
            'rows' => $rows,
            'certifications' => array_values(array_unique(array_filter($certifications, static fn (string $value): bool => $value !== ''))),
            'hasProfile' => $linked,
            'station' => $linked ? trim((string) $employee->station) : '',
            'email' => $linked ? trim((string) $employee->city_email) : '',
            'bid' => $linked ? $this->bid($employee) : [],
        ];
    }

    /** The same choice of roster the page makes, so the card can never describe a different one. */
    private function rosterFor(?string $requestedDate): ?RosterView
    {
        $now = CarbonImmutable::now();
        if ($requestedDate !== null && in_array($requestedDate, $this->reader->availableDutyDates(), true)
            && $requestedDate !== $this->calendar->dutyDate($now)->format('Y-m-d')) {
            return $this->reader->forDutyDate($requestedDate);
        }

        return $this->reader->current($now) ?? $this->reader->latestBefore($now);
    }

    /** @return array<string, string> Label => value, only what the current term holds */
    private function bid(Employee $employee): array
    {
        $bid = $this->personnel->currentBidFor($employee);
        if ($bid === null) {
            return [];
        }

        return array_filter(array_map(static fn (mixed $value): string => trim((string) $value), [
            'Term' => $bid->term_label,
            'Shift' => $bid->shift_label,
            'Station' => $bid->station_label,
            'Unit' => $bid->unit_label,
            'Position' => $bid->position_label,
            'A-Day' => $bid->a_day_label,
            'Assignment' => $bid->assignment_type,
        ]), static fn (string $value): bool => $value !== '');
    }
}
