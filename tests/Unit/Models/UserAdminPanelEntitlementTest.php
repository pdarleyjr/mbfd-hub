<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserAdminPanelEntitlementTest extends TestCase
{
    public function test_current_admin_panel_entitlement_uses_the_existing_panel_roles_and_is_not_sticky(): void
    {
        $user = new User;
        $user->setRelation('roles', new Collection([
            new Role(['name' => 'training_viewer', 'guard_name' => 'web']),
        ]));

        $this->assertTrue($user->hasCurrentAdminPanelEntitlement());

        $user->setRelation('roles', new Collection);

        $this->assertFalse($user->hasCurrentAdminPanelEntitlement());
    }

    public function test_non_admin_panel_roles_do_not_grant_bid_admin_entitlement(): void
    {
        $user = new User;
        $user->setRelation('roles', new Collection([
            new Role(['name' => 'workgroup_admin', 'guard_name' => 'web']),
        ]));

        $this->assertFalse($user->hasCurrentAdminPanelEntitlement());
    }
}
