<?php
use App\Models\User;

foreach(['MiguelAnchia@miamibeachfl.gov','RichardQuintela@miamibeachfl.gov'] as $e) {
    $u = User::whereRaw('LOWER(email) = ?', [strtolower($e)])->first();
    if ($u) {
        $u->syncRoles(['admin', 'super_admin']);
        echo $e . ': ' . implode(',', $u->getRoleNames()->toArray()) . PHP_EOL;
    }
}

// Also fix Peter and Gerald to have admin too
foreach(['peterdarley@miamibeachfl.gov','geralddeyoung@miamibeachfl.gov'] as $e) {
    $u = User::whereRaw('LOWER(email) = ?', [strtolower($e)])->first();
    if ($u) {
        echo $e . ': ' . implode(',', $u->getRoleNames()->toArray()) . PHP_EOL;
    }
}

echo PHP_EOL . 'TRAINING:' . PHP_EOL;
foreach(['danielgato@miamibeachfl.gov','victorwhite@miamibeachfl.gov','ClaudioNavas@miamibeachfl.gov','michaelsica@miamibeachfl.gov','GreciaTrabanino@miamibeachfl.gov'] as $e) {
    $u = User::whereRaw('LOWER(email) = ?', [strtolower($e)])->first();
    echo $e . ': ' . ($u ? implode(',', $u->getRoleNames()->toArray()) : 'NOT FOUND') . PHP_EOL;
}
