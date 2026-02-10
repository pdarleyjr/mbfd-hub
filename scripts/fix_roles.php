<?php
use App\Models\User;
use Spatie\Permission\Models\Role;

// Ensure roles exist
foreach(['admin','super_admin','training_admin','training_viewer'] as $r) {
    Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
}

// Fix Peter - remove training_admin, ensure admin+super_admin
$peter = User::where('email', 'peterdarley@miamibeachfl.gov')->first();
if ($peter) {
    $peter->syncRoles(['admin', 'super_admin']);
    echo 'Peter: ' . implode(',', $peter->getRoleNames()->toArray()) . PHP_EOL;
}

// Fix Gerald - remove training_viewer, assign admin+super_admin
$gerald = User::where('email', 'geralddeyoung@miamibeachfl.gov')->first();
if ($gerald) {
    $gerald->syncRoles(['admin', 'super_admin']);
    echo 'Gerald: ' . implode(',', $gerald->getRoleNames()->toArray()) . PHP_EOL;
}

// Create/find ClaudioNavas (case-insensitive)
$claudio = User::whereRaw('LOWER(email) = ?', ['claudionavas@miamibeachfl.gov'])->first();
if (!$claudio) {
    $claudio = User::create(['email' => 'ClaudioNavas@miamibeachfl.gov', 'name' => 'Claudio Navas', 'password' => bcrypt('Flea1234!')]);
}
$claudio->syncRoles(['training_admin']);
echo 'Claudio: ' . implode(',', $claudio->getRoleNames()->toArray()) . PHP_EOL;

// Create/find GreciaTrabanino (case-insensitive)
$grecia = User::whereRaw('LOWER(email) = ?', ['greciatrabanino@miamibeachfl.gov'])->first();
if (!$grecia) {
    $grecia = User::create(['email' => 'GreciaTrabanino@miamibeachfl.gov', 'name' => 'Grecia Trabanino', 'password' => bcrypt('MBFDSupport!')]);
}
$grecia->syncRoles(['training_admin']);
echo 'Grecia: ' . implode(',', $grecia->getRoleNames()->toArray()) . PHP_EOL;

// Verify all
echo PHP_EOL . '=== FINAL VERIFICATION ===' . PHP_EOL;
echo 'LOGISTICS:' . PHP_EOL;
foreach(['MiguelAnchia@miamibeachfl.gov','RichardQuintela@miamibeachfl.gov','peterdarley@miamibeachfl.gov','geralddeyoung@miamibeachfl.gov'] as $e) {
    $u = User::whereRaw('LOWER(email) = ?', [strtolower($e)])->first();
    echo $e . ': ' . ($u ? implode(',', $u->getRoleNames()->toArray()) : 'NOT FOUND') . PHP_EOL;
}
echo 'TRAINING:' . PHP_EOL;
foreach(['danielgato@miamibeachfl.gov','victorwhite@miamibeachfl.gov','ClaudioNavas@miamibeachfl.gov','michaelsica@miamibeachfl.gov','GreciaTrabanino@miamibeachfl.gov'] as $e) {
    $u = User::whereRaw('LOWER(email) = ?', [strtolower($e)])->first();
    echo $e . ': ' . ($u ? implode(',', $u->getRoleNames()->toArray()) : 'NOT FOUND') . PHP_EOL;
}
