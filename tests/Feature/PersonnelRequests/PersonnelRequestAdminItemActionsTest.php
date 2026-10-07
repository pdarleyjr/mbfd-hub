<?php

declare(strict_types=1);

namespace Tests\Feature\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelEmployeeResource\Pages\ViewPersonnelEmployee;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource\Pages\ViewPersonnelRequest;
use App\Filament\Resources\UniformResource\Pages\ListUniforms;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\Uniform;
use App\Models\User;
use App\Services\PersonnelRequests\PersonnelRequestFulfillmentService;
use App\Services\PersonnelRequests\PersonnelRequestSubmissionService;
use App\Services\PersonnelRequests\PersonnelRequestWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PersonnelRequestAdminItemActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_acknowledge_message_and_record_partial_arrival_for_one_item(): void
    {
        $request = $this->request();
        $item = $request->items->first();
        $page = $this->page($request);
        $page->assertActionExists('acknowledge_item')->assertActionExists('arrive_item')->assertActionExists('issue_batch')
            ->assertActionExists('issue_uniform')
            ->callAction('acknowledge_item', ['item_id' => $item->id, 'message' => 'This shirt size is being confirmed.'])->assertHasNoActionErrors()
            ->callAction('arrive_item', ['item_id' => $item->id, 'quantity' => 1, 'employee_note' => 'One shirt has arrived.'])->assertHasNoActionErrors()
            ->callAction('add_note', ['item_id' => $item->id, 'employee_note' => 'The remaining shirts are on the next delivery.', 'internal_note' => 'Supplier invoice checked.'])->assertHasNoActionErrors()
            ->assertSee('Arrived')->assertSee('Issued')->assertSee('Item messages')
            ->assertSee('One shirt has arrived.')->assertSee('Supplier invoice checked.');

        $this->assertSame(1, $item->refresh()->arrived_quantity);
        $this->assertSame(0, $item->fulfilled_quantity);
        $this->assertSame(0, $request->items->last()->refresh()->arrived_quantity);
        $this->assertSame(PersonnelRequestStatus::Pending, $request->refresh()->status);
        foreach (['item_acknowledged', 'item_arrived', 'note_added'] as $event) {
            $this->assertSame($item->id, $request->updates()->where('event', $event)->sole()->metadata['item_id']);
        }
    }

    public function test_partial_uniform_issue_uses_remaining_defaults_and_stable_key_across_validation(): void
    {
        $request = $this->request();
        $item = $request->items->first();
        $stock = $this->stock('T-Shirt', 1);
        $page = $this->page($request)->mountAction('issue_uniform')
            ->setActionData(['item_id' => $item->id, 'uniform_id' => $stock->id, 'quantity' => 2, 'issued_at' => today()->toDateString()]);
        $key = $page->get('mountedActionsData.0.idempotency_key');
        $this->assertTrue(Str::isUuid($key));
        $page->callMountedAction()->assertHasActionErrors(['quantity'])
            ->assertSet('mountedActionsData.0.idempotency_key', $key);

        $stock->update(['quantity_on_hand' => 4]);
        $page->callMountedAction()->assertHasNoActionErrors()->assertSee('Partially Fulfilled');
        $this->assertSame(2, $item->refresh()->fulfilled_quantity);
        $this->assertSame(2, $stock->refresh()->quantity_on_hand);
        $this->assertSame($key, $request->updates()->where('event', 'item_fulfilled')->sole()->metadata['idempotency_key']);
        $page->mountAction('issue_uniform')->setActionData(['item_id' => $item->id])
            ->assertActionDataSet(['quantity' => 1]);
    }

    public function test_batch_issue_keeps_unselected_items_and_updates_each_selected_inventory(): void
    {
        $request = $this->request();
        $items = $request->items->keyBy('item_code');
        $shirt = $this->stock('T-Shirt', 5);
        $polo = $this->stock('Polo Shirt', 4);
        $page = $this->page($request)->mountAction('issue_batch');
        $lines = $page->get('mountedActionsData.0.lines');
        $this->assertCount(3, $lines);
        foreach ($lines as &$line) {
            if ($line['item_id'] === $items['t_shirt']->id) {
                $line['quantity'] = 1;
                $line['uniform_id'] = $shirt->id;
            } elseif ($line['item_id'] === $items['polo_shirt']->id) {
                $line['quantity'] = 2;
                $line['uniform_id'] = $polo->id;
            }
        }
        unset($line);
        $page->set('mountedActionsData.0.lines', $lines)->callMountedAction()->assertHasNoActionErrors();

        $this->assertSame(1, $items['t_shirt']->refresh()->fulfilled_quantity);
        $this->assertSame(2, $items['polo_shirt']->refresh()->fulfilled_quantity);
        $this->assertSame(0, $items['belt']->refresh()->fulfilled_quantity);
        $this->assertSame(4, $shirt->refresh()->quantity_on_hand);
        $this->assertSame(2, $polo->refresh()->quantity_on_hand);
        $this->assertDatabaseCount('assigned_equipment', 2);
        $this->assertSame(PersonnelRequestStatus::Pending, $request->refresh()->status);
    }

    public function test_foreign_request_item_cannot_be_targeted_by_an_admin_item_action(): void
    {
        $request = $this->request();
        $foreign = $this->request()->items->first();
        $this->page($request)->callAction('arrive_item', ['item_id' => $foreign->id, 'quantity' => 1])
            ->assertHasActionErrors(['item_id']);
        $this->assertSame(0, $foreign->refresh()->arrived_quantity);
        $this->assertSame(0, $request->updates()->where('event', 'item_arrived')->count());
    }

    #[DataProvider('lostResponseActions')]
    public function test_saved_action_receipt_can_replay_after_a_lost_response_exhausts_remaining_quantity(string $action): void
    {
        $request = $this->request();
        $item = $request->items->first();
        $request->items()->where('id', '!=', $item->id)->delete();
        $request->unsetRelation('items');
        $stock = $this->stock('T-Shirt', 3);
        $page = $this->page($request)->mountAction($action);
        $key = $page->get('mountedActionsData.0.idempotency_key');
        if ($action === 'issue_batch') {
            $lines = $page->get('mountedActionsData.0.lines');
            foreach ($lines as &$line) {
                $line['quantity'] = 3;
                $line['uniform_id'] = $stock->id;
            }
            unset($line);
            $page->set('mountedActionsData.0.lines', $lines);
            app(PersonnelRequestFulfillmentService::class)->issueBatch($request,
                [['item_id' => $item->id, 'quantity' => 3, 'uniform_id' => $stock->id]], auth()->user(), today()->toDateString(), $key);
        } elseif ($action === 'issue_uniform') {
            $page->setActionData(['item_id' => $item->id, 'quantity' => 3, 'uniform_id' => $stock->id]);
            app(PersonnelRequestFulfillmentService::class)->issueUniform($item, $stock, auth()->user(), today()->toDateString(), quantity: 3, idempotencyKey: $key);
        } else {
            $page->setActionData(['item_id' => $item->id, 'quantity' => 3]);
            app(PersonnelRequestWorkflowService::class)->arriveItem($item, auth()->user(), 3, $key);
        }

        // The commit happened, but the client still has its pre-response mounted form.
        $page->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(3, $item->refresh()->arrived_quantity);
        if ($action !== 'arrive_item') {
            $this->assertSame(3, $item->fulfilled_quantity);
            $this->assertSame(0, $stock->refresh()->quantity_on_hand);
            $this->assertDatabaseCount('assigned_equipment', 1);
            $this->assertSame(1, $request->updates()->where('event', 'item_fulfilled')->count());
        } else {
            $this->assertSame(0, $item->fulfilled_quantity);
            $this->assertSame(1, $request->updates()->where('event', 'item_arrived')->count());
        }
    }

    public static function lostResponseActions(): array
    {
        return [['issue_uniform'], ['issue_batch'], ['arrive_item']];
    }

    public function test_global_arrival_returns_fresh_item_counts_and_status_to_admin_page(): void
    {
        $request = $this->request();
        $page = $this->page($request)->callAction('acknowledge')->assertHasNoActionErrors()
            ->callAction('order')->assertHasNoActionErrors()->callAction('arrived')->assertHasNoActionErrors();
        $record = $page->instance()->getRecord();
        $this->assertSame(PersonnelRequestStatus::Arrived, $record->status);
        foreach ($record->items as $item) {
            $this->assertSame($item->quantity, $item->arrived_quantity);
        }
    }

    public function test_both_existing_manual_jacket_assignment_paths_enforce_cap_and_shared_issue_cycle(): void
    {
        $request = $this->request();
        $employee = $request->beneficiary;
        $this->page($request);
        auth()->user()->givePermissionTo([
            Permission::findOrCreate('view_any_uniform', 'web'), Permission::findOrCreate('view_uniform', 'web'),
        ]);
        $data = ['category' => 'Jacket', 'item_description' => 'Department jacket', 'quantity' => 1, 'issued_at' => today()->toDateString()];
        Livewire::test(ViewPersonnelEmployee::class, ['record' => $employee->id])->callAction('assign_ppe', $data)->assertHasNoActionErrors();
        Livewire::test(ViewPersonnelEmployee::class, ['record' => $employee->id])->callAction('assign_ppe', $data)->assertHasActionErrors(['issued_at']);
        Livewire::test(ListUniforms::class)->callAction('assign_equipment', $data + ['employee_portal_id' => $employee->id])->assertHasActionErrors(['issued_at']);
        Livewire::test(ListUniforms::class)->callAction('assign_equipment', array_replace($data, ['quantity' => 2]) + ['employee_portal_id' => $employee->id])->assertHasActionErrors(['quantity']);
        $this->assertDatabaseCount('assigned_equipment', 1);
    }

    private function request(): PersonnelRequest
    {
        $employee = Employee::query()->create(['employee_id' => 'ADMIN-ITEM-'.Str::random(8), 'name' => 'Admin Item Test Member',
            'rank' => 'Firefighter', 'password' => 'unused-fixture-credential', 'must_change_password' => false]);

        return app(PersonnelRequestSubmissionService::class)->submitUniform($employee, [
            ['item_code' => 't_shirt', 'quantity' => 3, 'metadata' => ['size' => 'L']],
            ['item_code' => 'polo_shirt', 'quantity' => 2, 'metadata' => ['size' => 'L']],
            ['item_code' => 'belt', 'quantity' => 1, 'metadata' => ['size' => 'L']],
        ], 'admin-item-'.Str::uuid());
    }

    private function stock(string $name, int $quantity): Uniform
    {
        return Uniform::query()->create(['item_name' => $name, 'size' => 'L', 'quantity_on_hand' => $quantity, 'reorder_level' => 0]);
    }

    private function page(PersonnelRequest $request): Testable
    {
        $this->withoutVite();
        Role::findOrCreate('logistics_admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('logistics_admin');
        $admin->givePermissionTo([
            Permission::findOrCreate('admin.access', 'web'), Permission::findOrCreate('admin.personnel.view', 'web'),
            Permission::findOrCreate('admin.personnel.manage', 'web'),
        ]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Livewire::test(ViewPersonnelRequest::class, ['record' => $request->public_id]);
    }
}
