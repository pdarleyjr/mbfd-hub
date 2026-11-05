<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Enums\AccountStatus;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Shared setup for the Support Services Automation admin screen tests. */
abstract class AdminScreensTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @param list<string> $permissions */
    protected function admin(bool $super = false, array $permissions = []): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active, 'name' => 'Pat Example', 'display_name' => 'Pat Example', 'must_change_password' => false]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        if ($super) {
            $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    protected function actingAsSuperAdmin(): User
    {
        $user = $this->admin(super: true);
        $this->actingAs($user);

        return $user;
    }

    /** @return list<list<string>> permission sets that must NOT open the screens */
    public static function deniedPermissionSets(): array
    {
        return [[['admin.access']], [['admin.ai_automation.manage']], [[]]];
    }
}
