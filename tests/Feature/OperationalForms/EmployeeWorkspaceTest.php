<?php

namespace Tests\Feature\OperationalForms;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_employee_login_and_intended_path_is_remembered(): void
    {
        $response = $this->get('/employee/forms');

        $response->assertRedirect('/employee/login');
        $this->assertSame(url('/employee/forms'), session('url.intended'));
        $this->assertSame('/employee/forms', session('employee.intended_path'));
    }

    public function test_authenticated_employee_can_open_workspace_without_sensitive_bootstrap_data(): void
    {
        $this->withoutVite();
        $employee = Employee::query()->create([
            'employee_id' => 'F042',
            'name' => 'Taylor Morgan',
            'rank' => 'Firefighter',
            'password' => 'OperationalForms!1',
            'must_change_password' => false,
        ]);

        $response = $this->actingAs($employee, 'employee')->get('/employee/forms');

        $response->assertOk()
            ->assertSee('operational-forms-root')
            ->assertSee('Taylor Morgan')
            ->assertSee('F042')
            ->assertDontSee('OperationalForms!1')
            ->assertDontSee('storage_path');
    }

    public function test_employee_dashboard_header_has_a_main_hub_home_action(): void
    {
        $this->withoutVite();
        $employee = Employee::query()->create([
            'employee_id' => 'F043',
            'name' => 'Jordan Lee',
            'password' => 'OperationalForms!1',
            'must_change_password' => false,
        ]);

        $this->actingAs($employee, 'employee')
            ->get('/employee/dashboard')
            ->assertOk()
            ->assertSee('employee-header-home', false)
            ->assertSee('href="/"', false)
            ->assertSee('Return to MBFD Hub home');
    }
}
