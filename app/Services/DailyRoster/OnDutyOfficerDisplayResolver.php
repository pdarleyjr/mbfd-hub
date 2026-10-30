<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\Captain5\Captain5ActingRequest;
use App\Models\DailyRoster\RosterAssignment;
use App\Models\DailyRoster\RosterSection;
use App\Models\DailyRoster\RosterSnapshot;
use App\Models\Employee;
use App\Services\Captain5\Captain5DutyRoster;
use App\Services\Captain5\OnDutyCaptain5;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Who the 300 and Captain 5 cards show. Display only: authority, narcotics review and notification
 * routing keep reading Captain5DutyRoster, and nothing there may read this class.
 *
 * Precedence: an approved acting member; then one person the current duty day's accepted roster
 * names as working this seat right now; then the bid seat when no usable roster exists for today;
 * anything ambiguous shows nobody, with a reason. Yesterday's roster is never used: at 07:59 the
 * duty day is still yesterday's, and from 08:00 until the first export the card shows today's seat.
 *
 * Intervals are half-open, [starts_at, ends_at), compared as instants, so DST needs no special case.
 */
#[Scoped]
final class OnDutyOfficerDisplayResolver
{
    private const SECTION_ROLES = [
        Captain5ActingRequest::ROLE_CAPTAIN_5 => RosterSection::ROLE_CAPTAIN_5,
        Captain5ActingRequest::ROLE_COMMAND_300 => RosterSection::ROLE_COMMAND_300,
    ];

    /** Absences a positive overtime or exchange row in the seat outranks. Every other absence is held for review. */
    private const OVERRIDABLE_ABSENCES = ['A-Day', 'Day off'];

    private const POSITIVE_CODES = ['+OT', 'OT', '2WXON', '2WXEON'];

    public function __construct(
        private readonly Captain5DutyRoster $roster,
        private readonly CurrentRosterReader $reader,
        private readonly RosterFreshnessResolver $freshness,
        private readonly DutyCalendar $calendar,
    ) {}

    public function resolve(string $role, ?CarbonInterface $at = null): OnDutyOfficerDisplay
    {
        $sectionRole = self::SECTION_ROLES[$role] ?? throw new InvalidArgumentException('Unknown on-duty role.');
        // UTC, because query bindings drop the zone and the database stores UTC.
        $moment = CarbonImmutable::instance($at ?? now())->utc();
        $seat = $this->roster->onDuty($moment, $role);

        if (! (bool) config('daily_roster.card_display_enabled', false)) {
            return OnDutyOfficerDisplay::unchanged($seat);
        }
        if ($seat->source === OnDutyCaptain5::SOURCE_ACTING) {
            return OnDutyOfficerDisplay::acting($seat);
        }

        try {
            return $this->fromRoster($sectionRole, $moment, $seat);
        } catch (Throwable $failure) {
            // A display line must never take the Captain 5 or 300 page down with it. Code only: the
            // original can be a query error, whose bindings carry employee IDs.
            report(new RosterPipelineException('display_failed_'.class_basename($failure)));

            return OnDutyOfficerDisplay::unchanged($seat);
        }
    }

    private function fromRoster(string $sectionRole, CarbonImmutable $moment, OnDutyCaptain5 $seat): OnDutyOfficerDisplay
    {
        $sections = $this->reader->sectionsForRole($moment, $sectionRole);
        $section = $sections[0] ?? null;
        // No section means either no roster at all or a roster without this section; the snapshot tells them apart.
        $snapshot = $section?->section->snapshot ?? $this->reader->current($moment)?->snapshot;

        if ($snapshot === null || ! $this->isForDutyDay($snapshot, $moment)) {
            return OnDutyOfficerDisplay::seat($seat, $this->arrivalFreshness($moment));
        }

        $asOf = $this->freshness->status($moment)->asOf ?? $snapshot->source_generated_at;
        // The roster did arrive, so the page calls it current. It just cannot be trusted to name anyone.
        if (! $this->isOnRotationShift($snapshot, $moment)) {
            return OnDutyOfficerDisplay::seat($seat, OnDutyOfficerDisplay::FRESHNESS_CURRENT, OnDutyOfficerDisplay::REASON_NEEDS_REVIEW, $asOf);
        }
        if ($section === null) {
            return OnDutyOfficerDisplay::seat($seat, OnDutyOfficerDisplay::FRESHNESS_CURRENT, OnDutyOfficerDisplay::REASON_SECTION_MISSING, $asOf);
        }
        if (count($sections) > 1) {
            return OnDutyOfficerDisplay::unknown($seat, OnDutyOfficerDisplay::REASON_CONFLICT, $asOf);
        }

        return $this->fromSection($section, $snapshot, $moment, $seat, $asOf);
    }

    /** Only the pointer's export for this exact duty day. Anything else is stale and ignored. */
    private function isForDutyDay(RosterSnapshot $snapshot, CarbonImmutable $moment): bool
    {
        return $snapshot->duty_date->format('Y-m-d') === $this->calendar->dutyDate($moment)->format('Y-m-d');
    }

    /** False also with the rotation off: nothing can say whose shift this is, so nobody is guessed. */
    private function isOnRotationShift(RosterSnapshot $snapshot, CarbonImmutable $moment): bool
    {
        $shift = $this->calendar->shiftFor($this->calendar->dutyDate($moment)->format('Y-m-d'));

        return $shift !== null && $snapshot->shift_letter === $shift;
    }

