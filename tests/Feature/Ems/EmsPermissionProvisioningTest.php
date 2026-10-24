<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Support\ApplicationAccessRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class EmsPermissionProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $direct = []): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        foreach ($direct as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }

        return $user;
    }

    private function runMigration(): void
    {
        (require database_path('migrations/2026_10_24_100300_provision_ems_permissions.php'))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_the_permissions_exist_and_are_listed_in_the_capability_editor(): void
    {
        foreach (['admin.ems.view', 'admin.ems.manage'] as $name) {
            $this->assertTrue(Permission::query()->where('name', $name)->where('guard_name', 'web')->exists());
        }
        $options = app(ApplicationAccessRegistry::class)->capabilityOptions();
        $this->assertSame('EMS — view', $options['admin.ems.view']);
        $this->assertSame('EMS — manage', $options['admin.ems.manage']);
    }

    public function test_the_migration_grants_view_to_admin_access_holders_and_manage_to_captain5_admins_only(): void
    {
        $admin = $this->user(['admin.access']);
        $captain5Admin = $this->user(['admin.access', 'captain5.admin']);
        $captain5Only = $this->user(['captain5.admin']);
        $plain = $this->user();
        $roleOnly = $this->user();
        $roleOnly->assignRole(Role::findOrCreate('admin', 'web'));
        Role::findOrCreate('admin', 'web')->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        DB::table('model_has_permissions')->whereIn('permission_id', Permission::query()->whereIn('name', ['admin.ems.view', 'admin.ems.manage'])->pluck('id'))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->runMigration();
        $this->runMigration(); // idempotent

        $has = fn (User $u, string $p): bool => $u->fresh()->hasDirectWebPermission($p);
        $this->assertTrue($has($admin, 'admin.ems.view'));
        $this->assertFalse($has($admin, 'admin.ems.manage'));
        $this->assertTrue($has($captain5Admin, 'admin.ems.view'));
        $this->assertTrue($has($captain5Admin, 'admin.ems.manage'));
        $this->assertFalse($has($captain5Only, 'admin.ems.view'), 'Without admin.access there is no Admin -> EMS.');
        $this->assertTrue($has($captain5Only, 'admin.ems.manage'));
        $this->assertFalse($has($plain, 'admin.ems.view'));
        $this->assertFalse($has($roleOnly, 'admin.ems.view'), 'Only direct permissions count.');
        $this->assertSame(4, DB::table('model_has_permissions')->whereIn('permission_id', Permission::query()->whereIn('name', ['admin.ems.view', 'admin.ems.manage'])->pluck('id'))->count());
    }
}
