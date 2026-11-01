<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterSection;
use App\Models\Employee;
use App\Models\User;
use App\Services\Security\Captain5Access;
use App\Services\Security\Captain5AccessDecision;
use App\Services\Security\Command300Access;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;

/**
 * Keeps the record of who the roster puts on the 300 and Captain 5 seats, and tells administrators about two
 * things: someone who cannot sign in, since no permission can fix an account that was never activated, and
 * someone who gets the page from the roster alone. It looks at the shift on duty and the next one, which gives
 * time to act. Events carry Hub user IDs only, never an employee ID or a name.
 */
final class RosterSeatWatch
{
    private const SEATS = [
        RosterSection::ROLE_CAPTAIN_5 => 'Captain 5',
        RosterSection::ROLE_COMMAND_300 => '300',
    ];

    public function __construct(
        private readonly RosterSeatAccess $seats,
        private readonly DutyCalendar $calendar,
        private readonly RosterStateRecorder $recorder,
        private readonly RosterAlerts $alerts,
        private readonly Command300Access $command300,
        private readonly Captain5Access $captain5,
    ) {}

    /** @return int Seats whose holders changed */
    public function check(CarbonImmutable $now): int
    {
        if (! $this->seats->isEnabled()) {
            return 0;
        }

        $today = $this->calendar->dutyDate($now);
        $changed = 0;
        foreach ([$today->format('Y-m-d'), $today->addDay()->format('Y-m-d')] as $dutyDate) {
            foreach (self::SEATS as $role => $label) {
                $changed += $this->checkSeat($dutyDate, $role, $label) ? 1 : 0;
            }
        }

        return $changed;
    }

    private function checkSeat(string $dutyDate, string $role, string $label): bool
    {
        $holders = $this->seats->holders($dutyDate, $role);
        if ($holders === null) {
            return false;
        }

        $employees = Employee::query()->whereIn('employee_id', $holders)->get()
            ->filter(static fn (Employee $employee): bool => in_array((string) $employee->employee_id, $holders, true) && $employee->roster_status !== 'departed');
        $users = User::query()->whereIn('employee_profile_id', $employees->modelKeys())->get()->keyBy('employee_profile_id');

        $userIds = $users->map(static fn (User $user): int => (int) $user->getKey())->sort()->values()->all();
        $context = [
            'user_ids' => $userIds,
            'no_account' => $employees->reject(static fn (Employee $employee): bool => $users->has($employee->getKey()))
                ->map(static fn (Employee $employee): int => (int) $employee->getKey())->sort()->values()->all(),
            'unmatched' => count($holders) - $employees->count(),
        ];

        $last = RosterEvent::query()
            ->where('action', RosterStateRecorder::ACTION_SEAT_HOLDERS_CHANGED)
            ->whereDate('duty_date', $dutyDate)
            ->where('reason', $role)
            ->latest('id')
            ->first()->context ?? ['user_ids' => [], 'no_account' => [], 'unmatched' => 0];
        if ($last == $context) {
            return false;
        }

        $this->recorder->appendEvent(null, RosterStateRecorder::ACTION_SEAT_HOLDERS_CHANGED, reason: $role, context: $context, dutyDate: $dutyDate);

        foreach ($employees as $employee) {
            $user = $users->get($employee->getKey());
            $name = trim((string) ($employee->display_name ?: $employee->name));
            if ($user === null || ! $user->isAuthenticationAllowed() || $user->must_change_password) {
                $this->alerts->seatAccountNotReady($name, $label, $dutyDate, (int) $employee->getKey());
            } elseif (! $this->qualifiesBesidesRoster($user, $role, $dutyDate)) {
                $this->alerts->seatOnlyByRoster($name, $label, $dutyDate, (int) $employee->getKey());
            }
        }

        return true;
    }

    /** A bad or forged export could name anyone, so someone who gets a page from the roster alone is worth a look. */
    private function qualifiesBesidesRoster(User $user, string $role, string $dutyDate): bool
    {
        if ($role === RosterSection::ROLE_COMMAND_300) {
            return $this->command300->qualifiesBesidesRoster($user);
        }

        // At the start of that duty day, because the next shift's roster counts for nothing until then.
        $sources = $this->captain5->decide($user, $this->calendar->window($dutyDate)[0])->sources;

        return array_diff($sources, [Captain5AccessDecision::SOURCE_ROSTER_SEAT]) !== [];
    }
}
