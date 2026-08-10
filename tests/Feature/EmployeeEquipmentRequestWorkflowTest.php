<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Employee\Pages\RequestEquipmentPage;
use App\Models\Employee;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeEquipmentRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_request_reaches_admin_queue_without_losing_attribution_or_content(): void
    {
        Role::create(['name' => 'logistics_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('logistics_admin');

        $employee = Employee::query()->create([
            'employee_id' => '20810',
            'name' => 'Portal Request Test',
            'rank' => 'Firefighter',
            'password' => Hash::make('a-long-test-password'),
            'must_change_password' => false,
        ]);

        $this->actingAs($employee, 'employee');
        Filament::setCurrentPanel(Filament::getPanel('employee'));

        Livewire::test(RequestEquipmentPage::class)
            ->fillForm([
                'requested_items' => '2x navy T-shirts, size Large; replace torn station wear.',
            ])
            ->call('submit')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('employee_equipment_requests', [
            'employee_portal_id' => $employee->id,
            'user_id' => null,
            'requested_items' => '2x navy T-shirts, size Large; replace torn station wear.',
            'status' => 'Pending',
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $admin->id,
        ]);
    }
}
