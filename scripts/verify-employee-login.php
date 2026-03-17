<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Employee;
use Illuminate\Support\Facades\Hash;

echo "=== Verify Employee Login ===\n\n";

$emp = Employee::where('employee_id', '20731')->first();
if (!$emp) {
    echo "ERROR: Employee 20731 not found!\n";
    exit(1);
}

echo "Found: {$emp->name} (ID: {$emp->employee_id}, Rank: {$emp->rank})\n";
echo "Must change password: " . ($emp->must_change_password ? 'YES' : 'NO') . "\n";
echo "Password check (MBFD1!): " . (Hash::check('MBFD1!', $emp->password) ? 'PASS' : 'FAIL') . "\n";

// Test auth attempt
$credentials = ['employee_id' => '20731', 'password' => 'MBFD1!'];
$result = auth('employee')->attempt($credentials);
echo "Auth attempt result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";

// Check total employees
$total = Employee::count();
echo "\nTotal employees in table: {$total}\n";

// Check that no @mbfd.local users exist in users table
$fakeUsers = \App\Models\User::where('email', 'like', '%@mbfd.local')->count();
echo "Fake @mbfd.local users in users table: {$fakeUsers}\n";

echo "\n✅ Verification complete\n";
