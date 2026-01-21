<?php

namespace App\Filament\Widgets;

use App\Models\CapitalProject;
use App\Models\ProjectMilestone;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class PriorityNotificationsWidget extends Widget
{
    protected static string $view = 'filament.widgets.priority-notifications-widget';
    
    protected int | string | array $columnSpan = 'full';
    
    protected static ?int $sort = 2;

    public function getPollingInterval(): ?string
    {
        return '30s';
    }

    public function getNotifications(): array
    {
        $notifications = [];
        
        // Get overdue milestones
        $overdueMilestones = ProjectMilestone::query()
            ->with('capitalProject')
            ->where('due_date', '<', now())
            ->where('status', '!=', 'completed')
            ->get();
            
        foreach ($overdueMilestones as $milestone) {
            $notifications[] = [
                'id' => 'milestone-' . $milestone->id,
                'type' => 'overdue_milestone',
                'priority' => 'high',
                'project_id' => $milestone->capital_project_id,
                'project_name' => $milestone->capitalProject->name,
                'title' => 'Overdue Milestone',
                'message' => $milestone->title . ' was due ' . $milestone->due_date->diffForHumans(),
                'action_url' => route('filament.admin.resources.capital-projects.view', ['record' => $milestone->capital_project_id]),
            ];
        }
        
        // Get projects past target completion date
        $overdueProjects = CapitalProject::query()
            ->whereNull('actual_completion_date')
            ->where('target_completion_date', '<', now())
            ->get();
            
        foreach ($overdueProjects as $project) {
            $notifications[] = [
                'id' => 'project-overdue-' . $project->id,
                'type' => 'overdue_project',
                'priority' => 'high',
                'project_id' => $project->id,
                'project_name' => $project->name,
                'title' => 'Project Past Deadline',
                'message' => 'Target completion was ' . $project->target_completion_date->diffForHumans(),
                'action_url' => route('filament.admin.resources.capital-projects.view', ['record' => $project->id]),
            ];
        }
        
        // Get critical priority projects
        $criticalProjects = CapitalProject::query()
            ->where('priority', 'critical')
            ->whereNull('actual_completion_date')
            ->get();
            
        foreach ($criticalProjects as $project) {
            $notifications[] = [
                'id' => 'project-critical-' . $project->id,
                'type' => 'critical_priority',
                'priority' => 'critical',
                'project_id' => $project->id,
                'project_name' => $project->name,
                'title' => 'Critical Priority Project',
                'message' => 'This project requires immediate attention',
                'action_url' => route('filament.admin.resources.capital-projects.view', ['record' => $project->id]),
            ];
        }
        
        // Get high priority projects
        $highPriorityProjects = CapitalProject::query()
            ->where('priority', 'high')
            ->whereNull('actual_completion_date')
            ->limit(5)
            ->get();
            
        foreach ($highPriorityProjects as $project) {
            $notifications[] = [
                'id' => 'project-high-' . $project->id,
                'type' => 'high_priority',
                'priority' => 'high',
                'project_id' => $project->id,
                'project_name' => $project->name,
                'title' => 'High Priority Project',
                'message' => 'Project completion: ' . $project->completion_percentage . '%',
                'action_url' => route('filament.admin.resources.capital-projects.view', ['record' => $project->id]),
            ];
        }
        
        // Sort by priority
        usort($notifications, function ($a, $b) {
            $priorityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2];
            return ($priorityOrder[$a['priority']] ?? 3) <=> ($priorityOrder[$b['priority']] ?? 3);
        });
        
        return $notifications;
    }

    public function markComplete($notificationId): void
    {
        // Extract type and ID from notification ID
        $parts = explode('-', $notificationId);
        
        if ($parts[0] === 'milestone' && isset($parts[1])) {
            $milestone = ProjectMilestone::find($parts[1]);
            if ($milestone) {
                $milestone->update(['status' => 'completed']);
            }
        }
        
        $this->dispatch('refresh');
    }

    public function snooze($notificationId): void
    {
        // Could implement snoozing logic here
        $this->dispatch('refresh');
    }
}
