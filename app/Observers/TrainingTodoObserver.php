<?php

namespace App\Observers;

use App\Models\Training\TrainingTodo;
use App\Models\User;
use App\Notifications\TrainingTodoAssignedNotification;
use Illuminate\Support\Facades\Notification;

class TrainingTodoObserver
{
    public function created(TrainingTodo $todo): void
    {
        $this->notifyRecipients($todo, $this->recipientIdsForCreatedTodo($todo));
    }

    public function updated(TrainingTodo $todo): void
    {
        if (! $todo->wasChanged('assigned_to')) {
            return;
        }

        $oldAssignees = $this->normalizeUserIds($todo->getOriginal('assigned_to'));
        $newAssignees = $this->normalizeUserIds($todo->assigned_to);
        $newlyAssigned = array_values(array_diff($newAssignees, $oldAssignees));

        $this->notifyRecipients($todo, $newlyAssigned);
    }

    /**
     * Notify assignees when present. For unassigned list-wide tasks, notify the
     * training panel audience so the todo is still visible to mobile users.
     *
     * @return array<int>
     */
    private function recipientIdsForCreatedTodo(TrainingTodo $todo): array
    {
        $assigneeIds = $this->normalizeUserIds($todo->assigned_to);

        if ($assigneeIds !== []) {
            return $assigneeIds;
        }

        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', [
                'super_admin',
                'training_admin',
                'training_viewer',
            ]))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function notifyRecipients(TrainingTodo $todo, array $userIds): void
    {
        $actorId = auth()->id();
        $recipientIds = collect($userIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0 && $id !== $actorId)
            ->unique()
            ->values();

        if ($recipientIds->isEmpty()) {
            return;
        }

        $recipients = User::query()
            ->whereIn('id', $recipientIds)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new TrainingTodoAssignedNotification(
            todoId: $todo->id,
            title: $todo->title,
            priority: $todo->priority ?? 'medium',
            actionUrl: route('filament.training.resources.training-todos.view', ['record' => $todo]),
        ));
    }

    /**
     * @return array<int>
     */
    private function normalizeUserIds(mixed $userIds): array
    {
        if (is_string($userIds)) {
            $decoded = json_decode($userIds, true);
            $userIds = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($userIds)) {
            $userIds = [];
        }

        return collect($userIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
