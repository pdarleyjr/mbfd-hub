<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Workgroup\Pages\CreateWorkgroup;
use App\Filament\Resources\Workgroup\Pages\CreateWorkgroupMember;
use App\Filament\Resources\Workgroup\Pages\EditWorkgroupMember;
use App\Filament\Resources\Workgroup\Pages\ListWorkgroupMembers;
use App\Filament\Resources\Workgroup\Pages\ViewWorkgroup;
use App\Filament\Resources\Workgroup\RelationManagers\MembersRelationManager;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Services\Workgroup\WorkgroupMembershipService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class WorkgroupBulkMembershipTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($this->admin);
    }

    public function test_a_user_can_have_active_memberships_in_multiple_workgroups(): void
    {
        $user = User::factory()->create();
        $userCount = User::query()->count();
        $passwordHash = $user->getRawOriginal('password');
        $roles = $user->getRoleNames()->all();
        $first = $this->workgroup('Existing Workgroup');
        $second = $this->workgroup('Back to Basics - Train the Trainer');

        WorkgroupMember::query()->create([
            'workgroup_id' => $first->id,
            'user_id' => $user->id,
            'role' => 'member',
            'is_active' => true,
        ]);

        app(WorkgroupMembershipService::class)->addUsers($second, [$user->id], 'facilitator');

        $this->assertDatabaseHas('workgroup_members', [
            'workgroup_id' => $first->id,
            'user_id' => $user->id,
            'role' => 'member',
            'is_active' => true,
        ]);
        self::assertSame($userCount, User::query()->count());
        self::assertSame($passwordHash, $user->refresh()->getRawOriginal('password'));
        self::assertSame($roles, $user->getRoleNames()->all());
        $this->assertDatabaseHas('workgroup_members', [
            'workgroup_id' => $second->id,
            'user_id' => $user->id,
            'role' => 'facilitator',
            'is_active' => true,
        ]);
    }

    public function test_bulk_add_is_duplicate_safe_and_reactivates_an_inactive_membership(): void
    {
        $workgroup = $this->workgroup('Training Workgroup');
        $active = User::factory()->create();
        $inactive = User::factory()->create();
        $new = User::factory()->create();

        WorkgroupMember::query()->create([
            'workgroup_id' => $workgroup->id,
            'user_id' => $active->id,
            'role' => 'member',
            'is_active' => true,
        ]);
        WorkgroupMember::query()->create([
            'workgroup_id' => $workgroup->id,
            'user_id' => $inactive->id,
            'role' => 'member',
            'is_active' => false,
        ]);

        $changed = app(WorkgroupMembershipService::class)->addUsers(
            $workgroup,
            [$active->id, $inactive->id, $new->id, $new->id],
            'facilitator',
        );

        self::assertSame(2, $changed);
        self::assertSame(3, $workgroup->members()->count());
        self::assertSame(
            'member',
            WorkgroupMember::query()
                ->where('workgroup_id', $workgroup->id)
                ->where('user_id', $active->id)
                ->sole()
                ->role,
        );
        $this->assertDatabaseHas('workgroup_members', [
            'workgroup_id' => $workgroup->id,
            'user_id' => $inactive->id,
            'role' => 'facilitator',
            'is_active' => true,
        ]);
    }

    public function test_workgroup_creation_can_add_multiple_existing_users(): void
    {
        $users = User::factory()->count(2)->create();

        Livewire::test(CreateWorkgroup::class)
            ->assertFormFieldExists('member_user_ids')
            ->fillForm([
                'name' => 'Back to Basics - Train the Trainer',
                'description' => 'Module training workgroup',
                'is_active' => true,
                'member_user_ids' => $users->modelKeys(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $workgroup = Workgroup::query()->where('name', 'Back to Basics - Train the Trainer')->sole();
        self::assertEqualsCanonicalizing($users->modelKeys(), $workgroup->members()->pluck('user_id')->all());
    }

    public function test_invalid_bulk_selection_fails_without_partial_memberships(): void
    {
        $workgroup = $this->workgroup('Fail Closed Bulk Add');
        $validUser = User::factory()->create();

        try {
            app(WorkgroupMembershipService::class)->addUsers($workgroup, [$validUser->id, 999999]);
            self::fail('Expected invalid bulk selection to be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('user_ids', $exception->errors());
        }

        self::assertSame(0, $workgroup->members()->count());
    }

    public function test_legacy_single_add_reactivates_instead_of_raising_a_duplicate_error(): void
    {
        $workgroup = $this->workgroup('Legacy Single Add');
        $user = User::factory()->create();
        WorkgroupMember::query()->create([
            'workgroup_id' => $workgroup->id,
            'user_id' => $user->id,
            'role' => 'member',
            'is_active' => false,
        ]);

        Livewire::test(CreateWorkgroupMember::class)
            ->fillForm([
                'workgroup_id' => $workgroup->id,
                'user_id' => $user->id,
                'role' => 'facilitator',
                'is_active' => true,
                'count_evaluations' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        self::assertSame(1, $workgroup->members()->count());
        $this->assertDatabaseHas('workgroup_members', [
            'workgroup_id' => $workgroup->id,
            'user_id' => $user->id,
            'role' => 'facilitator',
            'is_active' => true,
        ]);
    }

    public function test_membership_identity_cannot_be_reassigned_while_editing_profile_settings(): void
    {
        $originalWorkgroup = $this->workgroup('Original Workgroup');
        $otherWorkgroup = $this->workgroup('Other Workgroup');
        $originalUser = User::factory()->create();
        $otherUser = User::factory()->create();
        $membership = WorkgroupMember::query()->create([
            'workgroup_id' => $originalWorkgroup->id,
            'user_id' => $originalUser->id,
            'role' => 'member',
            'is_active' => true,
        ]);

        Livewire::test(EditWorkgroupMember::class, ['record' => $membership->getRouteKey()])
            ->assertFormFieldIsDisabled('user_id')
            ->assertFormFieldIsDisabled('workgroup_id')
            ->fillForm([
                'user_id' => $otherUser->id,
                'workgroup_id' => $otherWorkgroup->id,
                'role' => 'facilitator',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $membership->refresh();
        self::assertSame($originalUser->id, $membership->user_id);
        self::assertSame($originalWorkgroup->id, $membership->workgroup_id);
        self::assertSame('facilitator', $membership->role);
    }

    public function test_global_member_list_supports_bulk_adds_to_one_workgroup(): void
    {
        $workgroup = $this->workgroup('Global Bulk Add');
        $users = User::factory()->count(2)->create();

        Livewire::test(ListWorkgroupMembers::class)
            ->assertActionExists('addMembers')
            ->callAction('addMembers', data: [
                'workgroup_id' => $workgroup->id,
                'user_ids' => $users->modelKeys(),
                'role' => 'member',
                'count_evaluations' => true,
            ])
            ->assertHasNoActionErrors();

        self::assertEqualsCanonicalizing($users->modelKeys(), $workgroup->members()->pluck('user_id')->all());
    }

    public function test_workgroup_members_tab_supports_bulk_adds(): void
    {
        $workgroup = $this->workgroup('Relation Manager Bulk Add');
        $users = User::factory()->count(2)->create();

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $workgroup,
            'pageClass' => ViewWorkgroup::class,
        ])
            ->assertTableActionExists('addMembers')
            ->callTableAction('addMembers', data: [
                'user_ids' => $users->modelKeys(),
                'role' => 'member',
                'count_evaluations' => true,
            ])
            ->assertHasNoTableActionErrors();

        self::assertEqualsCanonicalizing($users->modelKeys(), $workgroup->members()->pluck('user_id')->all());
    }

    private function workgroup(string $name): Workgroup
    {
        return Workgroup::query()->create([
            'name' => $name,
            'created_by' => $this->admin->id,
            'is_active' => true,
        ]);
    }
}
