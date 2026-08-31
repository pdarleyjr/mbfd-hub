<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate('view_queue_status', 'web');
        $superAdmin = Role::query()
            ->where('name', 'super_admin')
            ->where('guard_name', 'web')
            ->first();

        $superAdmin?->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->where('name', 'view_queue_status')
            ->where('guard_name', 'web')
            ->first()
            ?->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
