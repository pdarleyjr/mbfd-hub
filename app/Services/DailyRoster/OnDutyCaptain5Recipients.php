<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\Captain5\Captain5ActingRequest;
use App\Models\User;
use App\Services\Captain5\Captain5DutyRoster;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Who the shift's Captain 5 alerts go to: the person the card shows at this moment, so they follow the
 * TeleStaff roster and change hands at the time the roster says. When the card names nobody, or names
 * someone with no Hub account, every Captain 5 seat holder hears instead, so an alert is never lost.
 *
 * Callers still drop anyone who cannot use the Captain 5 page right now.
 */
final class OnDutyCaptain5Recipients
{
    public function __construct(
        private readonly Captain5DutyRoster $roster,
        private readonly OnDutyOfficerDisplayResolver $display,
    ) {}

    /** @return Collection<int, User> */
    public function users(?DateTimeInterface $moment = null): Collection
    {
        $shown = $this->display->resolve(
            Captain5ActingRequest::ROLE_CAPTAIN_5,
            $moment === null ? null : CarbonImmutable::instance($moment),
        );
        if ($shown->employee !== null) {
            $users = $this->roster->usersFor([(int) $shown->employee->getKey()]);
            if ($users->isNotEmpty()) {
                return $users;
            }
        }

        return $this->roster->usersFor($this->roster->captain5Seats()->keys());
    }
}
