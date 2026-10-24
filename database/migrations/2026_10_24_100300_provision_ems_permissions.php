<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin -> EMS capabilities (docs/ems/ems-inventory-contract.md section 7). Additive only.
 *
 * `admin.ems.view` goes to every account that holds the direct `admin.access` permission today and
 * `admin.ems.manage` to every account that holds the direct `captain5.admin` permission, so nobody
 * who could already administer Captain 5 loses access. Super administrators pass Gate::before.
 */
return new class extends Migration
{
    private const USER = 'App\\Models\\User';

    public function up(): void
    {
        $now = now();
        foreach (['admin.ems.view', 'admin.ems.manage'] as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $this->grant('admin.access', 'admin.ems.view');
        $this->grant('captain5.admin', 'admin.ems.manage');

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

    private function grant(string $holds, string $gets): void
    {
        $heldId = DB::table('permissions')->where('guard_name', 'web')->where('name', $holds)->value('id');
        $getId = DB::table('permissions')->where('guard_name', 'web')->where('name', $gets)->value('id');
        if ($heldId === null || $getId === null) {
            return;
        }

        DB::table('model_has_permissions')
            ->where('permission_id', $heldId)->where('model_type', self::USER)
            ->pluck('model_id')
            ->each(function (int|string $userId) use ($getId): void {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => $getId, 'model_type' => self::USER, 'model_id' => $userId,
                ]);
            });
    }
};
