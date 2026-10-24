<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin -> EMS capabilities (docs/ems/ems-inventory-contract.md section 7). Additive only.
 *
 * Creates `admin.ems.view` and `admin.ems.manage` and grants them to nobody: the production deploy
 * gate refuses any role or permission change on established accounts, so access is given
 * deliberately in the Admin capability editor. Super administrators pass Gate::before.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (['admin.ems.view', 'admin.ems.manage'] as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('EMS permissions are never removed outside local or testing.');
        }
        $ids = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', ['admin.ems.view', 'admin.ems.manage'])->pluck('id');
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
