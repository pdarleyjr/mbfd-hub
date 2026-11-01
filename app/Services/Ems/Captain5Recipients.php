<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\User;
use App\Services\Captain5\Captain5DutyRoster;
use App\Services\DailyRoster\OnDutyCaptain5Recipients;
use App\Services\Security\Captain5Access;
use Illuminate\Support\Collection;

/**
 * Who hears about a new request. Composes only PUBLIC Captain 5 APIs (the private
 * Captain5Notifier::onDutyRecipients() is not touched) and applies the same rule:
 * the on-duty Captain 5 by the TeleStaff roster, else the Captain 5 seat holders, limited to people
 * who still pass Captain5Access right now; plus EMS Admins (direct admin.ems.manage, or super_admin)
 * with an active account.
 */
final class Captain5Recipients
{
    public function __construct(
        private readonly Captain5DutyRoster $roster,
        private readonly OnDutyCaptain5Recipients $onDuty,
        private readonly Captain5Access $access,
    ) {}

    /** @return Collection<int, User> */
    public function onDuty(): Collection
    {
        $recipients = $this->eligible($this->onDuty->users());

        return $recipients->isNotEmpty()
            ? $recipients
            : $this->eligible($this->roster->usersFor($this->roster->captain5Seats()->keys()));
    }

    /** @return Collection<int, User> */
    public function emsAdmins(): Collection
    {
        return User::query()
            ->where(fn ($query) => $query
                ->whereHas('roles', fn ($roles) => $roles->where('name', 'super_admin'))
                ->orWhereHas('permissions', fn ($permissions) => $permissions->where('guard_name', 'web')->where('name', EmsAccess::MANAGE)))
            ->get()
            ->filter(fn (User $user): bool => $user->isAuthenticationAllowed())
            ->values();
    }

    /** @return Collection<int, User> */
    public function staff(): Collection
    {
        return $this->onDuty()->merge($this->emsAdmins());
    }

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    private function eligible(Collection $users): Collection
    {
        return $users->filter(fn (User $user): bool => $this->access->decide($user)->canViewData)->values();
    }
}
