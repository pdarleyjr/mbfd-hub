<?php

namespace App\Observers;

use App\Models\Todo;
use App\Models\User;
use App\Notifications\OperationalAlertNotification;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Support\Str;

class TodoObserver
{
    public function created(Todo $todo): void
    {
        $this->notifyAssignees($todo, $todo->assigned_to ?? [], 'assigned');
    }

    public function updated(Todo $todo): void
    {
        $oldAssignees = $todo->getOriginal('assigned_to') ?? [];
        $newAssignees = $todo->assigned_to ?? [];

        // Find newly assigned users
        $newlyAssigned = array_diff($newAssignees, $oldAssignees);

        if (! empty($newlyAssigned)) {
            $this->notifyAssignees($todo, $newlyAssigned, 'assigned');
        }
    }

    protected function notifyAssignees(Todo $todo, array $userIds, string $action): void
    {
        if (empty($userIds)) {
            return;
        }

        try {
            // The person who created or updated the todo is not told.
            $users = User::whereIn('id', $userIds)->where('id', '!=', (int) auth()->id())->get();
            if ($users->isEmpty()) {
                return;
            }

            // Each assignment is its own event (its key carries a fresh id): unassigning and assigning the same person
            // again is a new alert. The key still collapses a retry of this very fan-out. Queued after the todo's write commits; whatever goes wrong there never undoes the todo.
            app(NotificationDispatchService::class)->dispatch(
                NotificationCategoryRegistry::TODO_ASSIGNMENTS,
                new OperationalAlertNotification(
                    category: NotificationCategoryRegistry::TODO_ASSIGNMENTS,
                    title: 'New Todo Assigned',
                    body: "You have been assigned to: {$todo->title}",
                    url: route('filament.admin.resources.todos.edit', ['record' => $todo]),
                    icon: 'heroicon-o-clipboard-document-list',
                    iconColor: 'primary',
                    actionLabel: 'View Todo',
                ),
                $users,
                NotificationDispatchService::eventKey(NotificationCategoryRegistry::TODO_ASSIGNMENTS, 'todo', $todo->id, $action.':'.Str::uuid()),
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
