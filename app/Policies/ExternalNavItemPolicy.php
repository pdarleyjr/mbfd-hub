<?php

namespace App\Policies;

use App\Models\ExternalNavItem;
use App\Models\User;

class ExternalNavItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['training_admin', 'training_viewer']);
    }

    public function view(User $user, ExternalNavItem $item): bool
    {
        return $user->hasAnyRole(['training_admin', 'training_viewer']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('training_admin');
    }

    public function update(User $user, ExternalNavItem $item): bool
    {
        return $user->hasRole('training_admin');
    }

    public function delete(User $user, ExternalNavItem $item): bool
    {
        return $user->hasRole('training_admin');
    }
}
