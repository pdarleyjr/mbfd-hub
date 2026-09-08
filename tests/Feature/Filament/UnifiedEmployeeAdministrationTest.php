<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Filament\Resources\UserResource;
use App\Models\Employee;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class UnifiedEmployeeAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['security.employee_bootstrap.secret' => 'test-only-bootstrap']);
        $actor = User::factory()->create(['account_status' => 'active']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_one_directory_and_legacy_index_redirect(): void
    {
        self::assertSame('Employees & Access', EmployeeResource::getNavigationLabel());
        self::assertFalse(UserResource::shouldRegisterNavigation());
        $this->get(UserResource::getUrl())->assertRedirect(EmployeeResource::getUrl());
    }

    public function test_legacy_account_url_uses_foreign_key_not_matching_primary_keys(): void
    {
        $employee = Employee::query()->create(['employee_id' => '50010', 'name' => 'Canonical Person']);
        $user = User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => '50010']);
        $this->get(UserResource::getUrl('edit', ['record' => $user]))
            ->assertRedirect(EmployeeResource::getUrl('edit', ['record' => $employee]));
    }

    public function test_profile_save_preserves_identity_and_password_and_updates_personnel_source(): void
    {
        $employee = Employee::query()->create(['employee_id' => '50011', 'name' => 'Original Name']);
        $user = User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => '50011']);
        $password = $user->getRawOriginal('password');
        Livewire::test(EditEmployee::class, ['record' => $employee->id])
            ->assertFormFieldIsDisabled('employee_id')
            ->assertActionDoesNotExist('delete')
            ->fillForm(['name' => 'Updated Name', 'rank' => 'Captain'])
            ->call('save')->assertHasNoFormErrors();
        self::assertSame('Updated Name', $employee->fresh()->name);
        self::assertSame('Updated Name', $user->fresh()->name);
        self::assertSame('50011', $user->fresh()->employee_id);
        self::assertSame($password, $user->fresh()->getRawOriginal('password'));
    }

    public function test_self_profile_allows_contact_but_not_security_or_rank_changes(): void
    {
        $employee = Employee::query()->create(['employee_id' => '50012', 'name' => 'Self', 'rank' => 'Captain']);
        $actor = auth()->user();
        $actor->forceFill(['employee_profile_id' => $employee->id, 'employee_id' => '50012'])->save();
        Livewire::test(EditEmployee::class, ['record' => $employee->id])
            ->assertFormFieldIsDisabled('rank')
            ->assertActionHidden('resetPassword')
            ->fillForm(['phone' => '305-555-0100', 'display_name' => 'Captain Self'])
            ->call('save')->assertHasNoFormErrors();
        self::assertSame('Captain', $employee->fresh()->rank);
        self::assertSame('305-555-0100', $employee->fresh()->phone);
        self::assertTrue($actor->fresh()->hasRole('super_admin'));
    }

    public function test_unlinked_legacy_account_does_not_redirect_by_coincidental_employee_primary_key(): void
    {
        $user = User::factory()->create(['account_status' => 'active']);
        $this->get(UserResource::getUrl('edit', ['record' => $user]))->assertRedirect(
            \App\Filament\Resources\AccountProfileResource::getUrl('edit', ['record' => $user]),
        );
    }

    public function test_read_only_personnel_administrator_can_inspect_but_cannot_save(): void
    {
        $employee = Employee::query()->create(['employee_id' => '50013', 'name' => 'Read only target']);
        $viewer = User::factory()->create(['account_status' => 'active']);
        foreach (['admin.access', 'admin.personnel.view'] as $permission) {
            $viewer->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($viewer);
        Livewire::test(EditEmployee::class, ['record' => $employee->id])
            ->assertFormFieldIsDisabled('name')->assertFormFieldIsDisabled('phone')
            ->assertActionHidden('save')->assertActionHidden('correctEmployeeId');
        self::assertSame('Read only target', $employee->fresh()->name);
    }

    public function test_workgroup_dialog_round_trips_existing_membership_and_adds_multiple_groups(): void
    {
        $employee = Employee::query()->create(['employee_id' => '50014', 'name' => 'Workgroup target']);
        $user = User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id, 'account_status' => 'active']);
        $first = \App\Models\Workgroup::query()->create(['name' => 'First training group', 'created_by' => auth()->id()]);
        $second = \App\Models\Workgroup::query()->create(['name' => 'Second training group', 'created_by' => auth()->id()]);
        $membership = \App\Models\WorkgroupMember::query()->create(['user_id' => $user->id, 'workgroup_id' => $first->id, 'role' => 'member', 'is_active' => true, 'count_evaluations' => true]);
        Livewire::test(EditEmployee::class, ['record' => $employee->id])
            ->mountAction('manageWorkgroups')->assertHasNoActionErrors()
            ->set('mountedActionsData.0.memberships', [
                ['workgroup_id' => $first->id, 'role' => 'facilitator', 'is_active' => true, 'count_evaluations' => true],
                ['workgroup_id' => $second->id, 'role' => 'member', 'is_active' => true, 'count_evaluations' => true],
            ])->set('mountedActionsData.0.current_password', 'password')
            ->set('mountedActionsData.0.reason', 'Approved training responsibilities')
            ->call('callMountedAction')
            ->assertHasNoActionErrors();
        self::assertSame('facilitator', $membership->fresh()->role);
        self::assertSame(2, \App\Models\WorkgroupMember::query()->where('user_id', $user->id)->count());
    }
}
