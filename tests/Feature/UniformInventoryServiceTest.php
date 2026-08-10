<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Uniform;
use App\Services\UniformInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UniformInventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuing_a_uniform_links_the_inventory_item_and_decrements_stock(): void
    {
        $employee = Employee::create([
            'employee_id' => '99999',
            'name' => 'Firefighter Test',
            'rank' => 'Firefighter',
            'password' => bcrypt('test-password'),
        ]);
        $uniform = Uniform::create([
            'item_name' => 'Class B Polo',
            'size' => 'L',
            'quantity_on_hand' => 5,
            'reorder_level' => 2,
            'unit_cost' => 29.95,
        ]);

        $assignment = app(UniformInventoryService::class)->issue(
            $uniform,
            $employee,
            2,
            now()->toDateString(),
            'Initial issue',
        );

        $this->assertSame($uniform->id, $assignment->uniform_id);
        $this->assertSame($employee->id, $assignment->employee_portal_id);
        $this->assertSame('Class B Polo — Size L', $assignment->item_description);
        $this->assertSame(3, $uniform->fresh()->quantity_on_hand);
    }

    public function test_issuing_more_than_available_stock_fails_without_creating_an_assignment(): void
    {
        $employee = Employee::create([
            'employee_id' => '99998',
            'name' => 'Lieutenant Test',
            'rank' => 'Lieutenant',
            'password' => bcrypt('test-password'),
        ]);
        $uniform = Uniform::create([
            'item_name' => 'Bunker Coat',
            'size' => 'XL',
            'quantity_on_hand' => 1,
            'reorder_level' => 1,
        ]);

        try {
            app(UniformInventoryService::class)->issue($uniform, $employee, 2, now()->toDateString());
            $this->fail('Expected insufficient stock validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        $this->assertSame(1, $uniform->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('assigned_equipment', 0);
    }
}