    /**
     * No roster for today: "not here yet", or "should be here by now". A held export has not replaced
     * anything, so before the cutoff it reads like any other export still to come.
     */
    private function arrivalFreshness(CarbonImmutable $moment): string
    {
        return match ($this->freshness->status($moment)->state) {
            RosterFreshness::AWAITING, RosterFreshness::NONE => OnDutyOfficerDisplay::FRESHNESS_AWAITING,
            RosterFreshness::NEEDS_REVIEW => $this->freshness->isPastCutoff($moment) ? OnDutyOfficerDisplay::FRESHNESS_DELAYED : OnDutyOfficerDisplay::FRESHNESS_AWAITING,
            default => OnDutyOfficerDisplay::FRESHNESS_DELAYED,
        };
    }

    private function fromSection(RosterSectionView $section, RosterSnapshot $snapshot, CarbonImmutable $moment, OnDutyCaptain5 $seat, ?CarbonImmutable $asOf): OnDutyOfficerDisplay
    {
        $unknown = static fn (string $reason): OnDutyOfficerDisplay => OnDutyOfficerDisplay::unknown($seat, $reason, $asOf);

        // Vacancies and annotations are never candidates; they are simply not looked at.
        $covering = collect($section->assignments)
            ->filter(fn (RosterAssignment $row): bool => $row->kind === RosterAssignment::KIND_EMPLOYEE && $this->covers($row, $moment));
        $working = $covering->where('availability', RosterAssignment::AVAILABILITY_WORKING)->values();
        $unclassified = $covering->where('availability', RosterAssignment::AVAILABILITY_UNKNOWN);

        if ($working->isEmpty()) {
            return $unknown($unclassified->isNotEmpty() ? OnDutyOfficerDisplay::REASON_NEEDS_REVIEW : OnDutyOfficerDisplay::REASON_NO_WORKING_ROW);
        }

        $ids = $working->map(fn (RosterAssignment $row): string => (string) $row->employee_id)->unique()->values();
        $identified = $ids->filter(static fn (string $id): bool => $id !== '')->values();
        if ($identified->count() > 1) {
            return $unknown(OnDutyOfficerDisplay::REASON_CONFLICT);
        }
        // A working row with no ID can never be called the same person as anyone.
        if ($identified->count() !== $ids->count() || $identified->isEmpty()) {
            return $unknown(OnDutyOfficerDisplay::REASON_NEEDS_REVIEW);
        }
        $employeeId = (string) $identified->first();

        $otherUnclassified = $unclassified->contains(fn (RosterAssignment $row): bool => (string) $row->employee_id !== $employeeId);
        if ($otherUnclassified || $this->isAbsentAt($snapshot, $employeeId, $working, $moment)) {
            return $unknown(OnDutyOfficerDisplay::REASON_NEEDS_REVIEW);
        }

        return $this->person($seat, $working->first(), $employeeId, $asOf)
            ?? $unknown(OnDutyOfficerDisplay::REASON_NEEDS_REVIEW);
    }

    /** The same person marked absent at this instant, anywhere in the export, unless the seat row explains it. */
    private function isAbsentAt(RosterSnapshot $snapshot, string $employeeId, Collection $working, CarbonImmutable $moment): bool
    {
        $absences = RosterAssignment::query()
            ->where('roster_snapshot_id', $snapshot->getKey())
            ->where('employee_id', $employeeId)
            ->get()
            ->filter(fn (RosterAssignment $row): bool => $row->availability === RosterAssignment::AVAILABILITY_NOT_WORKING && $this->covers($row, $moment));

        if ($absences->isEmpty()) {
            return false;
        }

        $overridable = $absences->every(static fn (RosterAssignment $row): bool => in_array($row->category, self::OVERRIDABLE_ABSENCES, true));
        $positive = $working->contains(static fn (RosterAssignment $row): bool => in_array($row->base_code, self::POSITIVE_CODES, true));

        return ! ($overridable && $positive);
    }

    /**
     * Exact-string identity. A match shows the Hub's name and rank; anything else shows only the name
     * the source printed. The printed rank belongs to the seat, and a backfill may hold a different one.
     */
    private function person(OnDutyCaptain5 $seat, RosterAssignment $row, string $employeeId, ?CarbonImmutable $asOf): ?OnDutyOfficerDisplay
    {
        $employee = Employee::query()->where('employee_id', $employeeId)->first();
        $linked = $employee instanceof Employee
            && (string) $employee->employee_id === $employeeId
            && $employee->roster_status !== 'departed';

        if ($linked) {
            return OnDutyOfficerDisplay::fromRoster($seat, trim((string) ($employee->display_name ?: $employee->name)), $employee->rank, $employee, $asOf);
        }

        $name = trim((string) ($row->display_name ?: $row->raw_name));
        if ($name === '') {
            return null;
        }

        return OnDutyOfficerDisplay::fromRoster($seat, $name, null, null, $asOf);
    }

    private function covers(RosterAssignment $row, CarbonImmutable $moment): bool
    {
        return $row->starts_at !== null && $row->ends_at !== null
            && $row->starts_at->lessThanOrEqualTo($moment)
            && $row->ends_at->greaterThan($moment);
    }
}
