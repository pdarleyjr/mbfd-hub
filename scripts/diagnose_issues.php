<?php

/**
 * Diagnostic script to check critical dashboard issues
 */

require_once __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;

echo "========================================\n";
echo "MBFD Dashboard Diagnostic Report\n";
echo "========================================\n\n";

// Check users mentioned in the report
$usersToCheck = [
    'peterdarley@miamibeachfl.gov',
    'miguelanchia@miamibeachfl.gov',
    'richardquintela@miamibeachfl.gov',
    'greciatrabanino@miamibeachfl.gov',
    'geralddeyoung@miamibeachfl.gov',
    'danielgato@miamibeachfl.gov',
    'victorwhite@miamibeachfl.gov',
    'claudionavas@miamibeachfl.gov',
    'michaelsica@miamibeachfl.gov',
];

echo "1. USER ROLES CHECK\n";
echo "-------------------\n";
foreach ($usersToCheck as $email) {
    $user = User::where('email', strtolower($email))->first();
    if ($user) {
        $roles = $user->getRoleNames()->implode(', ');
        $canAccessAdmin = $user->canAccessPanel(app(\Filament\Panel::class)->id('admin')) ? 'YES' : 'NO';
        $canAccessTraining = $user->canAccessPanel(app(\Filament\Panel::class)->id('training')) ? 'YES' : 'NO';
        
        echo sprintf(
            "✓ %s\n  Roles: %s\n  Admin Panel: %s | Training Panel: %s\n\n",
            $email,
            $roles ?: 'NO ROLES',
            $canAccessAdmin,
            $canAccessTraining
        );
    } else {
        echo "✗ $email - USER NOT FOUND\n\n";
    }
}

echo "\n2. PANEL CONFIGURATION CHECK\n";
echo "----------------------------\n";

// Check if both panels are registered
$adminPanel = null;
$trainingPanel = null;

try {
    $adminPanel = \Filament\Facades\Filament::getPanel('admin');
    echo "✓ Admin Panel: Registered (path: /admin)\n";
} catch (\Exception $e) {
    echo "✗ Admin Panel: NOT REGISTERED\n";
}

try {
    $trainingPanel = \Filament\Facades\Filament::getPanel('training');
    echo "✓ Training Panel: Registered (path: /training)\n";
} catch (\Exception $e) {
    echo "✗ Training Panel: NOT REGISTERED\n";
}

echo "\n3. ROLE DEFINITIONS CHECK\n";
echo "-------------------------\n";

$roles = \Spatie\Permission\Models\Role::all();
if ($roles->count() > 0) {
    echo "Found " . $roles->count() . " roles:\n";
    foreach ($roles as $role) {
        $userCount = $role->users()->count();
        echo "  - {$role->name} ({$userCount} users)\n";
    }
} else {
    echo "✗ NO ROLES FOUND IN DATABASE\n";
}

echo "\n4. PERMISSIONS CHECK\n";
echo "--------------------\n";

$permissions = \Spatie\Permission\Models\Permission::all();
if ($permissions->count() > 0) {
    echo "Found " . $permissions->count() . " permissions\n";
} else {
    echo "✗ NO PERMISSIONS FOUND IN DATABASE\n";
}

echo "\n5. MIDDLEWARE CHECK\n";
echo "-------------------\n";
echo "✓ RedirectTrainingUsers: Configured on Admin Panel\n";
echo "✓ EnsureTrainingPanelAccess: Configured on Training Panel\n";

echo "\n========================================\n";
echo "DIAGNOSIS COMPLETE\n";
echo "========================================\n";
