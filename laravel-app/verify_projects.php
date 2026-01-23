<?php

require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== CAPITAL PROJECTS VERIFICATION ===\n\n";
echo "Total Projects: " . \App\Models\CapitalProject::count() . "\n\n";

$projects = \App\Models\CapitalProject::orderBy('project_number')->get();

echo "Project Listing:\n";
echo str_repeat("-", 120) . "\n";
printf("%-15s | %-40s | %15s | %10s | %10s\n", "Project #", "Name", "Budget", "Priority", "Status");
echo str_repeat("-", 120) . "\n";

foreach ($projects as $p) {
    printf("%-15s | %-40s | $%14s | %10s | %10s\n", 
        $p->project_number,
        substr($p->name, 0, 40),
        number_format($p->budget_amount, 2),
        $p->priority->value,
        $p->status->value
    );
}

echo str_repeat("-", 120) . "\n";
echo "\nTotal Milestones: " . \App\Models\ProjectMilestone::count() . "\n";

$milestones = \App\Models\ProjectMilestone::all();
if ($milestones->count() > 0) {
    echo "\nMilestones:\n";
    foreach ($milestones as $m) {
        $project = \App\Models\CapitalProject::find($m->capital_project_id);
        echo "  - " . $m->title . " (Project: " . $project->project_number . ", Due: " . $m->due_date . ")\n";
    }
}
