<?php

namespace App\Observers;

use App\Models\Todo;
use App\Models\User;
use Filament\Notifications\Notification;

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
        
        if (!empty($newlyAssigned)) {
            $this->notifyAssignees($todo, $newlyAssigned, 'assigned');
        }
    }

    protected function notifyAssignees(Todo $todo, array $userIds, string $action): void
    {
        if (empty($userIds)) {
            return;
        }

        $users = User::whereIn('id', $userIds)->get();
        
        foreach ($users as $user) {
            // Don't notify the person who created/updated the todo
            if ($user->id === auth()->id()) {
                continue;
            }

            Notification::make()
                ->title('New Todo Assigned')
                ->body("You have been assigned to: {$todo->title}")
                ->icon('heroicon-o-clipboard-document-list')
                ->iconColor('primary')
                ->actions([
                    \Filament\Notifications\Actions\Action::make('view')
                        ->label('View Todo')
                        ->url(route('filament.admin.resources.todos.edit', ['record' => $todo]))
                        ->markAsRead(),
                ])
                ->sendToDatabase($user);
        }
    }
}
