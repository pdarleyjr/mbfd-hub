<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PersonnelRequest;
use App\Models\User;

class PersonnelRequestPolicy
{
    private function manage(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
    }

    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, PersonnelRequest $request): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, PersonnelRequest $request): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, PersonnelRequest $request): bool
    {
        return false;
    }

    public function restore(User $user, PersonnelRequest $request): bool
    {
        return false;
    }

    public function forceDelete(User $user, PersonnelRequest $request): bool
    {
        return false;
    }
}
