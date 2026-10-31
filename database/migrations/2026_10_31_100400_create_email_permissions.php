<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Email Center capabilities. Creates the three permissions and grants them to nobody: the production deploy
 * gate refuses any role or permission change on established accounts, so access is given deliberately in the
 * Admin capability editor. Until then MailAccess honours the older admin.communications.view and
 * admin.communications.send, so nobody who can read or send mail today loses that ability at deploy.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['admin.email.view', 'admin.email.send', 'admin.email.manage'];

    public function up(): void
    {
        $now = now();
        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Email capabilities are access records and are never removed outside local or testing.');
        }

        $ids = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', self::PERMISSIONS)->pluck('id');
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
