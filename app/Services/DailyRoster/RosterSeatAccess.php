<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterSnapshot;
use App\Models\Employee;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Container\Attributes\Scoped;
use Throwable;

/**
 * Whether TeleStaff has a member on the 300 or Captain 5 seat, for the access decisions.
 *
 * Nothing is stored as a permission. Every answer reads the accepted roster for the duty day containing
 * the moment, so a roster posted early changes nothing until 08:00 and the outgoing member keeps the seat
 * until then. Only working rows with an exact employee ID count, and a roster that does not match the
 * rotation shift counts for nothing. A roster that cannot be read closes this route only.
 */
#[Scoped]
final class RosterSeatAccess
{
    /** @var array<string, list<string>> Snapshots are immutable, so these can never go stale. */
    private array $memo = [];

    public function __construct(
        private readonly CurrentRosterReader $reader,
        private readonly OnDutyOfficerDisplayResolver $display,
        private readonly DutyCalendar $calendar,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('daily_roster.seat_access_enabled', false);
    }

    /** Listed as working this seat at any point in the duty day containing the moment. */
    public function holdsSeat(Employee $employee, string $role, ?DateTimeInterface $moment = null): bool
    {
        $employeeId = (string) $employee->employee_id;
        if (! $this->isEnabled() || $employeeId === '') {
            return false;
        }

        try {
            $holders = $this->holders($this->calendar->dutyDate($moment)->format('Y-m-d'), $role);
        } catch (Throwable $failure) {
            // Code only: the original can be a query error, whose bindings carry employee IDs.
            report(new RosterPipelineException('seat_access_failed_'.class_basename($failure)));

            return false;
        }

        return in_array($employeeId, $holders ?? [], true);
    }

    /** The one person the roster has working this seat at this moment, or the bid holder when no roster names anyone. */
    public function isOnSeatNow(Employee $employee, string $role, ?DateTimeInterface $moment = null): bool
    {
        $shown = $this->display->resolve($role, $moment === null ? null : CarbonImmutable::instance($moment));

        return $shown->employee !== null && (int) $shown->employee->getKey() === (int) $employee->getKey();
    }

    /**
     * Employee IDs listed as working this seat on a duty date's accepted roster.
     *
     * @return list<string>|null Null when that date has no accepted roster
     */
    public function holders(string $dutyDate, string $role): ?array
    {
        $snapshotId = RosterActiveVersion::query()->whereDate('duty_date', $dutyDate)->value('roster_snapshot_id');
        if ($snapshotId === null) {
            return null;
        }

        return $this->memo[$snapshotId.'|'.$role] ??= $this->read((int) $snapshotId, $dutyDate, $role);
    }

    /** @return list<string> */
    private function read(int $snapshotId, string $dutyDate, string $role): array
    {
        $expectedShift = $this->calendar->shiftFor($dutyDate);
        $shift = RosterSnapshot::query()->whereKey($snapshotId)->value('shift_letter');

        return $expectedShift !== null && $shift === $expectedShift
            ? $this->reader->workingEmployeeIds($snapshotId, $role)
            : [];
    }
}
