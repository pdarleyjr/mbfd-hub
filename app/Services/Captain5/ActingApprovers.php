<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Captain5\Captain5ActingRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who approves acting Captain 5 and acting 300 requests (the requests themselves never grant
 * anything). The user's rule for both roles: "any admin member", i.e. an active account with no
 * pending password setup and current Admin Panel rights (super_admin or a direct `admin.access`
 * grant). Nobody approves their own request except a super admin (Captain5ActingService).
 */
final class ActingApprovers
{
    /** Same set for every role; the role parameter keeps call sites explicit if the rules ever differ. */
    public function can(?User $user, string $role): bool
    {
        return $user instanceof User && in_array($role, Captain5ActingRequest::ROLES, true)
            && $user->isAuthenticationAllowed() && ! $user->must_change_password && $user->hasCurrentAdminPanelEntitlement();
    }

    public function canApproveAny(?User $user): bool
    {
        return $this->can($user, Captain5ActingRequest::ROLE_CAPTAIN_5);
    }

    /** @return Collection<int, User> the people asked to approve a request of this role */
    public function users(string $role): Collection
    {
        return User::query()
            ->where(fn ($query) => $query->whereHas('roles', fn ($roles) => $roles->where('name', 'super_admin'))
                ->orWhereHas('permissions', fn ($permissions) => $permissions->where('name', 'admin.access')))
            ->orderBy('id')->get()
            ->filter(fn (User $user): bool => $this->can($user, $role))
            ->values();
    }
}
