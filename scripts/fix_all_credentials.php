// Fix credentials and roles per spec
// Run via: php artisan tinker < scripts/fix_all_credentials.php

// Fix Grecia - should be admin not training_admin
$g = App\Models\User::find(6);
if ($g) { $g->syncRoles(['admin', 'super_admin']); echo "Grecia roles: " . $g->getRoleNames()->implode(',') . PHP_EOL; }

// Fix Gerald - should be user only (no admin)
$d = App\Models\User::find(4);
if ($d) { $d->syncRoles([]); echo "Gerald roles: none (user)" . PHP_EOL; }

// Set all passwords
$passwords = [
    'miguelanchia@miamibeachfl.gov' => 'Penco1',
    'richardquintela@miamibeachfl.gov' => 'Penco2',
    'peterdarley@miamibeachfl.gov' => 'Penco3',
    'greciatrabanino@miamibeachfl.gov' => 'MBFDSupport!',
    'geralddeyoung@miamibeachfl.gov' => 'MBFDGerry1',
    'danielgato@miamibeachfl.gov' => 'Gato1234!',
    'victorwhite@miamibeachfl.gov' => 'Vic1234!',
    'claudionavas@miamibeachfl.gov' => 'Flea1234!',
    'michaelsica@miamibeachfl.gov' => 'Sica1234!',
];

foreach ($passwords as $email => $pw) {
    $updated = App\Models\User::whereRaw('LOWER(email) = ?', [strtolower($email)])->update(['password' => bcrypt($pw)]);
    echo "Updated {$email}: {$updated} row(s)" . PHP_EOL;
}

echo "Done!" . PHP_EOL;
