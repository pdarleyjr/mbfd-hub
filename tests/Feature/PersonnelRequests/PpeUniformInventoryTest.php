<?php

declare(strict_types=1);

namespace Tests\Feature\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Filament\Clusters\PersonnelUniformsEquipment;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelUniformResource;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelUniformResource\Pages\CreatePersonnelUniform;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelUniformResource\Pages\ListPersonnelUniforms;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\Uniform;
use App\Models\User;
use App\Services\PersonnelRequests\PersonnelRequestFulfillmentService;
use App\Services\Security\ApplicationAccessService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PpeUniformInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        foreach ([...ApplicationAccessService::UNIFORM_MANAGER_PERMISSIONS, 'admin.fleet.view'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_an_item_can_be_added_with_nothing_filled_in(): void
    {
        $this->actingAs($this->uniformManager());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreatePersonnelUniform::class)
            ->fillForm(['quantity_on_hand' => null, 'reorder_level' => null])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = Uniform::query()->sole();
        $this->assertSame(Uniform::UNNAMED_ITEM, $item->item_name);
        $this->assertSame(0, $item->quantity_on_hand);
    }

    public function test_ppe_detail_and_free_text_sizes_are_saved_and_name_is_derived(): void
    {
        $this->actingAs($this->uniformManager());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreatePersonnelUniform::class)
            ->fillForm([
                'category' => 'Firefighting PPE', 'item_type' => 'Firefighting Boots', 'manufacturer' => 'Haix',
                'model' => 'Fire Hero 3', 'size' => '10.5W', 'color' => 'Black', 'serial_number' => 'HX-123',
                'manufacture_date' => '2025-03-01', 'condition' => 'New', 'storage_location' => 'Custom shelf B',
                'quantity_on_hand' => 4,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = Uniform::query()->sole();
        $this->assertSame('Haix Fire Hero 3 Firefighting Boots', $item->item_name);
        $this->assertSame('10.5W', $item->size);
        $this->assertSame('Custom shelf B', $item->storage_location);
        $this->assertSame(4, $item->quantity_on_hand);
        $this->assertContains('Custom shelf B', PersonnelUniformResource::suggestions('storage_location', 'storage_locations'));
    }

    public function test_assigning_from_the_inventory_list_decrements_stock(): void
    {
        $this->actingAs($this->uniformManager());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $employee = $this->employee();
        $item = Uniform::query()->create(['category' => 'Firefighting PPE', 'item_type' => 'Structural Gloves', 'size' => 'Glove L', 'quantity_on_hand' => 3]);

        Livewire::test(ListPersonnelUniforms::class)
            ->assertSee('Add PPE / uniform item')
            ->callTableAction('issue', $item, ['employee_portal_id' => $employee->id, 'quantity' => 2, 'issued_at' => '2026-10-15'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, $item->refresh()->quantity_on_hand);
        $this->assertDatabaseHas('assigned_equipment', [
            'employee_portal_id' => $employee->id, 'uniform_id' => $item->id, 'quantity' => 2, 'category' => 'Personnel PPE',
        ]);
    }

    public function test_a_ppe_request_can_be_filled_from_stock_or_without_stock(): void
    {
        $admin = $this->uniformManager();
        $employee = $this->employee();
        $request = $this->equipmentRequest($employee);
        $item = $request->items()->firstOrFail();
        $stock = Uniform::query()->create(['category' => 'Firefighting PPE', 'item_type' => 'Hood', 'quantity_on_hand' => 5]);
        $service = app(PersonnelRequestFulfillmentService::class);

        $service->issueEquipment($item, $admin, '2026-10-15', null, null, 1, 'ppe-stock-1', $stock);
        $this->assertSame(4, $stock->refresh()->quantity_on_hand);

        $service->issueEquipment($item, $admin, '2026-10-15', null, null, 1, 'ppe-ordered-1');
        $this->assertSame(4, $stock->refresh()->quantity_on_hand);
        $this->assertSame(2, $item->refresh()->fulfilled_quantity);
    }

    public function test_uniform_manager_toggle_grants_and_removes_only_its_access(): void
    {
        $superAdmin = User::factory()->create(['password' => 'Correct-Horse-Battery-9', 'account_status' => 'active']);
        $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $member = User::factory()->create(['employee_id' => 'PPE-MANAGER', 'account_status' => 'active']);
        $service = app(ApplicationAccessService::class);

        $service->setUniformManager($superAdmin, $member, true, 'Correct-Horse-Battery-9', 'Handles PPE stock');
        $this->assertTrue(ApplicationAccessService::isUniformManager($member->refresh()));
        $this->assertTrue($member->hasCurrentAdminPanelEntitlement());

        $service->setUniformManager($superAdmin, $member, false, 'Correct-Horse-Battery-9', 'No longer handles PPE');
        $member->refresh();
        $this->assertFalse(ApplicationAccessService::isUniformManager($member));
        $this->assertFalse($member->hasCurrentAdminPanelEntitlement());

        $member->givePermissionTo('admin.fleet.view', 'admin.access');
        $service->setUniformManager($superAdmin, $member, true, 'Correct-Horse-Battery-9', 'Also PPE');
        $service->setUniformManager($superAdmin, $member->refresh(), false, 'Correct-Horse-Battery-9', 'Back to fleet only');
        $this->assertTrue($member->refresh()->hasCurrentAdminPanelEntitlement(), 'Other admin capabilities keep panel entry.');
        $this->assertFalse($member->hasDirectWebPermission('admin.personnel.manage'));
    }

    public function test_a_uniform_manager_sees_only_the_personnel_uniforms_and_equipment_area(): void
    {
        $this->actingAs($this->uniformManager());
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);

        $visible = [];
        foreach ($panel->getResources() as $resource) {
            if ($resource::shouldRegisterNavigation() && $resource::canAccess()) {
                $visible[] = $resource;
            }
        }
        foreach ($panel->getPages() as $page) {
            if ($page::shouldRegisterNavigation() && $page::canAccess()) {
                $visible[] = $page;
            }
        }
        $outside = array_values(array_filter($visible, fn (string $class): bool => ! str_starts_with($class, PersonnelUniformsEquipment::class)
            && $class !== \App\Filament\Pages\Dashboard::class));

        $this->assertSame([], $outside, 'Only the Personnel Uniforms & Equipment area should be offered.');
        $this->get(PersonnelUniformResource::getUrl('index'))->assertOk()->assertSee('Firefighting PPE / Uniforms');
        $this->get('/admin/personnel-uniforms-equipment/personnel-requests')->assertOk();
    }

    private function uniformManager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(ApplicationAccessService::UNIFORM_MANAGER_PERMISSIONS);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->refresh();
    }

    private function employee(): Employee
    {
        return Employee::query()->create(['employee_id' => 'PPE-'.str()->uuid(), 'name' => 'PPE Test Member',
            'rank' => 'Firefighter', 'password' => 'unused-fixture-credential', 'must_change_password' => false]);
    }

    private function equipmentRequest(Employee $employee): PersonnelRequest
    {
        $publicId = (string) str()->ulid();
        $request = PersonnelRequest::query()->create([
            'public_id' => $publicId, 'request_number' => 'TEST-'.$publicId,
            'type' => 'equipment', 'status' => PersonnelRequestStatus::Ordered,
            'beneficiary_employee_id' => $employee->id, 'requester_employee_id' => $employee->id,
            'beneficiary_name' => $employee->name, 'beneficiary_employee_number' => $employee->employee_id,
            'requester_name' => $employee->name, 'requester_employee_number' => $employee->employee_id,
            'idempotency_key' => 'fixture-'.$publicId,
        ]);
        $request->items()->create(['item_code' => 'hood', 'item_name' => 'Hood', 'category' => 'equipment', 'quantity' => 2]);

        return $request;
    }
}
