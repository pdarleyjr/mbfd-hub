<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Captain5\Captain5ActingRequest;
use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\User;
use App\Services\Security\Command300Access;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Resolves the Shift Captain 5 or Shift 300 (Division Chief) by seat or approved acting request. This
 * is an authority and routing input, not only a display: it feeds narcotics review
 * (NarcoticsCheckAccess), the Captain 5 audit capacity and notification routing. It never reads the
 * TeleStaff roster, which only ever changes the name on the cards.
 *  1. an administrator-approved acting request of that role showing at the moment;
 *  2. otherwise the verified bid holder of that seat ("Captain 5" / "300") whose bid shift is the
 *     on-duty rotation shift;
 *  3. otherwise unknown (shown as "not set"). Never inferred from rank.
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

    public function onDuty(?DateTimeInterface $moment = null, string $role = Captain5ActingRequest::ROLE_CAPTAIN_5): OnDutyCaptain5
    {
        $moment = CarbonImmutable::instance($moment ?? now());

        return $this->memo[$role.'@'.$moment->format('Y-m-d H:i')] ??= $this->resolve($moment, $role);
    }

    /** The shift's 300 (Division Chief): the approved acting 300 when showing, otherwise the 300 bid holder. */
    public function command300OnDuty(?DateTimeInterface $moment = null): OnDutyCaptain5
    {
        return $this->onDuty($moment, Captain5ActingRequest::ROLE_COMMAND_300);
    }

    public function actingAt(DateTimeInterface $moment, string $role = Captain5ActingRequest::ROLE_CAPTAIN_5): ?Captain5ActingRequest
    {
        return Captain5ActingRequest::query()->showingAt($moment, $role)->with('employee')->orderByDesc('starts_at')->orderByDesc('id')->first();
    }

    /** @return Collection<int, EmployeeBidAssignment> keyed by employee_profile_id */
    public function seatsFor(string $role): Collection
    {
        return $role === Captain5ActingRequest::ROLE_COMMAND_300
            ? $this->seats->holders(Command300Access::COMMAND_UNIT_LABEL)
            : $this->captain5Seats();
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

    private function resolve(CarbonImmutable $moment, string $role): OnDutyCaptain5
    {
        $dutyDate = $this->calendar->dutyDate($moment);
        $shift = $this->calendar->shiftFor($dutyDate);

        $acting = $this->actingAt($moment, $role);
        if ($acting !== null && $acting->employee instanceof Employee) {
            return new OnDutyCaptain5($dutyDate, $shift ?? $acting->shift_letter, OnDutyCaptain5::SOURCE_ACTING, $acting->employee, $acting);
        }

        if ($shift !== null) {
            $seat = $this->seatsFor($role)
                ->first(fn (EmployeeBidAssignment $row): bool => DutyCalendar::letterFromBidShift($row->shift_label) === $shift);
            $employee = $seat !== null ? Employee::query()->find($seat->employee_profile_id) : null;
            if ($employee instanceof Employee && $employee->roster_status !== 'departed') {
                return new OnDutyCaptain5($dutyDate, $shift, OnDutyCaptain5::SOURCE_BID, $employee, null);
            }
        }

        return new OnDutyCaptain5($dutyDate, $shift, OnDutyCaptain5::SOURCE_UNKNOWN, null, null);
    }
}
