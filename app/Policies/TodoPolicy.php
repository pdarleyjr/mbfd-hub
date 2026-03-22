<?php

namespace App\Policies;

use App\Models\Todo;
use App\Models\User;

class TodoPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Todo $todo): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Todo $todo): bool
    {
        // Allow if user is admin, creator, or one of the assignees
        $assignedTo = $todo->assigned_to ?? [];
        return $user->is_admin 
            || $todo->created_by === $user->id 
            || in_array($user->id, $assignedTo);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Todo $todo): bool
    {
        // Allow if user is admin or creator
        return $user->is_admin || $todo->created_by === $user->id;
    }
}
