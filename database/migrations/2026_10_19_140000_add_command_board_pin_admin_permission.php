<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Defines app.cmd.admin, the right to set and change the Command Board PIN
 * from the Hub admin panel. Provision only: no role is created and nobody
 * receives it automatically. A Super Administrator grants it per member as a
 * direct Hub capability in the employee access controls.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'name' => 'app.cmd.admin',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Retain explicit rights; rollback must not silently erase authorization.
    }
};
