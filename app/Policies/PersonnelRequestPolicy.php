<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PersonnelRequestType;
use App\Models\PersonnelRequest;
use App\Models\User;
use App\Services\Security\AdminCapabilityGate;

class PersonnelRequestPolicy
{
    private function manage(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return app(AdminCapabilityGate::class)->decision($user, 'update', [PersonnelRequest::class])
            ?? $user->hasAnyRole(['admin', 'logistics_admin']);
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
        return $this->manage($user) && ! $request->trashed();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, PersonnelRequest $request): bool
    {
        return $this->manage($user) && $request->type === PersonnelRequestType::Uniform;
    }

    public function restore(User $user, PersonnelRequest $request): bool
    {
        return $this->delete($user, $request);
    }

    public function forceDelete(User $user, PersonnelRequest $request): bool
    {
        return false;
    }
}
