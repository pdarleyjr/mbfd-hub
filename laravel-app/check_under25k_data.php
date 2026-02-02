<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Under 25k Projects Database Check ===" . PHP_EOL;
echo "Total projects: " . App\Models\Under25kProject::count() . PHP_EOL;
echo "Projects with zone PS: " . App\Models\Under25kProject::where('zone', 'PS')->count() . PHP_EOL;
echo "Projects with null zone: " . App\Models\Under25kProject::whereNull('zone')->count() . PHP_EOL;

echo PHP_EOL . "=== Sample projects ===" . PHP_EOL;
$projects = App\Models\Under25kProject::limit(5)->get(['id', 'facility_project_name', 'zone']);
foreach ($projects as $project) {
    echo "ID: {$project->id}, Name: {$project->facility_project_name}, Zone: {$project->zone}" . PHP_EOL;
}
