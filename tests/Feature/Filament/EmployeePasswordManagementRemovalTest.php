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

    public function test_employee_resource_creates_an_operational_profile_without_issuing_a_password(): void
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

        self::assertNotSame('', $employee->getRawOriginal('password'));
        self::assertFalse($employee->must_change_password);
    }
}
