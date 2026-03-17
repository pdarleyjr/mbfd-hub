<?php
/**
 * Fix authentication roles & users for logistics (admin) and training panels.
 * Run via: php artisan tinker < scripts/fix_auth_and_roles.php
 */

use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

// Reset permission cache
app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

// Ensure roles exist
foreach (['super_admin', 'admin', 'training_admin', 'training_viewer'] as $roleName) {
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
}

// Ensure permissions exist
foreach (['training.access', 'training.manage_external_links'] as $perm) {
    Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
}

// Sync permissions to roles
Role::findByName('training_admin', 'web')->syncPermissions(['training.access', 'training.manage_external_links']);
Role::findByName('training_viewer', 'web')->syncPermissions(['training.access']);
Role::findByName('super_admin', 'web')->syncPermissions(Permission::all());

echo "=== Roles & permissions synced ===\n";

// ----- LOGISTICS (ADMIN) DASHBOARD USERS -----
$adminUsers = [
    ['name' => 'Miguel Anchia',    'email' => 'miguelanchia@miamibeachfl.gov',    'password' => 'Penco1',        'roles' => ['admin']],
    ['name' => 'Richard Quintela', 'email' => 'richardquintela@miamibeachfl.gov', 'password' => 'Penco2',        'roles' => ['admin']],
    ['name' => 'Peter Darley',     'email' => 'peterdarley@miamibeachfl.gov',     'password' => 'Penco3',        'roles' => ['super_admin']],
    ['name' => 'Grecia Trabanino', 'email' => 'greciatrabanino@miamibeachfl.gov', 'password' => 'MBFDSupport!',  'roles' => ['admin']],
    ['name' => 'Gerald DeYoung',   'email' => 'geralddeyoung@miamibeachfl.gov',   'password' => 'MBFDGerry1',    'roles' => ['admin']],
];

foreach ($adminUsers as $u) {
    $user = User::updateOrCreate(
        ['email' => strtolower($u['email'])],
        ['name' => $u['name'], 'password' => bcrypt($u['password'])]
    );
    $user->syncRoles($u['roles']);
    echo "  [ADMIN] {$u['email']} -> roles: " . implode(', ', $u['roles']) . "\n";
}

// ----- TRAINING DASHBOARD USERS -----
$trainingUsers = [
    ['name' => 'Daniel Gato',   'email' => 'danielgato@miamibeachfl.gov',   'password' => 'Gato1234!', 'roles' => ['training_admin']],
    ['name' => 'Victor White',  'email' => 'victorwhite@miamibeachfl.gov',  'password' => 'Vic1234!',  'roles' => ['training_admin']],
    ['name' => 'Claudio Navas', 'email' => 'claudionavas@miamibeachfl.gov', 'password' => 'Flea1234!', 'roles' => ['training_admin']],
    ['name' => 'Michael Sica',  'email' => 'michaelsica@miamibeachfl.gov',  'password' => 'Sica1234!', 'roles' => ['training_admin']],
];

foreach ($trainingUsers as $u) {
    $user = User::updateOrCreate(
        ['email' => strtolower($u['email'])],
        ['name' => $u['name'], 'password' => bcrypt($u['password'])]
    );
    $user->syncRoles($u['roles']);
    echo "  [TRAINING] {$u['email']} -> roles: " . implode(', ', $u['roles']) . "\n";
}

// Clear all caches
app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

echo "\n=== All users & roles configured successfully ===\n";
echo "ADMIN panel users: " . count($adminUsers) . "\n";
echo "TRAINING panel users: " . count($trainingUsers) . "\n";
