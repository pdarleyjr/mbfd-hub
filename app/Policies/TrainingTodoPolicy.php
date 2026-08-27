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
        return $this->canManageTrainingTodos($user);
    }

    public function update(User $user, TrainingTodo $trainingTodo): bool
    {
        return $this->canManageTrainingTodos($user);
    }

    public function delete(User $user, TrainingTodo $trainingTodo): bool
    {
        return $this->canManageTrainingTodos($user);
    }

    private function canManageTrainingTodos(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'training_admin']);
    }
}
