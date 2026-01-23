<?php

namespace App\Console\Commands;

use App\Models\ProjectMilestone;
use App\Models\NotificationTracking;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendMilestoneReminders extends Command
{
    protected $signature = 'projects:milestone-reminders';
    
    protected $description = 'Send reminders for upcoming milestones (7 days, 3 days, and 1 day before due date)';

    public function handle()
    {
        $this->info('Checking for upcoming milestones...');
        
        try {
            $reminders = [
                '7_days' => ['days' => 7, 'urgency' => 'info', 'icon_color' => 'info'],
                '3_days' => ['days' => 3, 'urgency' => 'warning', 'icon_color' => 'warning'],
                '1_day' => ['days' => 1, 'urgency' => 'urgent', 'icon_color' => 'danger'],
            ];
            
            $totalSent = 0;
            
            foreach ($reminders as $key => $config) {
                $targetDate = now()->addDays($config['days'])->startOfDay();
                
                // Find milestones due on this target date
                $milestones = ProjectMilestone::whereDate('due_date', $targetDate->toDateString())
                    ->where('completed', false)
                    ->with('project')
                    ->get();
                
                $this->info("Found {$milestones->count()} milestones due in {$config['days']} days");
                
                foreach ($milestones as $milestone) {
                    $sent = $this->sendMilestoneReminder($milestone, $config);
                    if ($sent) {
                        $totalSent++;
                    }
                }
            }
            
            $this->info("✓ Total reminders sent: {$totalSent}");
            
            Log::info('Milestone reminders sent', [
                'reminders_sent' => $totalSent,
            ]);
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Error sending milestone reminders: ' . $e->getMessage());
            Log::error('Milestone reminder error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }
    
    private function sendMilestoneReminder(ProjectMilestone $milestone, array $config): bool
    {
        $recipients = User::whereHas('roles', function ($query) {
            $query->whereIn('name', ['admin', 'project-manager']);
        })->get();
        
        $notificationType = "milestone_reminder_{$config['days']}_day";
        $sent = false;
        
        foreach ($recipients as $user) {
            // Check if we've already sent this specific reminder type for this milestone
            $existingNotification = NotificationTracking::where('user_id', $user->id)
                ->where('notifiable_type', ProjectMilestone::class)
                ->where('notifiable_id', $milestone->id)
                ->where('notification_type', $notificationType)
                ->exists();
            
            if ($existingNotification) {
                continue;
            }
            
            $urgencyText = $config['days'] == 1 ? 'tomorrow' : "in {$config['days']} days";
            
            Notification::make()
                ->title("Milestone Due {$urgencyText}")
                ->body("**{$milestone->title}** in project \"{$milestone->project->name}\" is due on {$milestone->due_date->format('M d, Y')}.")
                ->icon('heroicon-o-bell-alert')
                ->iconColor($config['icon_color'])
                ->actions([
                    \Filament\Notifications\Actions\Action::make('view')
                        ->label('View Project')
                        ->url(route('filament.admin.resources.capital-projects.edit', $milestone->project_id)),
                    \Filament\Notifications\Actions\Action::make('complete')
                        ->label('Mark Complete')
                        ->color('success'),
                ])
                ->sendToDatabase($user);
            
            NotificationTracking::create([
                'user_id' => $user->id,
                'notifiable_type' => ProjectMilestone::class,
                'notifiable_id' => $milestone->id,
                'notification_type' => $notificationType,
                'metadata' => [
                    'days_until_due' => $config['days'],
                    'due_date' => $milestone->due_date->toDateString(),
                    'project_id' => $milestone->project_id,
                    'project_name' => $milestone->project->name,
                ],
            ]);
            
            $sent = true;
        }
        
        return $sent;
    }
}
