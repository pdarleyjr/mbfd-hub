<?php
// Comprehensive fix: remove duplicate users, ensure all users have correct roles, clear caches
require_once '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use Spatie\Permission\Models\Role;

echo "=== STEP 1: Fix duplicate users (case-sensitive email duplicates) ===\n";

$users = User::all();
$emailMap = [];

foreach ($users as $user) {
    $lower = strtolower($user->email);
    if (!isset($emailMap[$lower])) {
        $emailMap[$lower] = [];
    }
    $emailMap[$lower][] = $user;
}

foreach ($emailMap as $email => $dupes) {
    if (count($dupes) > 1) {
        echo "DUPLICATE found for: $email (" . count($dupes) . " records)\n";
        // Keep the lowest ID, delete the rest
        usort($dupes, function($a, $b) {
            return $a->id - $b->id;
        });
        $keep = $dupes[0];
        echo "  Keeping user ID {$keep->id} ({$keep->email})\n";
        
        // Delete duplicates first
        for ($i = 1; $i < count($dupes); $i++) {
            $del = $dupes[$i];
            echo "  Deleting duplicate user ID {$del->id} ({$del->email})\n";
            $del->roles()->detach();
            $del->permissions()->detach();
            // Use DB delete to bypass model events/accessors
            \Illuminate\Support\Facades\DB::table('users')->where('id', $del->id)->delete();
        }
        
        // Now fix email case if needed
        if ($keep->getRawOriginal('email') !== $email) {
            \Illuminate\Support\Facades\DB::table('users')->where('id', $keep->id)->update(['email' => $email]);
            echo "  Fixed email case for kept user ID {$keep->id}\n";
        }
    }
}

echo "\n=== STEP 2: Ensure all expected users exist with correct roles ===\n";

// Admin users
$adminUsers = [
    'miguelanchia@miamibeachfl.gov',
    'richardquintela@miamibeachfl.gov',
    'peterdarley@miamibeachfl.gov',
    'greciatrabanino@miamibeachfl.gov',
    'geralddeyoung@miamibeachfl.gov',
];

foreach ($adminUsers as $email) {
    $user = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
    if ($user) {
        if (!$user->hasRole('super_admin')) {
            $user->assignRole('super_admin');
            echo "  Assigned super_admin to $email\n";
        }
        if (!$user->hasRole('admin')) {
            $user->assignRole('admin');
            echo "  Assigned admin to $email\n";
        }
        echo "  OK: $email has roles: " . json_encode($user->getRoleNames()) . "\n";
    } else {
        echo "  WARNING: User $email not found!\n";
    }
}

// Training admin users
$trainingUsers = [
    'danielgato@miamibeachfl.gov',
    'victorwhite@miamibeachfl.gov',
    'claudionavas@miamibeachfl.gov',
    'michaelsica@miamibeachfl.gov',
];

foreach ($trainingUsers as $email) {
    $user = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
    if ($user) {
        if (!$user->hasRole('training_admin')) {
            $user->assignRole('training_admin');
            echo "  Assigned training_admin to $email\n";
        }
        echo "  OK: $email has roles: " . json_encode($user->getRoleNames()) . "\n";
    } else {
        echo "  WARNING: User $email not found!\n";
    }
}

echo "\n=== STEP 3: Clear permission cache ===\n";
app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
echo "Permission cache cleared.\n";

echo "\n=== STEP 4: Final user list ===\n";
$users = User::all();
foreach ($users as $u) {
    $roles = $u->getRoleNames()->toArray();
    $canAdmin = $u->hasRole('super_admin') || $u->hasRole('admin');
    $canTraining = $u->hasRole('super_admin') || $u->hasRole('training_admin') || $u->hasRole('training_viewer');
    echo $u->id . ' | ' . $u->email . ' | roles: [' . implode(', ', $roles) . '] | admin: ' . ($canAdmin ? 'YES' : 'NO') . ' | training: ' . ($canTraining ? 'YES' : 'NO') . "\n";
}

echo "\nDONE.\n";
