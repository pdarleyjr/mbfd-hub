<?php

declare(strict_types=1);

use App\Services\Security\FleetOperationsManagerAssignment;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Provisions the "Fleet Operations Manager" role and the preventative-maintenance
 * permission the apparatus form already checks but nothing ever created.
 * Additive and idempotent: it adds missing rows only and assigns no one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissions = array_map(
            static fn (string $name): Permission => Permission::findOrCreate($name, 'web'),
            FleetOperationsManagerAssignment::rolePermissions(),
        );
        $role = Role::findOrCreate(FleetOperationsManagerAssignment::ROLE, 'web');
        $role->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve audited role assignments during an application rollback.
    }
};
