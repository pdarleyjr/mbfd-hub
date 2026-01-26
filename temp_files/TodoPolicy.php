<?php

namespace App\Policies;

use App\Models\Todo;
use App\Models\User;

class TodoPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Todo $todo): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Todo $todo): bool
    {
        return true;
    }

    public function delete(User $user, Todo $todo): bool
    {
        return $user->hasRole('admin') || $todo->created_by === $user->id;
    }
}
