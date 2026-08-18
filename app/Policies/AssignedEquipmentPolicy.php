<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AssignedEquipment;
use App\Models\User;

class AssignedEquipmentPolicy
{
    private function manage(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
    }

    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, AssignedEquipment $assignment): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, AssignedEquipment $assignment): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, AssignedEquipment $assignment): bool
    {
        return false;
    }

    public function restore(User $user, AssignedEquipment $assignment): bool
    {
        return false;
    }

    public function forceDelete(User $user, AssignedEquipment $assignment): bool
    {
        return false;
    }
}
