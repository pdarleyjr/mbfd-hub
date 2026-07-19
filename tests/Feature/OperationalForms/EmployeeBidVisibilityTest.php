<?php

declare(strict_types=1);

namespace Tests\Feature\OperationalForms;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EmployeeBidVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_portal_hides_all_bid_navigation_and_unregisters_the_bid_page(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'BID-HIDDEN',
            'name' => 'Bid Hidden Test',
            'password' => 'password',
            'must_change_password' => false,
        ]);

        $this->actingAs($employee, 'employee')
            ->get('/employee/dashboard')
            ->assertOk()
            ->assertDontSee('Open Bid Console')
            ->assertDontSee('My Bid Certifications')
            ->assertDontSee('Bid Certifications');

        $this->assertFalse(Route::has('filament.employee.pages.my-bid-certifications'));
        $this->get('/employee/my-bid-certifications')->assertNotFound();
    }
}
