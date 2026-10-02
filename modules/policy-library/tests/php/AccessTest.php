<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Tests;

use Mbfd\PolicyLibrary\Support\LibraryAccess;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class AccessTest extends TestCase
{
    public function test_domain_routes_do_not_replace_hub_root(): void
    {
        $this->getJson('https://mbfdhub.com/api/manuals')->assertNotFound();
        $this->getJson('https://files.mbfdhub.com/api/manuals')->assertUnauthorized()->assertJsonPath('redirect', '/login');
    }

    public function test_pin_gate_is_bound_to_identity_and_expires(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $this->getJson('https://files.mbfdhub.com/api/manuals')->assertForbidden()->assertJsonPath('redirect', '/access');
        $this->withSession(['policy-library.access' => $this->accessGrant($user)])->getJson('https://files.mbfdhub.com/api/manuals')->assertOk()->assertJsonPath('can_manage', false);
        $this->withSession(['policy-library.access' => $this->accessGrant($user, -1)])->getJson('https://files.mbfdhub.com/api/manuals')->assertForbidden();
        $this->withSession(['policy-library.access' => ['user_id' => '999', 'expires_at' => now()->timestamp + 3600, 'pin_version' => hash('sha256', config('policy-library.pin_hash'))]])->getJson('https://files.mbfdhub.com/api/manuals')->assertForbidden();
    }

    public function test_pin_success_never_grants_management_and_pin_rotation_revokes_access(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $this->post('https://files.mbfdhub.com/access', ['pin' => 'test-library-pin'])->assertRedirect('/');
        $this->getJson('https://files.mbfdhub.com/api/manuals')->assertOk()->assertJsonPath('can_manage', false);
        $this->get('https://files.mbfdhub.com/manage')->assertForbidden();
        config(['policy-library.pin_hash' => password_hash('rotated-test-pin', PASSWORD_BCRYPT)]);
        $this->getJson('https://files.mbfdhub.com/api/manuals')->assertForbidden();
    }

    public function test_pin_is_throttled_and_failure_preserves_denial(): void
    {
        $this->actingAs($this->user());
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('https://files.mbfdhub.com/access', ['pin' => 'invalid'])->assertSessionHasErrors('pin')->assertSessionMissing('_old_input.pin');
        }
        $this->post('https://files.mbfdhub.com/access', ['pin' => 'test-library-pin'])->assertSessionHasErrors('pin');
        $this->getJson('https://files.mbfdhub.com/api/manuals')->assertForbidden();
    }

    public function test_admin_authority_uses_current_entitlements_or_dedicated_permission(): void
    {
        $user = $this->user();
        $role = Role::findOrCreate('admin', 'web');
        $user->assignRole($role);
        $this->assertFalse(LibraryAccess::canManage($user));
        Permission::findOrCreate('admin.access', 'web');
        $user->givePermissionTo('admin.access');
        $this->assertTrue(LibraryAccess::canManage($user));
        $this->actingAs($user)->get('https://files.mbfdhub.com/manage')->assertRedirect();
        $user->revokePermissionTo('admin.access');
        $user->givePermissionTo('files.manage');
        $this->assertTrue(LibraryAccess::canManage($user));
        $this->actingAs($user)->get('https://files.mbfdhub.com/manage')->assertRedirect();
        $this->get('https://mbfdhub.com/manage')->assertNotFound();
    }

    public function test_pin_is_fail_closed_without_configuration(): void
    {
        config(['policy-library.pin_hash' => null]);
        $this->actingAs($this->user())->post('https://files.mbfdhub.com/access', ['pin' => 'test-library-pin'])->assertStatus(503);
    }
}
