<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Employee\Pages\RequestEquipmentPage;
use App\Models\Employee;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeEquipmentRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_request_reaches_admin_queue_without_losing_attribution_or_content(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => '20810',
            'name' => 'Portal Request Test',
            'rank' => 'Firefighter',
            'password' => Hash::make('a-long-test-password'),
            'must_change_password' => false,
        ]);

        $this->actingAs($employee, 'employee');
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('employee'));

        Livewire::test(RequestEquipmentPage::class)
            ->fillForm([
                'items' => [[
                    'item_code' => 't_shirt',
                    'size' => 'L',
                    'quantity' => 2,
                ]],
            ])
            ->call('submit')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('personnel_requests', [
            'beneficiary_employee_id' => $employee->id,
            'requester_employee_id' => $employee->id,
            'type' => 'uniform',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('personnel_request_items', [
            'item_code' => 't_shirt',
            'item_name' => 'T-Shirt',
            'size' => 'L',
            'quantity' => 2,
        ]);
        $this->assertDatabaseCount('employee_equipment_requests', 0);
    }
}
