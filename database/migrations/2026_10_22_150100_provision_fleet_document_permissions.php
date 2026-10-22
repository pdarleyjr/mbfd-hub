<?php

declare(strict_types=1);

use App\Services\Security\FleetOperationsManagerAssignment;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = collect(FleetOperationsManagerAssignment::DOCUMENT_PERMISSIONS)
            ->map(fn (string $name): Permission => Permission::findOrCreate($name, 'web'));

        // Extend the established role without changing any member's assignments
        // or direct grants. Document-specific checks honor these role permissions.
        Role::query()->where('name', FleetOperationsManagerAssignment::ROLE)->where('guard_name', 'web')
            ->first()?->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Access grants are retained on application rollback; records remain private.
    }
};
