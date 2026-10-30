<?php

namespace Tests\Feature\OperationalForms;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_canonical_login_and_intended_path_is_remembered(): void
    {
        $response = $this->get('/employee/forms');

        $response->assertRedirect('/login');
        $this->assertSame(url('/employee/forms'), session('url.intended'));
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

    public function test_forms_library_bootstrap_lists_the_take_home_inspection_as_a_linked_entry(): void
    {
        $this->withoutVite();
        $employee = Employee::query()->create([
            'employee_id' => 'F043',
            'name' => 'Casey Ortiz',
            'rank' => 'Firefighter',
            'password' => 'OperationalForms!1',
            'must_change_password' => false,
        ]);

        $html = $this->actingAs($employee, 'employee')->get('/employee/forms')->assertOk()->getContent();

        self::assertSame(1, preg_match("/data-bootstrap='([^']*)'/", $html, $matches));
        $bootstrap = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

        // The Medical Inventory Request opens inside this app; the take-home inspection keeps its own app.
        self::assertSame(['medical-inventory-request', 'take-home-vehicle-inspection', 'narcotics-check'], array_column($bootstrap['library_links'], 'key'));
        self::assertSame([
            'key' => 'take-home-vehicle-inspection',
            'title' => 'Take Home Vehicle Inspection',
            'description' => 'Complete a take-home vehicle inspection with photos and review your submitted reports.',
            'href' => route('take-home-inspections.index'),
            'action_label' => 'Open inspections',
        ], $bootstrap['library_links'][1]);
        // The in-app forms and their record endpoints are untouched.
        self::assertSame(route('employee.forms.api.form-types'), $bootstrap['endpoints']['form_types']);
        self::assertSame(route('employee.forms.api.records.index'), $bootstrap['endpoints']['records']);
    }
}
