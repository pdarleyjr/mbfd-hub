<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Integration;

use App\Enums\AccountStatus;
use App\Exceptions\CurrentPasswordMismatch;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Models\Employee;
use App\Models\User;
use App\Services\Security\ApplicationAccessService;
use App\Support\ApplicationAccessRegistry;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Mbfd\PolicyLibrary\PolicyLibraryServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CapabilityIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication(): Application
    {
        $app = require MBFD_POLICY_HUB_FIXTURE_ROOT.'/bootstrap/app.php';
        $app->afterBootstrapping(RegisterProviders::class, static function (Application $app): void {
            $app->register(PolicyLibraryServiceProvider::class);
        });
        $this->traitsUsedByTest = array_fill_keys(array_keys(class_uses_recursive(self::class)), 1);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Permission::findOrCreate('files.manage', 'web');
        Permission::findOrCreate('admin.access', 'web');
        Role::findOrCreate('super_admin', 'web');
    }

    public function test_existing_protected_capability_service_grants_and_revokes_library_management_without_hub_admin_escalation(): void
    {
        $password = bin2hex(random_bytes(24));
        $actor = $this->linkedUser($password);
        $actor->assignRole('super_admin');
        $target = $this->linkedUser();
        $options = app(ApplicationAccessRegistry::class)->capabilityOptions();
        self::assertSame('Policy library — manage', $options['files.manage']);
        $service = app(ApplicationAccessService::class);
        $service->syncAdministrationCapabilities($actor, $target, ['files.manage'], $password, 'Integration fixture grant');
        $target = $target->fresh();
        self::assertTrue($target->hasDirectWebPermission('files.manage'));
        self::assertFalse($target->hasDirectWebPermission('admin.access'));
        self::assertFalse($target->hasCurrentAdminPanelEntitlement());
        $this->assertDatabaseHas('security_action_events', [
            'actor_user_id' => $actor->id, 'target_user_id' => $target->id,
            'action' => 'change_admin_capabilities', 'result' => 'allowed',
        ]);
        $this->actingAsCanonicalUser($target);
        $this->get('https://files.mbfdhub.com/manage/manuals')->assertOk();
        $registryId = session('auth.canonical_session_id');
        $service->syncAdministrationCapabilities($actor, $target, [], $password, 'Integration fixture revoke');
        self::assertFalse($target->fresh()->hasDirectWebPermission('files.manage'));
        $this->app['auth']->guard('web')->setUser($target->fresh());
        $this->get('https://files.mbfdhub.com/manage/manuals')->assertForbidden();
        self::assertSame($registryId, session('auth.canonical_session_id'));
    }

    public function test_member_cannot_self_grant_the_new_capability(): void
    {
        $password = bin2hex(random_bytes(24));
        $actor = $this->linkedUser($password);
        $this->expectException(AuthorizationException::class);
        app(ApplicationAccessService::class)->syncAdministrationCapabilities($actor, $actor, ['files.manage'], $password, 'Rejected fixture self grant');
    }

    public function test_existing_employee_access_filament_action_assigns_the_library_permission(): void
    {
        $password = bin2hex(random_bytes(24));
        $actor = $this->linkedUser($password);
        $actor->assignRole('super_admin');
        $target = $this->linkedUser();
        $this->actingAsCanonicalUser($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        Livewire::test(EditEmployee::class, ['record' => $target->employee_profile_id])
            ->assertActionExists('manageAdministrationCapabilities')
            ->callAction('manageAdministrationCapabilities', [
                'capabilities' => ['files.manage'], 'current_password' => $password,
                'reason' => 'Integration fixture existing employee access action',
            ])->assertHasNoActionErrors();
        self::assertTrue($target->fresh()->hasDirectWebPermission('files.manage'));
        self::assertFalse($target->fresh()->hasCurrentAdminPanelEntitlement());
    }

    public function test_super_admin_must_reauthenticate_to_grant_the_new_capability(): void
    {
        $actor = $this->linkedUser();
        $actor->assignRole('super_admin');
        $target = $this->linkedUser();
        try {
            app(ApplicationAccessService::class)->syncAdministrationCapabilities($actor, $target, ['files.manage'], bin2hex(random_bytes(24)), 'Rejected fixture password');
            self::fail('The canonical capability workflow accepted an incorrect administrator password.');
        } catch (CurrentPasswordMismatch) {
            self::assertFalse($target->fresh()->hasDirectWebPermission('files.manage'));
        }
    }

    public function test_new_capability_does_not_open_the_separate_admin_access_scope(): void
    {
        $password = bin2hex(random_bytes(24));
        $actor = $this->linkedUser($password);
        $actor->assignRole('super_admin');
        $target = $this->linkedUser();
        $this->expectException(AuthorizationException::class);
        app(ApplicationAccessService::class)->syncAdministrationCapabilities($actor, $target, ['files.manage', 'admin.access'], $password, 'Rejected fixture escalation');
    }

    private function linkedUser(?string $password = null): User
    {
        $email = 'policy.capability.'.bin2hex(random_bytes(8)).'@miamibeachfl.gov';
        $employee = Employee::query()->create([
            'employee_id' => 'POLICY-'.bin2hex(random_bytes(4)), 'name' => 'Policy Capability Fixture',
            'rank' => 'Firefighter', 'roster_status' => 'active', 'city_email' => $email,
            'password' => bin2hex(random_bytes(24)), 'must_change_password' => false,
        ]);

        return User::factory()->create([
            'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id,
            'account_status' => AccountStatus::Active, 'email' => $email,
            'password' => Hash::make($password ?? bin2hex(random_bytes(24))), 'must_change_password' => false,
        ])->load('employeeProfile');
    }
}
