<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserAdminPanelEntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_admin_panel_entitlement_uses_explicit_permission_and_is_not_sticky(): void
    {
        Permission::findOrCreate('admin.access', 'web');
        $user = User::factory()->create();
        $user->givePermissionTo('admin.access');

        $this->assertTrue($user->hasCurrentAdminPanelEntitlement());

        $user->revokePermissionTo('admin.access');

        $this->assertFalse($user->hasCurrentAdminPanelEntitlement());
    }

    public function test_admin_permission_does_not_grant_bid_entitlement(): void
    {
        Permission::findOrCreate('admin.access', 'web');
        Permission::findOrCreate('app.bid.access', 'web');
        $user = User::factory()->create();
        $user->givePermissionTo('admin.access');

        $this->assertTrue($user->hasCurrentAdminPanelEntitlement());
        $this->assertFalse($user->hasCurrentBidEntitlement());
    }

    public function test_ordinary_role_permissions_cannot_replace_direct_entitlements(): void
    {
        $adminAccess = Permission::findOrCreate('admin.access', 'web');
        $mediaAccess = Permission::findOrCreate('app.media_control.access', 'web');
        $bidAccess = Permission::findOrCreate('app.bid.access', 'web');
        $role = Role::findOrCreate('admin', 'web');
        $role->givePermissionTo([$adminAccess, $mediaAccess, $bidAccess]);
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertFalse($user->hasCurrentAdminPanelEntitlement());
        $this->assertFalse($user->hasCurrentMediaControlEntitlement());
        $this->assertFalse($user->hasCurrentBidEntitlement());
    }
}
