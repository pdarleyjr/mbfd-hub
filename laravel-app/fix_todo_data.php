<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Todo;
use App\Models\User;

echo "Checking todos...\n";

$todos = Todo::all();

foreach ($todos as $todo) {
    echo "Todo #{$todo->id}: {$todo->title}\n";
    echo "  assigned_to: " . json_encode($todo->assigned_to) . "\n";
    echo "  assigned_by: " . json_encode($todo->assigned_by) . "\n";
    echo "  created_by: " . json_encode($todo->created_by) . "\n";
    echo "  created_by_user_id: " . json_encode($todo->created_by_user_id) . "\n";
    echo "  status: " . json_encode($todo->status) . "\n";
    
    // Fix assigned users
    if (is_array($todo->assigned_to) && count($todo->assigned_to) > 0) {
        $users = User::whereIn('id', $todo->assigned_to)->pluck('name')->toArray();
        echo "  Assigned users: " . implode(',', $users) . "\n";
    }
    
    echo "\n";
}

echo "\nDone!\n";
