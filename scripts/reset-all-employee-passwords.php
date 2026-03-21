<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

echo "=== Reset ALL Employee Passwords ===\n\n";

$defaultPassword = env('DEFAULT_EMPLOYEE_PASSWORD', 'changeme');
$password = Hash::make($defaultPassword);
echo "Generated hash: " . substr($password, 0, 30) . "...\n";
echo "Verify check: " . (Hash::check($defaultPassword, $password) ? 'PASS' : 'FAIL') . "\n\n";

// Bypass Eloquent entirely — direct DB update
$updated = DB::table('employees')->update([
    'password' => $password,
    'must_change_password' => true,
    'updated_at' => now(),
]);

echo "Updated {$updated} employees\n";

// Verify one record
$peter = DB::table('employees')->where('employee_id', '20731')->first();
echo "Peter Darley check: " . (Hash::check($defaultPassword, $peter->password) ? 'PASS' : 'FAIL') . "\n";
echo "must_change_password: " . ($peter->must_change_password ? 'YES' : 'NO') . "\n";

// Test auth
$result = auth('employee')->attempt(['employee_id' => '20731', 'password' => $defaultPassword]);
echo "Auth attempt: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
auth('employee')->logout();

echo "\n✅ All employees reset to default password\n";
