<?php

namespace App\Observers;

use App\Models\Training\TrainingTodo;
use App\Models\User;
use App\Notifications\TrainingTodoAssignedNotification;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Support\Str;

class TrainingTodoObserver
{
    public function created(TrainingTodo $todo): void
    {
        $assigneeIds = $this->normalizeUserIds($todo->assigned_to);

        // Notify assignees when present. For an unassigned list-wide task, the training panel audience is told
        // (found by the queued fan-out) so the todo is still visible to mobile users.
        $this->notifyRecipients($todo, $assigneeIds === [] ? null : $assigneeIds, 'created');
    }

    public function updated(TrainingTodo $todo): void
    {
        if (! $todo->wasChanged('assigned_to')) {
            return;
        }

        $oldAssignees = $this->normalizeUserIds($todo->getOriginal('assigned_to'));
        $newAssignees = $this->normalizeUserIds($todo->assigned_to);
        $newlyAssigned = array_values(array_diff($newAssignees, $oldAssignees));

        if ($newlyAssigned === []) {
            return;
        }

        // Each assignment is its own event (its key carries a fresh id): unassigning and assigning the same person
        // again is a new alert. The key still collapses a retry of this very fan-out.
        $this->notifyRecipients($todo, $newlyAssigned, 'assigned:'.Str::uuid());
    }

    /**
     * Never fails or slows the todo's own save: the fan-out is queued after commit and guarded.
     *
     * @param  list<int>|null  $userIds  null tells the whole training audience
     */
    private function notifyRecipients(TrainingTodo $todo, ?array $userIds, string $event): void
    {
        try {
            $actorId = auth()->id();
            $candidates = null;
            if ($userIds !== null) {
                $recipientIds = collect($userIds)
                    ->map(fn ($id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0 && $id !== $actorId)
                    ->unique()
                    ->values();
                if ($recipientIds->isEmpty()) {
                    return;
                }
                $candidates = User::query()->whereIn('id', $recipientIds)->get();
                if ($candidates->isEmpty()) {
                    return;
                }
            }

            // Only people who can still open Training are told; the same person is told once per assignment event.
            app(NotificationDispatchService::class)->dispatch('training_assignments', new TrainingTodoAssignedNotification(
                todoId: $todo->id,
                title: $todo->title,
                priority: $todo->priority ?? 'medium',
                actionUrl: route('filament.training.resources.training-todos.view', ['record' => $todo]),
            ), $candidates, NotificationDispatchService::eventKey('training_assignments', 'todo', $todo->id, $event), $userIds === null && $actorId !== null ? (int) $actorId : null);
        } catch (\Throwable $exception) {
            report($exception);
        }
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
