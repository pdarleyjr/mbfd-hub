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

    public function test_the_migration_creates_the_permissions_but_grants_them_to_nobody(): void
    {
        // The production deploy gate refuses role or permission changes on established accounts, so access
        // to Admin -> EMS is granted deliberately in the capability editor, never by a migration.
        $admin = $this->user(['admin.access']);
        $captain5Admin = $this->user(['admin.access', 'captain5.admin']);
        $roleOnly = $this->user();
        $roleOnly->assignRole(Role::findOrCreate('admin', 'web'));
        Role::findOrCreate('admin', 'web')->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $before = DB::table('model_has_permissions')->count();

        $this->runMigration();
        $this->runMigration(); // idempotent

        foreach (['admin.ems.view', 'admin.ems.manage'] as $name) {
            $this->assertSame(1, Permission::query()->where('name', $name)->where('guard_name', 'web')->count());
            foreach ([$admin, $captain5Admin, $roleOnly] as $user) {
                $this->assertFalse($user->fresh()->hasDirectWebPermission($name));
            }
        }
        $this->assertSame($before, DB::table('model_has_permissions')->count(), 'No account gains a permission from the migration.');
    }
}
