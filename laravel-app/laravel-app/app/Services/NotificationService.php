<?php

namespace App\Services;

use App\Models\CapitalProject;
use App\Models\NotificationTracking;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

class NotificationService
{
    /**
     * Send a project notification to specified recipients
     */
    public function sendProjectNotification(
        CapitalProject $project,
        string $type,
        string $message,
        array $recipients
    ): int {
        $sent = 0;
        
        foreach ($recipients as $user) {
            // Check cooldown before sending
            if (!$this->checkNotificationCooldown($user, $project, $type)) {
                continue;
            }
            
            // Determine notification styling based on type
            [$icon, $iconColor] = $this->getNotificationStyle($type);
            
            // Create and send notification
            Notification::make()
                ->title($this->getNotificationTitle($type))
                ->body($message)
                ->icon($icon)
                ->iconColor($iconColor)
                ->actions([
                    \Filament\Notifications\Actions\Action::make('view')
                        ->label('View Project')
                        ->url(route('filament.admin.resources.capital-projects.edit', $project)),
                ])
                ->sendToDatabase($user);
            
            // Track the notification
            $this->trackNotification($user, $project, $type);
            
            $sent++;
        }
        
        return $sent;
    }
    
    /**
     * Check if a notification has been sent recently (within cooldown period)
     */
    public function checkNotificationCooldown(
        User $user,
        CapitalProject $project,
        string $type,
        int $hours = 24
    ): bool {
        $exists = NotificationTracking::where('user_id', $user->id)
            ->where('notifiable_type', CapitalProject::class)
            ->where('notifiable_id', $project->id)
            ->where('notification_type', $type)
            ->where('created_at', '>=', now()->subHours($hours))
            ->exists();
        
        return !$exists; // Return true if we CAN send (no recent notification)
    }
    
    /**
     * Track a sent notification to prevent duplicates
     */
    public function trackNotification(
        User $user,
        CapitalProject $project,
        string $type,
        array $metadata = []
    ): NotificationTracking {
        return NotificationTracking::create([
            'user_id' => $user->id,
            'notifiable_type' => CapitalProject::class,
            'notifiable_id' => $project->id,
            'notification_type' => $type,
            'metadata' => $metadata,
        ]);
    }
    
    /**
     * Get users by role(s) for batch notifications
     */
    public function getBatchRecipients(array $roles = ['admin', 'project-manager']): Collection
    {
        return User::whereHas('roles', function ($query) use ($roles) {
            $query->whereIn('name', $roles);
        })->get();
    }
    
    /**
     * Get notification styling based on type
     */
    private function getNotificationStyle(string $type): array
    {
        $styles = [
            'priority_alert' => ['heroicon-o-exclamation-triangle', 'warning'],
            'overdue_project' => ['heroicon-o-clock', 'danger'],
            'overdue_milestone' => ['heroicon-o-flag', 'danger'],
            'milestone_reminder_7_day' => ['heroicon-o-bell-alert', 'info'],
            'milestone_reminder_3_day' => ['heroicon-o-bell-alert', 'warning'],
            'milestone_reminder_1_day' => ['heroicon-o-bell-alert', 'danger'],
            'budget_alert' => ['heroicon-o-currency-dollar', 'warning'],
            'status_update' => ['heroicon-o-information-circle', 'info'],
        ];
        
        return $styles[$type] ?? ['heroicon-o-bell', 'info'];
    }
    
    /**
     * Get notification title based on type
     */
    private function getNotificationTitle(string $type): string
    {
        $titles = [
            'priority_alert' => 'High Priority Project Alert',
            'overdue_project' => 'Overdue Project',
            'overdue_milestone' => 'Overdue Milestone',
            'milestone_reminder_7_day' => 'Milestone Due in 7 Days',
            'milestone_reminder_3_day' => 'Milestone Due in 3 Days',
            'milestone_reminder_1_day' => 'Milestone Due Tomorrow',
            'budget_alert' => 'Budget Alert',
            'status_update' => 'Project Status Update',
        ];
        
        return $titles[$type] ?? 'Project Notification';
    }
}
