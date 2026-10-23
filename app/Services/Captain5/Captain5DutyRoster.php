<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Captain5\Captain5DutyAssignment;
use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\User;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Resolves the on-duty Captain 5 from authoritative data only:
 *  1. an active controlled acting assignment covering the moment;
 *  2. otherwise the verified Captain 5 bid holder whose bid shift is the on-duty rotation shift;
 *  3. otherwise unknown (callers fall back to unit-level routing). Never inferred from rank.
 */
final class Captain5DutyRoster
{
    /** @var array<string, OnDutyCaptain5> */
    private array $memo = [];

    public function __construct(
        private readonly DutyCalendar $calendar,
        private readonly VerifiedBidSeats $seats,
    ) {}

    public function calendar(): DutyCalendar
    {
        return $this->calendar;
    }

    public function onDuty(?DateTimeInterface $moment = null): OnDutyCaptain5
    {
        $moment = CarbonImmutable::instance($moment ?? now());

        return $this->memo[$moment->format('Y-m-d H:i')] ??= $this->resolve($moment);
    }

    public function actingAt(DateTimeInterface $moment): ?Captain5DutyAssignment
    {
        return Captain5DutyAssignment::query()->covering($moment)->with('employee')->orderByDesc('id')->first();
    }

    /** @return Collection<int, EmployeeBidAssignment> keyed by employee_profile_id */
    public function captain5Seats(): Collection
    {
        return $this->seats->holders((string) config('captain5.bid_unit_labels.captain5'));
    }

    /** @return Collection<int, EmployeeBidAssignment> keyed by employee_profile_id */
    public function emsCaptainSeats(): Collection
    {
        return $this->seats->holders((string) config('captain5.bid_unit_labels.ems_captain'));
    }

    /**
     * Usable accounts linked to the given personnel profiles.
     *
     * @param  iterable<int|string>  $employeeProfileIds
     * @return Collection<int, User>
     */
    public function usersFor(iterable $employeeProfileIds): Collection
    {
        $ids = collect($employeeProfileIds)->map(fn ($id): int => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            return collect();
        }

        return User::query()->whereIn('employee_profile_id', $ids)->orderBy('id')->get()
            ->filter(fn (User $user): bool => $user->isAuthenticationAllowed())
            ->values();
    }

    /**
     * Immediate-notification recipients for "the on-duty Captain 5": that person when resolved,
     * otherwise every Captain 5 seat holder (unit-level fallback, three people at most).
     *
     * @return Collection<int, User>
     */
    public function notificationRecipients(?DateTimeInterface $moment = null): Collection
    {
        $onDuty = $this->onDuty($moment);
        if ($onDuty->isResolved()) {
            $users = $this->usersFor([(int) $onDuty->employee?->getKey()]);
            if ($users->isNotEmpty()) {
                return $users;
            }
        }

        return $this->usersFor($this->captain5Seats()->keys());
    }

    /** @return Collection<int, User> */
    public function emsCaptainUsers(): Collection
    {
        return $this->usersFor($this->emsCaptainSeats()->keys());
    }

    public function flush(): void
    {
        $this->memo = [];
        $this->seats->flush();
    }

    private function resolve(CarbonImmutable $moment): OnDutyCaptain5
    {
        $dutyDate = $this->calendar->dutyDate($moment);
        $shift = $this->calendar->shiftFor($dutyDate);

        $acting = $this->actingAt($moment);
        if ($acting !== null && $acting->employee instanceof Employee) {
            return new OnDutyCaptain5($dutyDate, $acting->shift_letter ?? $shift, OnDutyCaptain5::SOURCE_ACTING, $acting->employee, $acting);
        }

        if ($shift !== null) {
            $seat = $this->captain5Seats()
                ->first(fn (EmployeeBidAssignment $row): bool => DutyCalendar::letterFromBidShift($row->shift_label) === $shift);
            $employee = $seat !== null ? Employee::query()->find($seat->employee_profile_id) : null;
            if ($employee instanceof Employee && $employee->roster_status !== 'departed') {
                return new OnDutyCaptain5($dutyDate, $shift, OnDutyCaptain5::SOURCE_BID, $employee, null);
            }
        }

        return new OnDutyCaptain5($dutyDate, $shift, OnDutyCaptain5::SOURCE_UNKNOWN, null, null);
    }
}
