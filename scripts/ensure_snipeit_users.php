<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$emails = [
    'MiguelAnchia@miamibeachfl.gov',
    'RichardQuintela@miamibeachfl.gov',
    'PeterDarley@miamibeachfl.gov',
    'GreciaTrabanino@miamibeachfl.gov',
    'geralddeyoung@miamibeachfl.gov'
];

foreach ($emails as $email) {
    $user = \App\Models\User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
    if ($user) {
        if (!$user->hasRole('super_admin') && !$user->hasRole('admin')) {
            $user->assignRole('admin');
            echo "Assigned admin to {$email}\n";
        } else {
            echo "{$email} already has admin/super_admin role\n";
        }
    } else {
        echo "User not found: {$email}\n";
        // Create the user if not found
        $user = \App\Models\User::create([
            'name' => explode('@', $email)[0],
            'email' => $email,
            'password' => bcrypt('password123'), // Default password
        ]);
        $user->assignRole('admin');
        echo "Created user and assigned admin to {$email}\n";
    }
}
