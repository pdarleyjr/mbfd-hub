<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\Workgroup\Pages\CreateWorkgroupMember;
use App\Filament\Resources\Workgroup\Pages\EditWorkgroupMember;
use App\Filament\Resources\Workgroup\Pages\ListWorkgroupMembers;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PrivilegedCredentialAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutVite();
    }

    public function test_admin_cannot_reset_a_super_admin_through_ui_or_forged_table_action(): void
    {
        $actor = $this->adminWithUserManagementAccess();
        $target = User::factory()->create([
            'employee_id' => 'FORGED-PASSWORD-100',
            'password' => Hash::make('original-password'),
        ]);
        $target->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListUsers::class)
            ->assertTableActionDoesNotExist('resetPassword', record: $target)
            ->call('mountTableAction', 'resetPassword', (string) $target->getKey())
            ->assertSet('mountedTableActions', []);

        self::assertTrue(Hash::check('original-password', $target->fresh()->password));
    }

    public function test_admin_edit_form_cannot_accept_a_forged_password_field(): void
    {
        $actor = $this->adminWithUserManagementAccess();
        $target = User::factory()->create([
            'employee_id' => 'FORGED-PASSWORD-200',
            'password' => Hash::make('original-password'),
        ]);

        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->assertFormFieldDoesNotExist('password')
            ->fillForm([
                'name' => 'Updated Display Name',
                'password' => 'forged-password-value',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $target->refresh();

        self::assertSame('Updated Display Name', $target->name);
        self::assertTrue(Hash::check('original-password', $target->password));
    }

    public function test_owner_approved_administrative_user_creation_requires_members_manage_permission(): void
    {
        $actor = $this->adminWithUserManagementAccess();
        $actor->givePermissionTo(Permission::findOrCreate('create_user', 'web'));

        $this->actingAs($actor);

        self::assertTrue(UserResource::canCreate());
    }

    public function test_workgroup_manager_cannot_reset_a_same_group_super_admin_by_forging_an_action(): void
    {
        [$manager, $targetMembership] = $this->managedWorkgroupWithPrivilegedTarget();
        $target = $targetMembership->user;
        $target->forceFill(['password' => Hash::make('original-password')])->save();

        $this->actingAs($manager);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListWorkgroupMembers::class)
            ->assertTableActionDoesNotExist('setPassword', record: $targetMembership)
            ->call('mountTableAction', 'setPassword', (string) $targetMembership->getKey())
            ->assertSet('mountedTableActions', []);

        self::assertTrue(Hash::check('original-password', $target->fresh()->password));
    }

    public function test_workgroup_manager_cannot_create_a_global_user_account(): void
    {
        [$manager, $targetMembership] = $this->managedWorkgroupWithPrivilegedTarget();

        $this->actingAs($manager);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateWorkgroupMember::class)
            ->assertFormFieldDoesNotExist('create_new_user')
            ->assertFormFieldDoesNotExist('new_user_name')
            ->assertFormFieldDoesNotExist('new_user_email')
            ->assertFormFieldDoesNotExist('new_user_password');

        self::assertSame(2, User::query()->count());
        self::assertNotNull($targetMembership->user);
    }

    public function test_workgroup_manager_can_still_manage_legitimate_membership_fields(): void
    {
        [$manager, $targetMembership] = $this->managedWorkgroupWithPrivilegedTarget();

        $this->actingAs($manager);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditWorkgroupMember::class, ['record' => $targetMembership->getRouteKey()])
            ->fillForm([
                'user_id' => $targetMembership->user_id,
                'workgroup_id' => $targetMembership->workgroup_id,
                'role' => 'facilitator',
                'is_active' => true,
                'count_evaluations' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $targetMembership->refresh();

        self::assertSame('facilitator', $targetMembership->role);
        self::assertFalse($targetMembership->count_evaluations);
    }

    private function adminWithUserManagementAccess(): User
    {
        $adminRole = Role::findOrCreate('admin', 'web');
        $adminRole->givePermissionTo([
            Permission::findOrCreate('view_any_user', 'web'),
            Permission::findOrCreate('view_user', 'web'),
            Permission::findOrCreate('update_user', 'web'),
        ]);

        $actor = User::factory()->create();
        $actor->assignRole($adminRole);
        $actor->givePermissionTo([
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.members.view', 'web'),
            Permission::findOrCreate('admin.members.manage', 'web'),
        ]);

        return $actor;
    }

    /** @return array{User, WorkgroupMember} */
    private function managedWorkgroupWithPrivilegedTarget(): array
    {
        $manager = User::factory()->create();
        $manager->assignRole(Role::findOrCreate('workgroup_member', 'web'));

        $target = User::factory()->create();
        $target->assignRole(Role::findOrCreate('super_admin', 'web'));

        $workgroup = Workgroup::create([
            'name' => 'Credential boundary workgroup',
            'created_by' => $manager->id,
        ]);

        WorkgroupMember::create([
            'workgroup_id' => $workgroup->id,
            'user_id' => $manager->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $targetMembership = WorkgroupMember::create([
            'workgroup_id' => $workgroup->id,
            'user_id' => $target->id,
            'role' => 'member',
            'is_active' => true,
        ]);

        return [$manager, $targetMembership];
    }
}
