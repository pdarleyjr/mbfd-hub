<?php

namespace App\Policies;

use App\Models\ExternalSource;
use App\Models\User;

class ExternalSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('training_admin');
    }

    public function view(User $user, ExternalSource $source): bool
    {
        return $user->hasRole('training_admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('training_admin');
    }

    public function update(User $user, ExternalSource $source): bool
    {
        return $user->hasRole('training_admin');
    }

    public function delete(User $user, ExternalSource $source): bool
    {
        return $user->hasRole('training_admin');
    }
}
