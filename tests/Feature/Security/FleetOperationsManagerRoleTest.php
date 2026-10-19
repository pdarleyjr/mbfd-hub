<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\ApparatusInspectionException;
use App\Models\User;
use App\Services\Security\FleetOperationsManagerAssignment;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FleetOperationsManagerRoleTest extends TestCase
{
    use RefreshDatabase;

    private const ACTOR_PASSWORD = 'Super-Admin-Current-2026!';

    public function test_the_migration_provisions_one_role_with_the_whole_fleet_set(): void
    {
        $role = Role::findByName(FleetOperationsManagerAssignment::ROLE, 'web');

        self::assertEqualsCanonicalizing(
            ['admin.access', 'admin.fleet.view', 'admin.fleet.manage', 'admin.stations.view', 'manage_pm_settings'],
            $role->permissions->pluck('name')->all(),
        );
        self::assertSame(0, $role->users()->count(), 'Provisioning assigns no one.');
    }

    public function test_the_assignment_unlocks_fleet_tools_and_maintenance_controls_only(): void
    {
        $member = $this->member();
        $before = $this->granted($member);

        app(FleetOperationsManagerAssignment::class)->set($this->superAdmin(), $member, true, self::ACTOR_PASSWORD, 'Fleet operations duties');
        $member = $member->fresh();

        self::assertTrue(FleetOperationsManagerAssignment::isAssigned($member));
        self::assertTrue($member->canAccessPanel(Filament::getPanel('admin')));
        self::assertTrue($member->can('viewAny', Apparatus::class));
        self::assertTrue($member->can('create', Apparatus::class));
        self::assertTrue($member->can('manage_pm_settings'), 'Preventative-maintenance settings need this permission.');
        self::assertTrue($member->can('viewAny', ApparatusInspectionException::class));
        self::assertFalse($member->can('admin.members.manage'));
        self::assertFalse($member->can('admin.stations.manage'));
        self::assertFalse($member->hasAnyRole(['super_admin', 'admin', 'logistics_admin']));
        self::assertEqualsCanonicalizing(
            [...$before, 'admin.access', 'admin.fleet.view', 'admin.fleet.manage', 'admin.stations.view'],
            $this->granted($member),
        );
        self::assertDatabaseHas('security_action_events', [
            'target_user_id' => $member->id, 'action' => 'change_fleet_operations_manager', 'result' => 'allowed',
        ]);
    }

    public function test_assigning_twice_changes_nothing_and_keeps_existing_grants(): void
    {
        $member = $this->member();
        $member->givePermissionTo(Permission::findOrCreate('admin.stations.manage', 'web'));
        $service = app(FleetOperationsManagerAssignment::class);
        $actor = $this->superAdmin();

        $service->apply($actor, $member, true, 'first');
        $second = $service->apply($actor, $member->fresh(), true, 'second');

        self::assertSame([], $second['granted']);
        self::assertSame(1, $member->fresh()->roles()->where('name', FleetOperationsManagerAssignment::ROLE)->count());
        self::assertTrue($member->fresh()->hasDirectWebPermission('admin.stations.manage'));
    }

    public function test_removing_takes_back_fleet_access_but_keeps_unrelated_capabilities(): void
    {
        $member = $this->member();
        $member->givePermissionTo(Permission::findOrCreate('admin.training.view', 'web'));
        $service = app(FleetOperationsManagerAssignment::class);
        $actor = $this->superAdmin();
        $service->apply($actor, $member, true, 'grant');

        $service->apply($actor, $member->fresh(), false, 'revoke');
        $member = $member->fresh();

        self::assertFalse($member->hasRole(FleetOperationsManagerAssignment::ROLE));
        self::assertFalse($member->can('manage_pm_settings'));
        self::assertFalse($member->hasDirectWebPermission('admin.fleet.manage'));
        self::assertTrue($member->hasDirectWebPermission('admin.training.view'));
        self::assertTrue($member->hasDirectWebPermission('admin.access'), 'Panel entry stays for the remaining capability.');
    }

    public function test_only_an_active_super_administrator_with_their_password_can_assign_it(): void
    {
        $member = $this->member();
        $service = app(FleetOperationsManagerAssignment::class);
        $ordinaryAdmin = User::factory()->create(['account_status' => AccountStatus::Active, 'password' => Hash::make(self::ACTOR_PASSWORD)]);
        $ordinaryAdmin->assignRole(Role::findOrCreate('admin', 'web'));

        foreach ([
            fn () => $service->set($ordinaryAdmin, $member, true, self::ACTOR_PASSWORD, 'not allowed'),
            fn () => $service->set($this->superAdmin(), $member, true, 'wrong-password', 'wrong password'),
            fn () => $service->set($this->superAdmin(), $member, true, self::ACTOR_PASSWORD, ' '),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('The assignment should have been refused.');
            } catch (\Throwable) {
            }
        }

        self::assertFalse($member->fresh()->hasRole(FleetOperationsManagerAssignment::ROLE));
        self::assertSame([], $this->granted($member->fresh()));
    }

    public function test_the_operator_command_is_audited_and_supports_a_dry_run(): void
    {
        $member = $this->member('FLEET-CLI');
        $actor = $this->superAdmin('FLEET-ACTOR');

        $this->artisan('mbfd:fleet-operations-manager', ['employee_id' => 'FLEET-CLI', '--actor' => 'FLEET-ACTOR', '--reason' => 'Fleet duties', '--dry-run' => true])
            ->assertSuccessful();
        self::assertFalse($member->fresh()->hasRole(FleetOperationsManagerAssignment::ROLE));

        $this->artisan('mbfd:fleet-operations-manager', ['employee_id' => 'FLEET-CLI', '--actor' => 'FLEET-ACTOR', '--reason' => 'Fleet duties'])
            ->assertSuccessful();
        self::assertTrue(FleetOperationsManagerAssignment::isAssigned($member->fresh()));
        self::assertDatabaseHas('security_action_events', [
            'actor_user_id' => $actor->id, 'target_user_id' => $member->id, 'action' => 'change_fleet_operations_manager', 'reason' => 'Fleet duties',
        ]);

        $this->artisan('mbfd:fleet-operations-manager', ['employee_id' => 'FLEET-CLI', '--actor' => 'FLEET-CLI', '--reason' => 'self'])
            ->assertFailed();
    }

    private function member(string $employeeId = 'FLEET-MEMBER'): User
    {
        return User::factory()->create(['employee_id' => $employeeId, 'account_status' => AccountStatus::Active]);
    }

    private function superAdmin(string $employeeId = 'FLEET-SUPER'): User
    {
        $existing = User::query()->where('employee_id', $employeeId)->first();
        if ($existing instanceof User) {
            return $existing;
        }
        $actor = User::factory()->create([
            'employee_id' => $employeeId, 'account_status' => AccountStatus::Active, 'password' => Hash::make(self::ACTOR_PASSWORD),
        ]);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $actor;
    }

    /** @return list<string> */
    private function granted(User $user): array
    {
        return $user->permissions()->pluck('name')->sort()->values()->all();
    }
}
