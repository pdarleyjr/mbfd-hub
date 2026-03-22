<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\EquipmentIntake;
use App\Services\SnipeItService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use App\Models\User;

class EquipmentIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin user with required role
        $this->adminUser = User::factory()->create([
            'email' => 'admin@mbfdhub.com',
        ]);

        // Assign admin role (uses spatie/laravel-permission)
        $this->adminUser->assignRole('admin');
    }

    /**
     * Test that the Equipment Intake page is accessible to admin users.
     */
    public function test_equipment_intake_page_accessible_to_admin(): void
    {
        $this->actingAs($this->adminUser);

        $response = $this->get(route('filament.admin.pages.equipment-intake'));

        $response->assertStatus(200);
    }

    /**
     * Test that the Equipment Intake page is NOT accessible to unauthenticated users.
     */
    public function test_equipment_intake_page_requires_auth(): void
    {
        $response = $this->get('/admin/equipment-intake');

        $response->assertRedirect();
    }

    /**
     * Test processVisionResult populates form fields correctly.
     */
    public function test_process_vision_result_populates_form_fields(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(EquipmentIntake::class)
            ->call('processVisionResult', 'Scott', 'Air-Pak X3 Pro', 'SN-2024-00123')
            ->assertSet('scan_brand', 'Scott')
            ->assertSet('scan_model', 'Air-Pak X3 Pro')
            ->assertSet('scan_serial', 'SN-2024-00123')
            ->assertSet('scan_processing', false)
            ->assertSet('scan_error', null);
    }

    /**
     * Test handleScanError sets error state.
     */
    public function test_handle_scan_error_sets_error_state(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(EquipmentIntake::class)
            ->call('handleScanError', 'Vision API returned 500')
            ->assertSet('scan_error', 'Vision API returned 500')
            ->assertSet('scan_processing', false);
    }

    /**
     * Test approveAndSave requires location to be set.
     */
    public function test_approve_and_save_requires_location(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(EquipmentIntake::class)
            ->set('scan_brand', 'Scott')
            ->set('scan_model', 'Air-Pak X3 Pro')
            ->set('scan_serial', 'SN-2024-00123')
            ->set('scan_location', null) // no location
            ->call('approveAndSave')
            ->assertHasNoErrors()
            // Should notify danger without location
            ->assertNotified();
    }

    /**
     * Test approveAndSave with mocked SnipeItService.
     */
    public function test_approve_and_save_calls_snipe_it(): void
    {
        $this->actingAs($this->adminUser);

        $mockSnipeIt = $this->createMock(SnipeItService::class);
        $mockSnipeIt->expects($this->once())
            ->method('createAsset')
            ->with($this->callback(function ($data) {
                return $data['brand'] === 'Scott'
                    && $data['model'] === 'Air-Pak X3 Pro'
                    && $data['serial'] === 'SN-2024-00123'
                    && $data['location_id'] === '1';
            }))
            ->willReturn(['success' => true, 'data' => ['id' => 101]]);

        $this->app->instance(SnipeItService::class, $mockSnipeIt);

        Livewire::test(EquipmentIntake::class)
            ->set('scan_brand', 'Scott')
            ->set('scan_model', 'Air-Pak X3 Pro')
            ->set('scan_serial', 'SN-2024-00123')
            ->set('scan_location', '1')
            ->call('approveAndSave')
            ->assertSet('scan_brand', null) // form should reset after success
            ->assertSet('scan_model', null)
            ->assertSet('scan_serial', null)
            ->assertNotified();
    }

    /**
     * Test resetScanForm clears all scan fields.
     */
    public function test_reset_scan_form_clears_fields(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(EquipmentIntake::class)
            ->set('scan_brand', 'Scott')
            ->set('scan_model', 'Air-Pak X3 Pro')
            ->set('scan_serial', 'SN-123')
            ->set('scan_notes', 'Test notes')
            ->call('resetScanForm')
            ->assertSet('scan_brand', null)
            ->assertSet('scan_model', null)
            ->assertSet('scan_serial', null)
            ->assertSet('scan_notes', null)
            ->assertSet('scan_error', null)
            ->assertSet('scan_success', null);
    }

    /**
     * Test bulk items: addBulkRow adds a new empty row.
     */
    public function test_add_bulk_row_adds_empty_row(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(EquipmentIntake::class)
            ->assertCount('bulk_items', 1) // starts with 1 row
            ->call('addBulkRow')
            ->assertCount('bulk_items', 2);
    }

    /**
     * Test bulk items: removeBulkRow removes the specified row.
     */
    public function test_remove_bulk_row_removes_row(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(EquipmentIntake::class)
            ->call('addBulkRow') // now 2 rows
            ->assertCount('bulk_items', 2)
            ->call('removeBulkRow', 0)
            ->assertCount('bulk_items', 1);
    }

    /**
     * Test submitBulkItems requires location.
     */
    public function test_submit_bulk_items_requires_location(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(EquipmentIntake::class)
            ->set('bulk_location', null)
            ->call('submitBulkItems')
            ->assertNotified();
    }

    /**
     * Test submitBulkItems with mocked SnipeItService.
     */
    public function test_submit_bulk_items_calls_snipe_it(): void
    {
        $this->actingAs($this->adminUser);

        $mockSnipeIt = $this->createMock(SnipeItService::class);
        $mockSnipeIt->expects($this->once())
            ->method('bulkCreateAssets')
            ->willReturn([
                ['success' => true, 'data' => ['id' => 201]],
            ]);

        $this->app->instance(SnipeItService::class, $mockSnipeIt);

        Livewire::test(EquipmentIntake::class)
            ->set('bulk_location', '1')
            ->set('bulk_items', [
                ['name' => 'Flashlight batteries', 'quantity' => 2, 'category' => 'Consumable', 'notes' => ''],
            ])
            ->call('submitBulkItems')
            ->assertNotified()
            // Form resets to one empty row
            ->assertCount('bulk_items', 1);
    }
}
