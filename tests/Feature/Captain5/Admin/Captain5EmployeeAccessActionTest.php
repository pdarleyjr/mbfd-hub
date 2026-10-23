<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5\Admin;

use App\Filament\Resources\AccountProfileResource\Pages\EditAccountProfile;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Models\Captain5\Captain5AccessOverride;
use App\Models\Employee;
use App\Models\User;
use App\Services\Security\Captain5Access;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\TestCase;

/** The "Captain 5 Access" action on the employee profile mirrors the 300 Command Access action. */
final class Captain5EmployeeAccessActionTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['security.employee_bootstrap.secret' => 'Contextual-test-bootstrap-only']);
        $this->buildCaptain5World('2026-10-20 10:00:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_super_administrator_sets_access_with_password_and_reason_and_it_is_audited(): void
    {
        $admin = $this->superAdmin();
        $target = $this->officer('Firefighter', 'Target Tess');
        $this->actAs($admin);

        $page = Livewire::test(EditEmployee::class, ['record' => $target->employee_profile_id])
            ->mountAction('manageCaptain5Access')->assertActionMounted('manageCaptain5Access')
            ->assertSee('Current Captain 5 access')->assertSee('No Captain 5 access')
            ->assertSee('Your current administrator password')->assertSee('Reason for this change');

        $page->setActionData(['choice' => 'allow', 'current_password' => 'password', 'reason' => 'Covers EMS duties'])
            ->callMountedAction()->assertHasNoActionErrors();

        $override = Captain5AccessOverride::query()->current()->where('employee_profile_id', $target->employee_profile_id)->sole();
        self::assertSame('allow', $override->effect);
        self::assertSame('act', $override->level);
        self::assertSame('Covers EMS duties', $override->reason);
        self::assertSame((int) $admin->id, (int) $override->set_by_user_id);
        self::assertDatabaseHas('security_action_events', [
            'actor_user_id' => $admin->id, 'target_user_id' => $target->id, 'action' => 'change_captain5_access', 'reason' => 'Covers EMS duties',
        ]);
        $this->forgetCaptain5Memo();
        self::assertTrue(app(Captain5Access::class)->decide($target->fresh())->canAct);

        // Reopening shows the choice now in force.
        $page->mountAction('manageCaptain5Access')->assertSee('Administrator choice: Allow.');
    }

    public function test_wrong_password_or_missing_reason_changes_nothing(): void
    {
        $this->actAs($this->superAdmin());
        $target = $this->officer('Lieutenant', 'Target Lt');

        $page = Livewire::test(EditEmployee::class, ['record' => $target->employee_profile_id])->mountAction('manageCaptain5Access');
        $page->setActionData(['choice' => 'deny', 'current_password' => 'not-the-password', 'reason' => 'Review pending'])
            ->callMountedAction()->assertHasActionErrors(['current_password']);
        $page->setActionData(['choice' => 'deny', 'current_password' => 'password', 'reason' => ''])
            ->callMountedAction()->assertHasActionErrors(['reason']);
        $page->setActionData(['choice' => 'banned', 'current_password' => 'password', 'reason' => 'Bad choice'])
            ->callMountedAction()->assertHasActionErrors();
        foreach (['allow_view', 'allow_act'] as $removed) {
            $page->setActionData(['choice' => $removed, 'current_password' => 'password', 'reason' => 'Removed level'])
                ->callMountedAction()->assertHasActionErrors(['choice']);
        }

        self::assertSame(0, Captain5AccessOverride::query()->count());
        self::assertDatabaseMissing('security_action_events', ['action' => 'change_captain5_access']);
    }

    public function test_deny_removes_rank_based_access_and_automatic_restores_it(): void
    {
        $this->actAs($this->superAdmin());
        $target = $this->officer('Captain', 'Target Captain');
        $this->forgetCaptain5Memo();
        self::assertTrue(app(Captain5Access::class)->decide($target->fresh())->canViewData, 'Captains qualify by rank');

        $page = Livewire::test(EditEmployee::class, ['record' => $target->employee_profile_id]);
        $page->mountAction('manageCaptain5Access')->assertSee('Officer rank: Captain')
            ->setActionData(['choice' => 'deny', 'current_password' => 'password', 'reason' => 'Out for medical leave'])->callMountedAction()->assertHasNoActionErrors();
        $this->forgetCaptain5Memo();
        self::assertFalse(app(Captain5Access::class)->decide($target->fresh())->canViewData);

        $page->mountAction('manageCaptain5Access')->assertSee('Denied by an administrator')
            ->setActionData(['choice' => 'automatic', 'current_password' => 'password', 'reason' => 'Back on duty'])->callMountedAction()->assertHasNoActionErrors();
        $this->forgetCaptain5Memo();
        self::assertTrue(app(Captain5Access::class)->decide($target->fresh())->canViewData);
        self::assertSame(1, Captain5AccessOverride::query()->count(), 'The deny row is kept as history');
        self::assertSame(0, Captain5AccessOverride::query()->current()->count());
    }

    public function test_a_captain5_administrator_who_is_not_a_super_administrator_can_use_it(): void
    {
        $admin = $this->captain5Administrator();
        $admin->givePermissionTo(array_map(static fn (string $p): Permission => Permission::findOrCreate($p, 'web'), ['admin.members.view', 'admin.members.manage']));
        $target = $this->officer('Firefighter', 'Delegated Dee');
        $this->forgetCaptain5Memo();
        $this->actAs($admin->fresh());

        Livewire::test(EditEmployee::class, ['record' => $target->employee_profile_id])
            ->mountAction('manageCaptain5Access')
            ->setActionData(['choice' => 'allow', 'current_password' => 'password', 'reason' => 'Observer for the pilot'])
            ->callMountedAction()->assertHasNoActionErrors();

        self::assertSame('allow', Captain5AccessOverride::query()->current()->sole()->effect);
    }

    public function test_the_control_is_hidden_for_unauthorized_actors_self_super_admins_and_nonemployee_accounts(): void
    {
        $target = $this->officer('Firefighter', 'Plain Target');

        // A personnel manager without Captain 5 administration never sees it.
        $personnel = $this->officer('Firefighter', 'Personnel Manager');
        $personnel->givePermissionTo(array_map(static fn (string $p): Permission => Permission::findOrCreate($p, 'web'), ['admin.access', 'admin.members.view', 'admin.members.manage']));
        $this->forgetCaptain5Memo();
        $this->actAs($personnel->fresh());
        Livewire::test(EditEmployee::class, ['record' => $target->employee_profile_id])->assertActionHidden('manageCaptain5Access');

        // Nobody changes their own Captain 5 access, and a super administrator target is protected.
        $admin = $this->superAdmin();
        $employee = Employee::query()->create(['employee_id' => 'SA-1', 'name' => 'Super Sam', 'rank' => 'Firefighter', 'password' => 'x', 'must_change_password' => false]);
        $protected = User::factory()->create(['account_status' => 'active', 'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id]);
        $protected->assignRole('super_admin');
        $this->forgetCaptain5Memo();
        $this->actAs($admin);
        Livewire::test(EditEmployee::class, ['record' => $employee->id])->assertActionHidden('manageCaptain5Access');

        $this->actAs($protected->fresh());
        Livewire::test(EditEmployee::class, ['record' => $employee->id])->assertActionHidden('manageCaptain5Access');

        // A login with no personnel profile has no Captain 5 access to configure.
        $loose = User::factory()->create(['account_status' => 'active']);
        $this->actAs($admin->fresh());
        Livewire::test(EditAccountProfile::class, ['record' => $loose->id])->assertActionHidden('manageCaptain5Access');
        self::assertSame(0, Captain5AccessOverride::query()->count());
    }

    public function test_a_direct_call_by_an_unauthorized_actor_cannot_change_access(): void
    {
        $personnel = $this->officer('Firefighter', 'Personnel Manager');
        $personnel->givePermissionTo(array_map(static fn (string $p): Permission => Permission::findOrCreate($p, 'web'), ['admin.access', 'admin.members.view', 'admin.members.manage']));
        $target = $this->officer('Lieutenant', 'Direct Target');
        $this->forgetCaptain5Memo();
        $this->actAs($personnel->fresh());

        // Drive the Livewire calls directly: the Filament test helpers refuse to touch a hidden action.
        Livewire::test(EditEmployee::class, ['record' => $target->employee_profile_id])
            ->call('mountAction', 'manageCaptain5Access')
            ->set('mountedActionsData.0.choice', 'deny')
            ->set('mountedActionsData.0.current_password', 'password')
            ->set('mountedActionsData.0.reason', 'Should not work')
            ->call('callMountedAction');

        self::assertSame(0, Captain5AccessOverride::query()->count());
    }

    public function test_the_control_is_listed_in_the_access_and_capabilities_tab(): void
    {
        $this->actAs($this->superAdmin());
        $target = $this->officer('Firefighter', 'Listed Lee');

        Livewire::test(EditEmployee::class, ['record' => $target->employee_profile_id])
            ->assertSeeHtml('data-employee-controls="hub-capabilities"')
            ->assertSee('Captain 5 access');
    }
}
