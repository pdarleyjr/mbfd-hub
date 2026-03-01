<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Test login via Auth::attempt
$result = Illuminate\Support\Facades\Auth::attempt([
    'email' => 'miguelanchia@miamibeachfl.gov',
    'password' => 'Penco1'
]);
echo 'Auth::attempt result: ' . ($result ? 'SUCCESS' : 'FAILED') . PHP_EOL;

// Also check canAccessPanel
if ($result) {
    $u = Illuminate\Support\Facades\Auth::user();
    echo 'User: ' . $u->name . PHP_EOL;
    echo 'Roles: ' . json_encode($u->getRoleNames()) . PHP_EOL;
    
    // Simulate panel access check
    $panels = Filament\Facades\Filament::getPanels();
    foreach ($panels as $panel) {
        echo 'Panel ' . $panel->getId() . ': canAccess=' . ($u->canAccessPanel($panel) ? 'YES' : 'NO') . PHP_EOL;
    }
}
