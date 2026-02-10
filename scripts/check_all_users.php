<?php
// Check all users and their roles
require_once '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$users = App\Models\User::all();
echo "=== ALL USERS AND ROLES ===\n";
foreach($users as $u) {
    $roles = $u->getRoleNames()->toArray();
    $canAdmin = $u->hasRole('super_admin') || $u->hasRole('admin');
    $canTraining = $u->hasRole('super_admin') || $u->hasRole('training_admin') || $u->hasRole('training_viewer');
    echo $u->email . ' | roles: [' . implode(', ', $roles) . '] | admin: ' . ($canAdmin ? 'YES' : 'NO') . ' | training: ' . ($canTraining ? 'YES' : 'NO') . "\n";
}

echo "\n=== USERS WITHOUT ANY PANEL ACCESS ===\n";
foreach($users as $u) {
    $roles = $u->getRoleNames()->toArray();
    $canAdmin = $u->hasRole('super_admin') || $u->hasRole('admin');
    $canTraining = $u->hasRole('super_admin') || $u->hasRole('training_admin') || $u->hasRole('training_viewer') || $u->can('training.access');
    if (!$canAdmin && !$canTraining) {
        echo "NO ACCESS: " . $u->email . ' | roles: [' . implode(', ', $roles) . "]\n";
    }
}

echo "\n=== AVAILABLE ROLES IN SYSTEM ===\n";
$roles = Spatie\Permission\Models\Role::all();
foreach($roles as $r) {
    echo $r->name . " (guard: " . $r->guard_name . ")\n";
}
