#!/bin/bash
# Fix Snipe-IT Users — Run on VPS as root
# Usage: bash /root/mbfd-hub/scripts/fix_snipeit_users.sh
#
# This script creates/updates the 5 admin users directly in Snipe-IT's MariaDB database.
# Snipe-IT uses bcrypt for password hashing (same as Laravel).
# We use Snipe-IT's artisan tinker to hash passwords properly.

set -e

echo "=== Creating/Updating Snipe-IT Admin Users ==="
echo ""

# Generate bcrypt hashes inside the Snipe-IT container
# Snipe-IT uses Laravel under the hood, so we can use artisan tinker

docker exec -i snipeit php artisan tinker --no-interaction <<'TINKER_EOF'

$users = [
    [
        'first_name' => 'Miguel',
        'last_name' => 'Anchia',
        'username' => 'miguelanchia@miamibeachfl.gov',
        'email' => 'miguelanchia@miamibeachfl.gov',
        'password' => 'Penco1',
        'permissions' => '{"superuser":"1"}',
    ],
    [
        'first_name' => 'Richard',
        'last_name' => 'Quintela',
        'username' => 'richardquintela@miamibeachfl.gov',
        'email' => 'richardquintela@miamibeachfl.gov',
        'password' => 'Penco2',
        'permissions' => '{"superuser":"1"}',
    ],
    [
        'first_name' => 'Peter',
        'last_name' => 'Darley',
        'username' => 'peterdarley@miamibeachfl.gov',
        'email' => 'peterdarley@miamibeachfl.gov',
        'password' => 'Penco3',
        'permissions' => '{"superuser":"1"}',
    ],
    [
        'first_name' => 'Grecia',
        'last_name' => 'Trabanino',
        'username' => 'greciatrabanino@miamibeachfl.gov',
        'email' => 'greciatrabanino@miamibeachfl.gov',
        'password' => 'MBFDSupport!',
        'permissions' => '{"superuser":"1"}',
    ],
    [
        'first_name' => 'Gerald',
        'last_name' => 'DeYoung',
        'username' => 'geralddeyoung@miamibeachfl.gov',
        'email' => 'geralddeyoung@miamibeachfl.gov',
        'password' => 'MBFDGerry1',
        'permissions' => '{"superuser":"1"}',
    ],
];

foreach ($users as $userData) {
    $pw = $userData['password'];
    unset($userData['password']);
    
    // Case-insensitive lookup by email
    $user = \App\Models\User::whereRaw('LOWER(email) = ?', [strtolower($userData['email'])])->first();
    
    if ($user) {
        $user->update([
            'password' => bcrypt($pw),
            'permissions' => $userData['permissions'],
            'activated' => 1,
        ]);
        echo "UPDATED: {$userData['email']} (ID: {$user->id})\n";
    } else {
        $user = new \App\Models\User();
        $user->first_name = $userData['first_name'];
        $user->last_name = $userData['last_name'];
        $user->username = $userData['username'];
        $user->email = $userData['email'];
        $user->password = bcrypt($pw);
        $user->permissions = $userData['permissions'];
        $user->activated = 1;
        $user->save();
        echo "CREATED: {$userData['email']} (ID: {$user->id})\n";
    }
}

echo "\n=== All Snipe-IT users processed ===\n";

// List all users
$all = \App\Models\User::all(['id', 'username', 'email', 'activated', 'permissions']);
foreach ($all as $u) {
    echo "{$u->id} | {$u->email} | activated={$u->activated} | perms={$u->permissions}\n";
}
TINKER_EOF

echo ""
echo "=== Snipe-IT user setup complete ==="
echo "Test login at: https://inventory.mbfdhub.com"
echo ""
echo "Credentials:"
echo "  miguelanchia@miamibeachfl.gov / Penco1"
echo "  richardquintela@miamibeachfl.gov / Penco2"
echo "  peterdarley@miamibeachfl.gov / Penco3"
echo "  greciatrabanino@miamibeachfl.gov / MBFDSupport!"
echo "  geralddeyoung@miamibeachfl.gov / MBFDGerry1"
