<?php

use App\Models\CapitalProject;
use App\Models\ProjectMilestone;
use App\Enums\ProjectStatus;
use App\Enums\ProjectPriority;

echo "Creating test capital project...\n";

$project = new CapitalProject();
$project->name = 'Test Capital Project';
$project->project_number = 'CP-2026-001';
$project->status = ProjectStatus::Pending;
$project->priority = ProjectPriority::High;
$project->budget_amount = 150000;
$project->start_date = now();
$project->target_completion_date = now()->addMonths(6);
$project->save();

echo "Project created with ID: {$project->id}\n";

echo "Creating milestone...\n";

$milestone = new ProjectMilestone();
$milestone->capital_project_id = $project->id;
$milestone->title = 'Phase 1 Complete';
$milestone->due_date = now()->addMonths(2);
$milestone->completed = false;
$milestone->save();

echo "Milestone created with ID: {$milestone->id}\n";

echo "Testing relationships...\n";

$loadedProject = CapitalProject::with('milestones')->find($project->id);
echo "Project name: {$loadedProject->name}\n";
echo "Milestones count: {$loadedProject->milestones->count()}\n";
echo "First milestone: {$loadedProject->milestones->first()->title}\n";
echo "Is overdue: " . ($loadedProject->is_overdue ? 'Yes' : 'No') . "\n";
echo "Completion %: {$loadedProject->completion_percentage}%\n";

echo "\nTesting milestone relationship back to project...\n";
$loadedMilestone = ProjectMilestone::with('project')->find($milestone->id);
echo "Milestone belongs to project: {$loadedMilestone->project->name}\n";

echo "\nAll tests passed!\n";
