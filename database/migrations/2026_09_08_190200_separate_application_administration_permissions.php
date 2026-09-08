<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['app.bid.admin', 'app.media_control.admin'] as $permission) {
            DB::table('permissions')->insertOrIgnore(['name' => $permission, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        }
        $ids = DB::table('permissions')->where('guard_name', 'web')
            ->whereIn('name', ['app.bid.access', 'admin.access', 'app.bid.admin'])->pluck('id', 'name');
        // Preserve only the existing effective Bid-admin intersection. No app
        // access is granted, and future Hub panel grants do not inherit Bid admin.
        if (isset($ids['app.bid.access'], $ids['admin.access'], $ids['app.bid.admin'])) {
            $users = DB::table('model_has_permissions')->where('model_type', User::class)
                ->whereIn('permission_id', [$ids['app.bid.access'], $ids['admin.access']])
                ->groupBy('model_id')->havingRaw('COUNT(DISTINCT permission_id) = 2')->pluck('model_id');
            foreach ($users as $id) {
                DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $ids['app.bid.admin'], 'model_type' => User::class, 'model_id' => $id]);
            }
        }
        // Media local administrator roles cannot be inferred from Hub access.
        // Super Administrators retain their explicitly inherited app roles.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Retain explicit rights; rollback must not silently erase authorization.
    }
};
