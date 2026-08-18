<?php

declare(strict_types=1);

namespace Tests\Feature\OperationalForms;

use App\Filament\Employee\Pages\Auth\EmployeeLogin;
use App\Models\Employee;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeLoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_employee_login_returns_to_forms_intended_destination(): void
    {
        $employee = $this->employee();
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        session()->put('employee.intended_path', '/employee/forms');

        Livewire::test(EmployeeLogin::class)
            ->fillForm(['email' => $employee->employee_id, 'password' => 'password'])
            ->call('authenticate')
            ->assertRedirect('/employee/forms');

        $this->assertAuthenticatedAs($employee, 'employee');
    }

    public function test_same_host_framework_intended_url_returns_to_forms(): void
    {
        $employee = $this->employee();
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        session()->put('url.intended', url('/employee/forms'));

        Livewire::test(EmployeeLogin::class)
            ->fillForm(['email' => $employee->employee_id, 'password' => 'password'])
            ->call('authenticate')
            ->assertRedirect('/employee/forms');
    }

    public function test_unsafe_intended_destination_is_rejected(): void
    {
        $employee = $this->employee();
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        session()->put('employee.intended_path', '//evil.example/steal');

        Livewire::test(EmployeeLogin::class)
            ->fillForm(['email' => $employee->employee_id, 'password' => 'password'])
            ->call('authenticate')
            ->assertRedirect(route('filament.employee.pages.dashboard'));
    }

    public function test_repeated_failures_are_throttled_without_an_exception_page(): void
    {
        $employee = $this->employee();
        Filament::setCurrentPanel(Filament::getPanel('employee'));

        for ($attempt = 0; $attempt < 5; $attempt++) {
            Livewire::test(EmployeeLogin::class)
                ->fillForm(['email' => $employee->employee_id, 'password' => 'incorrect-password'])
                ->call('authenticate')
                ->assertHasErrors(['data.email']);
        }

        Livewire::test(EmployeeLogin::class)
            ->fillForm(['email' => $employee->employee_id, 'password' => 'incorrect-password'])
            ->call('authenticate')
            ->assertNotified();
    }

    public function test_one_employee_cannot_exhaust_another_employees_shared_ip_limit(): void
    {
        $firstEmployee = $this->employee();
        $secondEmployee = $this->employee('20732');
        Filament::setCurrentPanel(Filament::getPanel('employee'));

        for ($attempt = 0; $attempt < 5; $attempt++) {
            Livewire::test(EmployeeLogin::class)
                ->fillForm(['email' => $firstEmployee->employee_id, 'password' => 'incorrect-password'])
                ->call('authenticate')
                ->assertHasErrors(['data.email']);
        }

        Livewire::test(EmployeeLogin::class)
            ->fillForm(['email' => $secondEmployee->employee_id, 'password' => 'password'])
            ->call('authenticate')
            ->assertRedirect(route('filament.employee.pages.dashboard'));

        $this->assertAuthenticatedAs($secondEmployee, 'employee');
    }

    public function test_successful_login_clears_its_failed_attempt_counter(): void
    {
        $employee = $this->employee();
        Filament::setCurrentPanel(Filament::getPanel('employee'));

        for ($attempt = 0; $attempt < 4; $attempt++) {
            Livewire::test(EmployeeLogin::class)
                ->fillForm(['email' => $employee->employee_id, 'password' => 'incorrect-password'])
                ->call('authenticate')
                ->assertHasErrors(['data.email']);
        }

        Livewire::test(EmployeeLogin::class)
            ->fillForm(['email' => $employee->employee_id, 'password' => 'password'])
            ->call('authenticate')
            ->assertRedirect(route('filament.employee.pages.dashboard'));

        auth('employee')->logout();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            Livewire::test(EmployeeLogin::class)
                ->fillForm(['email' => $employee->employee_id, 'password' => 'incorrect-password'])
                ->call('authenticate')
                ->assertHasErrors(['data.email']);
        }
    }

    #[DataProvider('unsafePaths')]
    public function test_safe_path_filter_rejects_external_and_out_of_panel_paths(string $path): void
    {
        $this->assertNull(EmployeeLogin::safeIntendedPath($path));
    }

    public static function unsafePaths(): array
    {
        return [
            ['https://evil.example/employee/forms'],
            ['//evil.example/employee/forms'],
            ['%2F%2Fevil.example/employee/forms'],
            ['/admin'],
            ['/employee/%2e%2e/admin'],
            ['\\evil.example\employee\forms'],
        ];
    }

    private function employee(string $employeeId = '20731'): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Employee Login Test',
            'password' => Hash::make('password'),
            'must_change_password' => false,
        ]);
    }
}
