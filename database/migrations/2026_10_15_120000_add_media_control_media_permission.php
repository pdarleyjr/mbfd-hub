<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // Provision only. Super administrators grant it per member through the
        // employee access controls; nobody receives it automatically.
        DB::table('permissions')->insertOrIgnore([
            'name' => 'app.media_control.media',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Retain explicit rights; rollback must not silently erase authorization.
    }
};
