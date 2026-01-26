<?php
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make("Illuminate\Contracts\Console\Kernel")->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$updates = [
    ["email" => "miguelanchia@miamibeachfl.gov", "password" => "Penco1"],
    ["email" => "richardquintela@miamibeachfl.gov", "password" => "Penco2"],
    ["email" => "peterdarley@miamibeachfl.gov", "password" => "Penco3"],
    ["email" => "geralddeyoung@miamibeachfl.gov", "password" => "MBFDGerry1"],
];

foreach ($updates as $u) {
    $count = User::whereRaw("LOWER(email) = ?", [strtolower($u["email"])])->update(["password" => Hash::make($u["password"])]);
    echo "Updated {$u['email']}: $count rows\n";
}
echo "Done!\n";
