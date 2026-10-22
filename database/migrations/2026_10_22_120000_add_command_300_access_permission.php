<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // Explicit 300 Command Access grant for members who do not qualify by
        // rank, 300 assignment, or administrator status. Never auto-granted.
        Permission::findOrCreate('command.300.access', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve audited grants during an application rollback.
    }
};
