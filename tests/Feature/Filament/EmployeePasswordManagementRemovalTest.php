<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\Employee;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class EmployeePasswordManagementRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['security.employee_bootstrap.secret' => 'test-owner-approved-bootstrap']);
        $this->withoutVite();
    }

    public function test_employee_resource_exposes_no_password_fields_or_reset_action(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'D03-COMPATIBILITY',
            'name' => 'Compatibility Profile',
            'rank' => 'Firefighter',
            'password' => 'retained-compatibility-value',
            'must_change_password' => false,
        ]);
        $actor = User::factory()->create();
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListEmployees::class)
            ->assertTableActionDoesNotExist('resetPassword', record: $employee);

        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->assertFormFieldDoesNotExist('password')
            ->assertFormFieldDoesNotExist('must_change_password');
    }

    public function test_employee_resource_creates_a_first_login_ready_profile_without_exposing_the_password(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateEmployee::class)
            ->assertFormFieldDoesNotExist('password')
            ->assertFormFieldDoesNotExist('must_change_password')
            ->fillForm([
                'employee_id' => 'D03-NEW-PROFILE',
                'name' => 'New Operational Profile',
                'rank' => 'Firefighter',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $employee = Employee::query()->where('employee_id', 'D03-NEW-PROFILE')->sole();

        self::assertTrue(Hash::check('test-owner-approved-bootstrap', $employee->getAuthPassword()));
        self::assertTrue($employee->must_change_password);
    }

    public function test_employee_resource_fails_closed_when_the_protected_bootstrap_secret_is_unavailable(): void
    {
        config(['security.employee_bootstrap.secret' => null]);
        $actor = User::factory()->create();
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateEmployee::class)
            ->fillForm([
                'employee_id' => 'D03-NO-SECRET',
                'name' => 'Unavailable Bootstrap Profile',
                'rank' => 'Firefighter',
            ])
            ->call('create')
            ->assertHasFormErrors(['employee_id']);

        self::assertFalse(Employee::query()->where('employee_id', 'D03-NO-SECRET')->exists());
    }

    public function test_every_new_employee_without_an_explicit_credential_is_first_login_ready(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'D03-MODEL-DEFAULT',
            'name' => 'Model Default Profile',
            'rank' => 'Firefighter',
        ]);

        self::assertTrue(Hash::check('test-owner-approved-bootstrap', $employee->getAuthPassword()));
        self::assertTrue($employee->must_change_password);
    }
}
