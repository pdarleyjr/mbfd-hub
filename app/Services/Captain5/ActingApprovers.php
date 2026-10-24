<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Captain5\Captain5ActingRequest;
use App\Models\User;
use App\Services\Security\Captain5Access;
use Illuminate\Support\Collection;

/**
 * Who approves acting requests, per role (the requests themselves never grant anything):
 *  - acting Captain 5: Captain 5 administrators (Captain5Access::canConfigure: super_admin, or the
 *    Admin Panel plus `captain5.admin`), as released;
 *  - acting 300: any administrator (the user's rule): an active account with no pending password
 *    setup and current Admin Panel rights (super_admin or a direct `admin.access` grant).
 * Nobody approves their own request except a super admin (Captain5ActingService).
 */
final class ActingApprovers
{
    public function __construct(private readonly Captain5Access $access) {}

    public function can(?User $user, string $role): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $role === Captain5ActingRequest::ROLE_COMMAND_300
            ? $user->isAuthenticationAllowed() && ! $user->must_change_password && $user->hasCurrentAdminPanelEntitlement()
            : $this->access->canConfigure($user);
    }

    /** Anyone who may approve at least one role (the page and admin queue checks). */
    public function canApproveAny(?User $user): bool
    {
        return $this->can($user, Captain5ActingRequest::ROLE_COMMAND_300) || $this->can($user, Captain5ActingRequest::ROLE_CAPTAIN_5);
    }

    /** @return Collection<int, User> the people asked to approve a request of this role */
    public function users(string $role): Collection
    {
        $permission = $role === Captain5ActingRequest::ROLE_COMMAND_300 ? 'admin.access' : Captain5Access::PERMISSION_ADMIN;

        return User::query()
            ->where(fn ($query) => $query->whereHas('roles', fn ($roles) => $roles->where('name', 'super_admin'))
                ->orWhereHas('permissions', fn ($permissions) => $permissions->where('name', $permission)))
            ->orderBy('id')->get()
            ->filter(fn (User $user): bool => $this->can($user, $role))
            ->values();
    }
}
