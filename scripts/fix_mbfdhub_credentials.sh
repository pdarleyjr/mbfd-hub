#!/bin/bash
# Fix MBFD Hub Credentials — Run on VPS as root
# Usage: bash /root/mbfd-hub/scripts/fix_mbfdhub_credentials.sh
#
# Sets correct passwords and roles for ALL dashboard users.
# Emails are matched case-insensitively.

set -e

echo "=== Fixing MBFD Hub Credentials (Logistics + Training) ==="
echo ""

docker compose exec -T laravel.test php artisan tinker --no-interaction <<'TINKER_EOF'

// Ensure roles exist
foreach (['super_admin', 'admin', 'logistics_admin', 'training_admin', 'training_viewer'] as $r) {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
}

echo "=== LOGISTICS ADMIN USERS ===\n";

$logisticsUsers = [
    'miguelanchia@miamibeachfl.gov' => ['pw' => 'Penco1', 'name' => 'Miguel Anchia', 'roles' => ['super_admin', 'admin']],
    'richardquintela@miamibeachfl.gov' => ['pw' => 'Penco2', 'name' => 'Richard Quintela', 'roles' => ['super_admin', 'admin']],
    'peterdarley@miamibeachfl.gov' => ['pw' => 'Penco3', 'name' => 'Peter Darley', 'roles' => ['super_admin', 'admin']],
    'greciatrabanino@miamibeachfl.gov' => ['pw' => 'MBFDSupport!', 'name' => 'Grecia Trabanino', 'roles' => ['super_admin', 'admin']],
    'geralddeyoung@miamibeachfl.gov' => ['pw' => 'MBFDGerry1', 'name' => 'Gerald DeYoung', 'roles' => ['super_admin', 'admin']],
];

foreach ($logisticsUsers as $email => $data) {
    $user = \App\Models\User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
    if (!$user) {
        $user = \App\Models\User::create([
            'name' => $data['name'],
            'email' => strtolower($email),
            'password' => \Illuminate\Support\Facades\Hash::make($data['pw']),
            'email_verified_at' => now(),
        ]);
        echo "CREATED: {$email}\n";
    } else {
        $user->update(['password' => \Illuminate\Support\Facades\Hash::make($data['pw'])]);
        echo "PASSWORD RESET: {$email}\n";
    }
    $user->syncRoles($data['roles']);
    echo "  Roles: " . $user->getRoleNames()->implode(', ') . "\n";
}

echo "\n=== TRAINING USERS ===\n";

$trainingUsers = [
    'danielgato@miamibeachfl.gov' => ['pw' => 'Gato1234!', 'name' => 'Daniel Gato', 'roles' => ['admin', 'training_admin']],
    'victorwhite@miamibeachfl.gov' => ['pw' => 'Vic1234!', 'name' => 'Victor White', 'roles' => ['admin', 'training_admin']],
    'claudionavas@miamibeachfl.gov' => ['pw' => 'Flea1234!', 'name' => 'Claudio Navas', 'roles' => ['admin', 'training_admin']],
    'michaelsica@miamibeachfl.gov' => ['pw' => 'Sica1234!', 'name' => 'Michael Sica', 'roles' => ['admin', 'training_admin']],
];

foreach ($trainingUsers as $email => $data) {
    $user = \App\Models\User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
    if (!$user) {
        $user = \App\Models\User::create([
            'name' => $data['name'],
            'email' => strtolower($email),
            'password' => \Illuminate\Support\Facades\Hash::make($data['pw']),
            'email_verified_at' => now(),
        ]);
        echo "CREATED: {$email}\n";
    } else {
        $user->update(['password' => \Illuminate\Support\Facades\Hash::make($data['pw'])]);
        echo "PASSWORD RESET: {$email}\n";
    }
    $user->syncRoles($data['roles']);
    echo "  Roles: " . $user->getRoleNames()->implode(', ') . "\n";
}

// Clear permission cache
app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
echo "\nPermission cache cleared.\n";

echo "\n=== FINAL USER LIST ===\n";
foreach (\App\Models\User::all() as $u) {
    echo "{$u->id} | {$u->email} | roles: [" . $u->getRoleNames()->implode(', ') . "]\n";
}

echo "\nDONE!\n";
TINKER_EOF

echo ""
echo "=== MBFD Hub credentials fixed ==="
echo ""
echo "Now clearing all caches..."
docker compose exec -T laravel.test php artisan optimize:clear
docker compose exec -T laravel.test php artisan permission:cache-reset
docker compose exec -T laravel.test php artisan config:cache
docker compose exec -T laravel.test php artisan route:cache
echo ""
echo "=== All caches cleared ==="
echo ""
echo "Logistics Admin credentials (https://www.mbfdhub.com/admin):"
echo "  miguelanchia@miamibeachfl.gov / Penco1"
echo "  richardquintela@miamibeachfl.gov / Penco2"
echo "  peterdarley@miamibeachfl.gov / Penco3"
echo "  greciatrabanino@miamibeachfl.gov / MBFDSupport!"
echo "  geralddeyoung@miamibeachfl.gov / MBFDGerry1"
echo ""
echo "Training credentials (https://www.mbfdhub.com/training):"
echo "  danielgato@miamibeachfl.gov / Gato1234!"
echo "  victorwhite@miamibeachfl.gov / Vic1234!"
echo "  claudionavas@miamibeachfl.gov / Flea1234!"
echo "  michaelsica@miamibeachfl.gov / Sica1234!"
