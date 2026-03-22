<?php

namespace App\Console\Commands;

use App\Models\CapitalProject;
use App\Models\ProjectMilestone;
use App\Models\NotificationTracking;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckOverdueProjects extends Command
{
    protected $signature = 'projects:check-overdue';
    
    protected $description = 'Check for overdue projects and milestones and send notifications';

    public function handle()
    {
        $this->info('Checking for overdue projects and milestones...');
        
        $overdueProjects = [];
        $overdueMilestones = [];
        
        try {
            // Find overdue projects - using correct column names
            $overdueProjects = CapitalProject::where('target_completion_date', '<', now())
                ->whereNull('actual_completion')
                ->whereNotIn('status', ['completed', 'on-hold'])
                ->get();
            
            // Find overdue milestones
            $overdueMilestones = ProjectMilestone::where('due_date', '<', now())
                ->where('completed', false)
                ->with('project')
                ->get();
            
            $this->info("Found {$overdueProjects->count()} overdue projects");
            $this->info("Found {$overdueMilestones->count()} overdue milestones");
            
            // Send notifications for overdue projects
            foreach ($overdueProjects as $project) {
                $this->notifyOverdueProject($project);
            }
            
            // Send notifications for overdue milestones
            foreach ($overdueMilestones as $milestone) {
                $this->notifyOverdueMilestone($milestone);
            }
            
            // Display summary
            $this->newLine();
            $this->info('=== Overdue Items Summary ===');
            $this->table(
                ['Type', 'Count'],
                [
                    ['Overdue Projects', $overdueProjects->count()],
                    ['Overdue Milestones', $overdueMilestones->count()],
                ]
            );
            
            Log::info('Overdue check completed', [
                'overdue_projects' => $overdueProjects->count(),
                'overdue_milestones' => $overdueMilestones->count(),
            ]);
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Error checking overdue items: ' . $e->getMessage());
            Log::error('Check overdue projects error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }
    
    private function notifyOverdueProject(CapitalProject $project): void
    {
        $recipients = User::whereHas('roles', function ($query) {
            $query->whereIn('name', ['admin', 'project-manager']);
        })->get();
        
        $daysOverdue = now()->diffInDays($project->target_completion_date);
        
        foreach ($recipients as $user) {
            // Check if we've notified this user today about this project
            $recentNotification = NotificationTracking::where('user_id', $user->id)
                ->where('notifiable_type', CapitalProject::class)
                ->where('notifiable_id', $project->id)
                ->where('notification_type', 'overdue_project')
                ->where('created_at', '>=', now()->startOfDay())
                ->exists();
            
            if ($recentNotification) {
                continue;
            }
            
            Notification::make()
                ->title('Overdue Project')
                ->body("**{$project->name}** is {$daysOverdue} days overdue. Target completion was {$project->target_completion_date->format('M d, Y')}.")
                ->icon('heroicon-o-clock')
                ->iconColor('danger')
                ->actions([
                    \Filament\Notifications\Actions\Action::make('view')
                        ->label('View Project')
                        ->url(route('filament.admin.resources.capital-projects.edit', $project)),
                ])
                ->sendToDatabase($user);
            
            NotificationTracking::create([
                'user_id' => $user->id,
                'notifiable_type' => CapitalProject::class,
                'notifiable_id' => $project->id,
                'notification_type' => 'overdue_project',
                'metadata' => [
                    'days_overdue' => $daysOverdue,
                    'target_completion_date' => $project->target_completion_date->toDateString(),
                ],
            ]);
        }
    }
    
    private function notifyOverdueMilestone(ProjectMilestone $milestone): void
    {
        $recipients = User::whereHas('roles', function ($query) {
            $query->whereIn('name', ['admin', 'project-manager']);
        })->get();
        
        $daysOverdue = now()->diffInDays($milestone->due_date);
        
        foreach ($recipients as $user) {
            // Check if we've notified this user today about this milestone
            $recentNotification = NotificationTracking::where('user_id', $user->id)
                ->where('notifiable_type', ProjectMilestone::class)
                ->where('notifiable_id', $milestone->id)
                ->where('notification_type', 'overdue_milestone')
                ->where('created_at', '>=', now()->startOfDay())
                ->exists();
            
            if ($recentNotification) {
                continue;
            }
            
            Notification::make()
                ->title('Overdue Milestone')
                ->body("Milestone \"**{$milestone->title}**\" in project {$milestone->project->name} is {$daysOverdue} days overdue.")
                ->icon('heroicon-o-flag')
                ->iconColor('danger')
                ->actions([
                    \Filament\Notifications\Actions\Action::make('view')
                        ->label('View Project')
                        ->url(route('filament.admin.resources.capital-projects.edit', $milestone->project_id)),
                ])
                ->sendToDatabase($user);
            
            NotificationTracking::create([
                'user_id' => $user->id,
                'notifiable_type' => ProjectMilestone::class,
                'notifiable_id' => $milestone->id,
                'notification_type' => 'overdue_milestone',
                'metadata' => [
                    'days_overdue' => $daysOverdue,
                    'due_date' => $milestone->due_date->toDateString(),
                    'project_id' => $milestone->project_id,
                ],
            ]);
        }
    }
}
