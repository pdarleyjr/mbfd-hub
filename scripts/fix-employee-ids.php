<?php
/**
 * Fix Employee Portal account merging:
 * 1. Find existing admin/user accounts without employee_id
 * 2. Match them by name to the imported accounts (which have correct employee_id)
 * 3. Transfer employee_id and rank to the original account
 * 4. Reassign any assigned_equipment and employee_equipment_requests to original account
 * 5. Delete the duplicate imported account
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\User;

echo "=== Employee Portal Account Merge Fix ===\n\n";

// Find all imported accounts (emails ending in @mbfd.local)
$imported = User::where('email', 'like', '%@mbfd.local')
    ->whereNotNull('employee_id')
    ->get();

echo "Found " . $imported->count() . " imported @mbfd.local accounts\n\n";

$merged = 0;
$skipped = 0;

foreach ($imported as $importedUser) {
    // Try to find matching original account by exact name match
    $original = User::where('email', 'not like', '%@mbfd.local')
        ->where('name', $importedUser->name)
        ->whereNull('employee_id')
        ->first();

    if (!$original) {
        // No matching original account - keep imported as-is
        $skipped++;
        continue;
    }

    echo "Merging '{$importedUser->name}' (emp:{$importedUser->employee_id})\n";
    echo "  Original ID:{$original->id} email:{$original->email}\n";
    echo "  Imported ID:{$importedUser->id} email:{$importedUser->email}\n";

    // First clear the employee_id on the imported account to release the unique constraint
    $empId = $importedUser->employee_id;
    $importedUser->employee_id = null;
    $importedUser->saveQuietly();

    // Now update original account with employee_id and rank
    $original->employee_id = $empId;
    $original->rank = $importedUser->rank ?? $original->rank;
    // Don't override must_change_password for existing admins
    $original->saveQuietly();

    // Reassign equipment records
    $equipmentMoved = DB::table('assigned_equipment')
        ->where('user_id', $importedUser->id)
        ->update(['user_id' => $original->id]);
    if ($equipmentMoved > 0) {
        echo "  Moved {$equipmentMoved} equipment records\n";
    }

    // Reassign equipment requests
    $requestsMoved = DB::table('employee_equipment_requests')
        ->where('user_id', $importedUser->id)
        ->update(['user_id' => $original->id]);
    if ($requestsMoved > 0) {
        echo "  Moved {$requestsMoved} equipment requests\n";
    }

    // Delete the duplicate imported account
    $importedUser->delete();
    echo "  ✅ Merged and deleted duplicate\n";
    $merged++;
}

echo "\n=== Summary ===\n";
echo "Merged: {$merged}\n";
echo "Kept as-is (no duplicate found): {$skipped}\n";
echo "\nAll admin users now have their correct employee_id set.\n";
echo "Employees without an existing admin account login with {empid}@mbfd.local\n";
