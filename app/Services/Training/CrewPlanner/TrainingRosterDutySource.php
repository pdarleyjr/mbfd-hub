<?php

declare(strict_types=1);

namespace App\Services\Training\CrewPlanner;

use App\Models\DailyRoster\RosterAssignment;
use App\Models\DailyRoster\RosterSnapshot;
use App\Services\DailyRoster\CurrentRosterReader;
use App\Services\DailyRoster\RosterFreshness;
use App\Services\DailyRoster\RosterFreshnessResolver;
use App\Services\DailyRoster\RosterSectionView;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;

/**
 * The Crew Planner's only way to the roster: the emailed TeleStaff export the Hub already accepted and
 * published, projected into the duty shape TeleStaffDutyResolver works with. It parses nothing and keeps
 * nothing, so duty codes and leave reasons never reach a proposal. A date with no accepted export has no
 * staff at all; another day's roster is never borrowed.
 */
final class TrainingRosterDutySource
{
    /** Absences a positive overtime or exchange row outranks, for that row's hours only. */
    private const OVERRIDABLE_ABSENCES = ['A-Day', 'Day off'];

    public function __construct(
        private readonly CurrentRosterReader $reader,
        private readonly RosterFreshnessResolver $freshness,
        private readonly DutyCalendar $calendar,
        private readonly TeleStaffDutyResolver $resolver,
    ) {}

    public function today(): string
    {
        return $this->calendar->dutyDate()->format('Y-m-d');
    }

    /** @return list<string> dates the Hub holds a verified roster for, newest first */
    public function availableDates(int $limit = 7): array
    {
        return $this->reader->availableDutyDates($limit);
    }

    public function forDate(string $dutyDate): TrainingRosterDuty
    {
        $view = $this->reader->forDutyDate($dutyDate);
        $scheduled = $this->calendar->shiftFor($dutyDate);
        if ($view === null) {
            return new TrainingRosterDuty($dutyDate, null, null, $scheduled, $scheduled !== null, null, [], $this->unavailableReason($dutyDate), null);
        }

        return $this->duty($view->snapshot, $view->sections, $scheduled, $view->asOf ?? $view->snapshot->source_generated_at);
    }

    /** One stored version, to compare a plan's earlier roster against the one showing now. */
    public function forSnapshotId(int $snapshotId): ?TrainingRosterDuty
    {
        $snapshot = RosterSnapshot::query()->find($snapshotId);
        if ($snapshot === null) {
            return null;
        }
        $view = $this->reader->forSnapshot($snapshot);

        return $this->duty($snapshot, $view->sections, $this->calendar->shiftFor($snapshot->duty_date->format('Y-m-d')), $snapshot->source_generated_at);
    }

    /** @param list<RosterSectionView> $sections */
    private function duty(RosterSnapshot $snapshot, array $sections, ?string $scheduled, ?CarbonImmutable $asOf): TrainingRosterDuty
    {
        // Mirrors the 300 card: a roster that disagrees with the rotation cannot be trusted to say who is on.
        $mismatch = $scheduled !== null && $scheduled !== $snapshot->shift_letter;
        $rows = [];
        foreach ($sections as $section) {
            foreach ($section->assignments as $row) {
                if ($row->kind === RosterAssignment::KIND_EMPLOYEE && filled($row->employee_id)) {
                    $rows[] = $this->assignment($row, $section);
                }
            }
        }
        $staff = $this->resolver->resolve(['parsed_assignments' => $rows, 'definitive_eligibility_blocked' => $mismatch], []);

        return new TrainingRosterDuty(
            $snapshot->duty_date->format('Y-m-d'), (int) $snapshot->getKey(), (int) $snapshot->version, $snapshot->shift_letter, false, $asOf, $staff, null,
            $mismatch ? 'This roster\'s shift does not match the department calendar, so nobody is treated as confirmed on duty. Check the daily roster.' : null,
        );
    }

    /** @return array<string, mixed> */
    private function assignment(RosterAssignment $row, RosterSectionView $section): array
    {
        return [
            'raw_employee_id' => (string) $row->employee_id,
            'raw_name' => (string) ($row->display_name ?: $row->raw_name),
            'code_class' => match ($row->availability) {
                RosterAssignment::AVAILABILITY_WORKING => 'WORKING',
                RosterAssignment::AVAILABILITY_NOT_WORKING => 'NOT_WORKING',
                RosterAssignment::AVAILABILITY_ANNOTATION => 'ANNOTATION',
                default => 'UNKNOWN',
            },
            'approval_state' => 'VERIFIED_EFFECTIVE', // the import pipeline accepted this export
            'normalized_code' => in_array($row->category, self::OVERRIDABLE_ABSENCES, true) ? 'ADONIS DAY' : $row->base_code,
            'reported_station' => $section->section->parent_label,
            'reported_apparatus' => $section->section->unit_label,
            'seat_rank' => $row->seat_rank,
            'start_at' => $row->starts_at?->toIso8601String(),
            'end_at' => $row->ends_at?->toIso8601String(),
        ];
    }

    private function unavailableReason(string $dutyDate): string
    {
        $today = $this->today();
        if ($dutyDate > $today) {
            return 'TeleStaff has not published a roster for this date yet.';
        }
        if ($dutyDate < $today) {
            return 'The Hub has no verified roster on file for this date.';
        }

        return match ($this->freshness->status()->state) {
            RosterFreshness::DELAYED => 'Today\'s roster is late. The Hub has not received it yet.',
            RosterFreshness::NEEDS_REVIEW => 'Today\'s roster arrived but is held for review.',
            default => 'Today\'s roster has not arrived yet.',
        };
    }
}
