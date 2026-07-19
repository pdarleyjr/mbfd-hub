<?php

namespace App\Policies;

use App\Models\OperationalFormRecord;
use App\Models\User;

class OperationalFormRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
    }

    public function view(User $user, OperationalFormRecord $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, OperationalFormRecord $record): bool
    {
        return false;
    }

    public function delete(User $user, OperationalFormRecord $record): bool
    {
        return $this->viewAny($user);
    }

    public function restore(User $user, OperationalFormRecord $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, OperationalFormRecord $record): bool
    {
        return $this->viewAny($user);
    }
}
