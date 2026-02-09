<?php

namespace App\Policies;

use App\Models\Training\TrainingTodo;
use App\Models\User;

class TrainingTodoPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TrainingTodo $trainingTodo): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, TrainingTodo $trainingTodo): bool
    {
        $assignedTo = $trainingTodo->assigned_to ?? [];
        return $user->is_admin 
            || $trainingTodo->created_by === $user->id 
            || in_array($user->id, $assignedTo);
    }

    public function delete(User $user, TrainingTodo $trainingTodo): bool
    {
        return $user->is_admin || $trainingTodo->created_by === $user->id;
    }
}
