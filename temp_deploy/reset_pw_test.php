<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$u = App\Models\User::where('email', 'peterdarley@miamibeachfl.gov')->first();
if ($u) {
    $u->password = bcrypt('Penco3');
    $u->must_change_password = false;
    $u->save();
    echo "Password reset for {$u->name} ({$u->email})\n";
} else {
    echo "User not found\n";
}