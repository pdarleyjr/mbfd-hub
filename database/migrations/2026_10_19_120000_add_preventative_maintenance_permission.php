<?php

declare(strict_types=1);

use App\Services\Security\FleetOperationsManagerAssignment;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Defines the preventative-maintenance permission the apparatus form already
 * checks but nothing ever created. Migrations provision no roles: the Fleet
 * Operations Manager role is created on its first audited assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate(FleetOperationsManagerAssignment::MAINTENANCE_PERMISSION, 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve audited grants during an application rollback.
    }
};
