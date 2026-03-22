<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ----- Permissions -----
        $permissions = [
            'training.access',
            'training.manage_external_links',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web']
            );
        }

        // ----- Roles -----

        // Super admin — full access to everything
        $superAdmin = Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'web']
        );

        // Admin — logistics / admin panel access (existing role)
        Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'web']
        );

        // Training admin — full training panel access
        $trainingAdmin = Role::firstOrCreate(
            ['name' => 'training_admin', 'guard_name' => 'web']
        );
        $trainingAdmin->syncPermissions([
            'training.access',
            'training.manage_external_links',
        ]);

        // Training viewer — read-only training panel access
        $trainingViewer = Role::firstOrCreate(
            ['name' => 'training_viewer', 'guard_name' => 'web']
        );
        $trainingViewer->syncPermissions([
            'training.access',
        ]);

        // Super admin gets all permissions
        $superAdmin->syncPermissions(Permission::all());
    }
}
