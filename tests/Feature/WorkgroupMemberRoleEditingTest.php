<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Workgroup\Pages\EditWorkgroupMember;
use App\Filament\Resources\Workgroup\Pages\ViewWorkgroup;
use App\Filament\Resources\Workgroup\RelationManagers\MembersRelationManager;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class WorkgroupMemberRoleEditingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_can_edit_a_role_from_the_workgroup_members_tab_without_changing_login_identity(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        $workgroup = $this->workgroup($admin);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $membership = $this->membership($workgroup, $user);
        $otherMembership = $this->membership($this->workgroup($admin), $user);
        $identity = $user->only(['id', 'employee_id', 'password']);
        $globalRoles = $user->getRoleNames()->all();

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $workgroup,
            'pageClass' => ViewWorkgroup::class,
        ])
            ->assertTableActionVisible('edit', $membership)
            ->callTableAction('edit', $membership, data: [
                'user_id' => $otherUser->id,
                'role' => 'admin',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('admin', $membership->refresh()->role);
        $this->assertSame($user->id, $membership->user_id);
        $this->assertSame($workgroup->id, $membership->workgroup_id);
        $this->assertSame('member', $otherMembership->refresh()->role);
        $this->assertSame($identity, $user->refresh()->only(['id', 'employee_id', 'password']));
        $this->assertSame($globalRoles, $user->getRoleNames()->all());
    }

    public function test_workgroup_facilitator_can_edit_a_role_in_their_workgroup(): void
    {
        $facilitator = User::factory()->create();
        $workgroup = $this->workgroup($facilitator);
        $this->membership($workgroup, $facilitator, 'facilitator');
        $membership = $this->membership($workgroup, User::factory()->create());
        $this->actingAs($facilitator);

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $workgroup,
            'pageClass' => ViewWorkgroup::class,
        ])
            ->assertTableActionVisible('edit', $membership)
            ->callTableAction('edit', $membership, data: ['role' => 'facilitator'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('facilitator', $membership->refresh()->role);
    }

    public function test_global_member_edit_page_saves_roles_with_identity_fields_disabled(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        $workgroup = $this->workgroup($admin);
        $membership = $this->membership($workgroup, User::factory()->create());
        $userId = $membership->user_id;

        Livewire::test(EditWorkgroupMember::class, ['record' => $membership->getRouteKey()])
            ->assertFormFieldIsDisabled('user_id')
            ->assertFormFieldIsDisabled('workgroup_id')
            ->fillForm(['role' => 'facilitator'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('facilitator', $membership->refresh()->role);
        $this->assertSame($userId, $membership->user_id);
        $this->assertSame($workgroup->id, $membership->workgroup_id);
    }

    public function test_regular_member_cannot_edit_workgroup_roles(): void
    {
        $user = User::factory()->create();
        $workgroup = $this->workgroup($user);
        $membership = $this->membership($workgroup, $user);
        $this->actingAs($user);

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $workgroup,
            'pageClass' => ViewWorkgroup::class,
        ])->assertTableActionHidden('edit', $membership);

        $this->assertFalse(MembersRelationManager::canViewForRecord($workgroup, ViewWorkgroup::class));
        $this->assertSame('member', $membership->refresh()->role);
    }

    public function test_facilitator_of_another_workgroup_cannot_edit_roles(): void
    {
        $facilitator = User::factory()->create();
        $this->membership($this->workgroup($facilitator), $facilitator, 'facilitator');
        $workgroup = $this->workgroup(User::factory()->create());
        $membership = $this->membership($workgroup, User::factory()->create());
        $this->actingAs($facilitator);

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $workgroup,
            'pageClass' => ViewWorkgroup::class,
        ])->assertTableActionHidden('edit', $membership);

        $this->assertFalse(MembersRelationManager::canViewForRecord($workgroup, ViewWorkgroup::class));
        $this->assertSame('member', $membership->refresh()->role);
    }

    private function workgroup(User $creator): Workgroup
    {
        return Workgroup::query()->create([
            'name' => 'Training Workgroup',
            'created_by' => $creator->id,
            'is_active' => true,
        ]);
    }

    private function membership(Workgroup $workgroup, User $user, string $role = 'member'): WorkgroupMember
    {
        return WorkgroupMember::query()->create([
            'workgroup_id' => $workgroup->id,
            'user_id' => $user->id,
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
