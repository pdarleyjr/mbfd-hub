<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use App\Services\Security\ApplicationAccessService;
use App\Support\ApplicationAccessRegistry;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ApplicationAccessAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_app_grant_and_revoke_are_audited_and_preserve_other_rights_and_identity(): void
    {
        [$actor, $target] = $this->members();
        $target->givePermissionTo(Permission::findOrCreate('training.access', 'web'));
        $target->assignRole(Role::findOrCreate('training_viewer', 'web'));
        $identity = $target->fresh()->getAttributes();
        $service = app(ApplicationAccessService::class);
        $service->syncApplications($actor, $target, ['bid', 'media_control'], 'Access-test-password!', 'Training duties');
        self::assertTrue($target->fresh()->hasCurrentBidEntitlement());
        self::assertTrue($target->fresh()->hasCurrentMediaControlEntitlement());
        self::assertFalse($target->fresh()->hasCurrentAdminPanelEntitlement());
        $service->syncApplications($actor, $target, ['bid'], 'Access-test-password!', 'Duties changed');
        self::assertFalse($target->fresh()->hasCurrentMediaControlEntitlement());
        self::assertTrue($target->fresh()->hasDirectWebPermission('training.access'));
        self::assertSame(['training_viewer'], $target->fresh()->getRoleNames()->all());
        self::assertSame($identity, $target->fresh()->getAttributes());
        $this->assertDatabaseHas('security_action_events', ['actor_user_id' => $actor->id, 'target_user_id' => $target->id, 'action' => 'change_application_access', 'result' => 'allowed', 'reason' => 'Duties changed']);
    }

    public function test_admin_capabilities_have_an_independent_allowlist_and_preserve_application_access(): void
    {
        [$actor, $target] = $this->members();
        $target->givePermissionTo(Permission::findOrCreate('app.bid.access', 'web'));
        app(ApplicationAccessService::class)->syncAdministrationCapabilities($actor, $target, ['admin.members.view'], 'Access-test-password!', 'Personnel review');
        self::assertTrue($target->fresh()->hasDirectWebPermission('admin.members.view'));
        self::assertTrue($target->fresh()->hasCurrentBidEntitlement());
        self::assertFalse($target->fresh()->hasCurrentAdminPanelEntitlement());
    }

    public function test_unsupported_integrations_cannot_be_granted(): void
    {
        [$actor, $target] = $this->members();
        $this->expectException(AuthorizationException::class);
        app(ApplicationAccessService::class)->syncApplications($actor, $target, ['cmd'], 'Access-test-password!', 'Forged unsupported request');
    }

    public function test_general_member_manager_cannot_delegate_apps_even_with_correct_password(): void
    {
        [$actor, $target] = $this->members();
        $actor->syncRoles([]);
        $actor->givePermissionTo(Permission::findOrCreate('admin.members.manage', 'web'));
        $this->expectException(AuthorizationException::class);
        app(ApplicationAccessService::class)->syncApplications($actor, $target, ['admin'], 'Access-test-password!', 'Forbidden grant');
    }

    public function test_disabled_and_stale_authorized_actor_cannot_grant(): void
    {
        [$actor, $target] = $this->members();
        $actor->load('roles');
        User::query()->whereKey($actor->id)->update(['account_status' => 'disabled']);
        $this->expectException(AuthorizationException::class);
        app(ApplicationAccessService::class)->syncApplications($actor, $target, ['bid'], 'Access-test-password!', 'Forbidden grant');
    }

    public function test_self_and_super_admin_inherited_rights_are_protected(): void
    {
        [$actor, $target] = $this->members();
        $service = app(ApplicationAccessService::class);
        foreach ([$actor, $target->assignRole(Role::findOrCreate('super_admin', 'web'))] as $protected) {
            try {
                $service->syncApplications($actor, $protected, [], 'Access-test-password!', 'Forbidden removal');
                self::fail('Protected access must not change.');
            } catch (AuthorizationException) {
                self::assertTrue($protected->fresh()->hasCurrentAdminPanelEntitlement());
            }
        }
    }

    public function test_wrong_password_is_denied_audited_and_not_stored(): void
    {
        [$actor, $target] = $this->members();
        try {
            app(ApplicationAccessService::class)->syncApplications($actor, $target, ['bid'], 'Never-store-this-wrong-password', 'Forbidden grant');
            self::fail('Password verification must be required.');
        } catch (AuthorizationException) {
            self::assertFalse($target->fresh()->hasCurrentBidEntitlement());
            $this->assertDatabaseHas('security_action_events', ['target_user_id' => $target->id, 'result' => 'denied']);
            self::assertStringNotContainsString('Never-store-this-wrong-password', \App\Models\SecurityActionEvent::all()->toJson());
        }
    }

    public function test_registry_reports_actual_direct_gate_and_pending_integrations_without_inherited_role_confusion(): void
    {
        [, $target] = $this->members();
        $role = Role::findOrCreate('test-role-with-app-permission', 'web');
        $role->givePermissionTo(Permission::findOrCreate('app.bid.access', 'web'));
        $target->assignRole($role);
        $registry = app(ApplicationAccessRegistry::class);
        self::assertFalse($registry->states($target)['bid']['allowed']);
        self::assertFalse($registry->states($target)['cmd']['operational']);
        self::assertFalse($registry->states($target)['cloud']['operational']);
    }

    public function test_profile_save_cannot_forge_status_or_raw_permissions_and_shows_friendly_access_actions(): void
    {
        [$actor, $target] = $this->members();
        $target->givePermissionTo(Permission::findOrCreate('training.access', 'web'));
        $permission = Permission::findOrCreate('app.media_control.access', 'web');
        $this->actingAs($actor);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->assertFormFieldDoesNotExist('permissions')
            ->assertActionExists('manageApplicationAccess')
            ->set('data.permissions', [$permission->id])
            ->set('data.account_status', 'disabled')
            ->call('save')
            ->assertHasNoFormErrors();
        self::assertSame('active', $target->fresh()->getRawOriginal('account_status'));
        self::assertFalse($target->fresh()->hasCurrentMediaControlEntitlement());
        self::assertTrue($target->fresh()->hasDirectWebPermission('training.access'));
    }

    public function test_actual_application_action_grants_only_explicit_supported_access(): void
    {
        [$actor, $target] = $this->members();
        $this->actingAs($actor);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->callAction('manageApplicationAccess', ['applications' => ['bid'], 'current_password' => 'Access-test-password!', 'reason' => 'Approved Bid access'])
            ->assertHasNoActionErrors();
        self::assertTrue($target->fresh()->hasCurrentBidEntitlement());
        self::assertFalse($target->fresh()->hasCurrentAdminPanelEntitlement());
        $this->assertDatabaseHas('security_action_events', ['target_user_id' => $target->id, 'action' => 'change_application_access', 'result' => 'allowed']);
    }

    public function test_wrong_password_stays_inline_in_both_access_dialogs_without_changes_and_can_be_corrected(): void
    {
        [$actor, $target] = $this->members();
        $this->actingAs($actor);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach (['manageApplicationAccess' => ['applications' => ['bid']], 'manageAdministrationCapabilities' => ['capabilities' => ['admin.members.view']]] as $action => $selection) {
            $before = $target->fresh()->permissions()->pluck('id')->all();
            $component = Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
                ->callAction($action, [...$selection, 'current_password' => 'Wrong-password-do-not-retain', 'reason' => 'Approved access'])
                ->assertHasActionErrors(['current_password'])
                ->assertSet('mountedActions', [$action])
                ->assertSet('mountedActionsData.0.current_password', '');
            self::assertSame($before, $target->fresh()->permissions()->pluck('id')->all());
            $this->assertDatabaseHas('security_action_events', ['target_user_id' => $target->id, 'result' => 'denied']);
            $component->set('mountedActionsData.0.current_password', 'Access-test-password!')
                ->call('callMountedAction')
                ->assertHasNoActionErrors()
                ->assertSet('mountedActionsData', []);
        }
        self::assertTrue($target->fresh()->hasCurrentBidEntitlement());
        self::assertTrue($target->fresh()->hasDirectWebPermission('admin.members.view'));
        self::assertStringNotContainsString('Wrong-password-do-not-retain', \App\Models\SecurityActionEvent::all()->toJson());
    }

    public function test_forged_cross_scope_keys_cannot_partially_grant_access(): void
    {
        [$actor, $target] = $this->members();
        try {
            app(ApplicationAccessService::class)->syncApplications($actor, $target, ['bid', 'admin.members.security'], 'Access-test-password!', 'Forged mixed scope');
            self::fail('Cross-scope keys must fail atomically.');
        } catch (AuthorizationException) {
            self::assertFalse($target->fresh()->hasCurrentBidEntitlement());
            self::assertFalse($target->fresh()->hasDirectWebPermission('admin.members.security'));
        }
    }

    public function test_actor_role_revocation_is_rechecked_instead_of_using_cached_roles(): void
    {
        [$actor, $target] = $this->members();
        $actor->load('roles');
        $actor->roles()->detach();
        $this->expectException(AuthorizationException::class);
        app(ApplicationAccessService::class)->syncApplications($actor, $target, ['bid'], 'Access-test-password!', 'Stale actor');
    }

    public function test_employee_id_links_to_authorized_edit_but_not_self(): void
    {
        [$actor, $target] = $this->members();
        $this->actingAs($actor);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::test(ListUsers::class);
        $column = $component->instance()->getTable()->getColumn('employee_id');
        self::assertSame(UserResource::getUrl('edit', ['record' => $target]), $column->record($target)->getUrl());
        self::assertNull($column->record($actor)->getUrl());
    }

    /** @return array{User, User} */
    private function members(): array
    {
        $actor = User::factory()->create(['password' => 'Access-test-password!', 'account_status' => 'active']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        $target = User::factory()->create(['employee_id' => 'ACCESS-TARGET', 'account_status' => 'active']);

        return [$actor, $target];
    }
}
