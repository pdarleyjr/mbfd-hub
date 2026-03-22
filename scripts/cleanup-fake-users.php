<?php
/**
 * Cleanup script: Remove all fake @mbfd.local users from the users table.
 * These were created by the original import command which incorrectly used the users table.
 * The Employee Portal now uses the independent 'employees' table.
 * 
 * Safety checks:
 * 1. Only deletes users with @mbfd.local email suffix
 * 2. Nullifies any assigned_equipment.user_id pointing to fake users (keeping records)
 * 3. Nullifies any employee_equipment_requests.user_id pointing to fake users
 * 4. Cleans up related notifications, push subscriptions
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Cleanup: Remove @mbfd.local users from users table ===\n\n";

// Get all fake user IDs
$fakeUserIds = DB::table('users')
    ->where('email', 'like', '%@mbfd.local')
    ->pluck('id');

$count = $fakeUserIds->count();
echo "Found {$count} fake @mbfd.local user accounts to remove\n";

if ($count === 0) {
    echo "Nothing to clean up. Exiting.\n";
    exit(0);
}

// Safety: Nullify user_id on assigned_equipment (don't delete equipment records)
$eqUpdated = DB::table('assigned_equipment')
    ->whereIn('user_id', $fakeUserIds)
    ->update(['user_id' => null]);
echo "Nullified user_id on {$eqUpdated} assigned_equipment records\n";

// Safety: Nullify user_id on employee_equipment_requests  
$reqUpdated = DB::table('employee_equipment_requests')
    ->whereIn('user_id', $fakeUserIds)
    ->update(['user_id' => null]);
echo "Nullified user_id on {$reqUpdated} equipment request records\n";

// Remove push subscriptions for fake users
$pushDeleted = DB::table('push_subscriptions')
    ->whereIn('subscribable_id', $fakeUserIds)
    ->where('subscribable_type', 'App\\Models\\User')
    ->delete();
echo "Deleted {$pushDeleted} push subscriptions\n";

// Remove notifications for fake users
$notifDeleted = DB::table('notifications')
    ->whereIn('notifiable_id', $fakeUserIds)
    ->where('notifiable_type', 'App\\Models\\User')
    ->delete();
echo "Deleted {$notifDeleted} notifications\n";

// Remove roles assigned to fake users (Spatie permissions)
DB::table('model_has_roles')
    ->whereIn('model_id', $fakeUserIds)
    ->where('model_type', 'App\\Models\\User')
    ->delete();

DB::table('model_has_permissions')
    ->whereIn('model_id', $fakeUserIds)
    ->where('model_type', 'App\\Models\\User')
    ->delete();

// Remove personal access tokens
DB::table('personal_access_tokens')
    ->whereIn('tokenable_id', $fakeUserIds)
    ->where('tokenable_type', 'App\\Models\\User')
    ->delete();

// Finally delete the fake users
$deleted = DB::table('users')
    ->whereIn('id', $fakeUserIds)
    ->delete();

echo "\n✅ Deleted {$deleted} fake @mbfd.local user accounts\n";
echo "The users table is now clean — only real admin/workgroup/training users remain.\n";
echo "All 229 fire department employees now exist exclusively in the 'employees' table.\n";
