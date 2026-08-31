<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('view_queue_status', 'web');
        $superAdmin = Role::findOrCreate('super_admin', 'web');

        $superAdmin->givePermissionTo($permission);
    }

    public function down(): void
    {
        $permission = Permission::findByName('view_queue_status', 'web');
        $permission->delete();
    }
};
