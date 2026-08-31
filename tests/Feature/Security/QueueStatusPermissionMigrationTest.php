<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class QueueStatusPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_and_repeated_migration_defines_permission_without_provisioning_roles(): void
    {
        self::assertTrue($this->permissionExists());
        self::assertSame(0, Role::query()->count());

        $this->migration()->up();

        self::assertSame(1, Permission::query()
            ->where('name', 'view_queue_status')
            ->where('guard_name', 'web')
            ->count());
        self::assertSame(0, Role::query()->count());
    }

    public function test_repeated_migration_grants_permission_to_an_existing_super_admin(): void
    {
        $superAdmin = Role::findOrCreate('super_admin', 'web');

        $this->migration()->up();

        self::assertSame(1, Role::query()->count());
        self::assertTrue($superAdmin->fresh()->hasPermissionTo('view_queue_status'));
    }

    public function test_rollback_removes_only_the_permission_and_preserves_business_roles(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $migration = $this->migration();
        $migration->up();

        $migration->down();

        self::assertFalse($this->permissionExists());
        self::assertTrue(Role::query()
            ->where('name', 'super_admin')
            ->where('guard_name', 'web')
            ->exists());
    }

    private function permissionExists(): bool
    {
        return Permission::query()
            ->where('name', 'view_queue_status')
            ->where('guard_name', 'web')
            ->exists();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_31_120000_add_view_queue_status_permission.php');
    }
}
