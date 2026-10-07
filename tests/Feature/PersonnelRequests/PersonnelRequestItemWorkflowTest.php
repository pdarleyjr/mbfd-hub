<?php

declare(strict_types=1);

namespace Tests\Feature\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\PersonnelRequestItem;
use App\Models\Uniform;
use App\Models\User;
use App\Services\PersonnelRequests\PersonnelRequestFulfillmentService;
use App\Services\PersonnelRequests\PersonnelRequestWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class PersonnelRequestItemWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Real commits verify delivery timing and replay suppression in the isolated test database.
        $this->artisan('migrate:fresh');
        $this->app[Kernel::class]->setArtisan(null);
    }

    public function test_individual_acknowledgements_and_arrival_deltas_preserve_whole_order_status_and_retry_once(): void
    {
        [$request, $employee, $admin] = $this->request();
        $item = $request->items()->firstOrFail();
        $workflow = app(PersonnelRequestWorkflowService::class);
        $workflow->acknowledgeItem($item, $admin, 'We are checking this size.', 'ack-1');
        $workflow->acknowledgeItem($item, $admin, 'We are checking this size.', 'ack-1');
        $this->assertSame('acknowledged', $item->refresh()->fulfillment_status);
        $this->assertSame(1, $employee->notifications()->count());
        $this->assertSame($item->id, $request->updates()->sole()->metadata['item_id']);
        $this->assertSame('/employee/my-requests/'.$request->public_id.'#item-'.$item->id,
            data_get($employee->notifications()->sole()->data, 'actions.0.url'));

        $workflow->arriveItem($item, $admin, 1, 'arrival-1', 'The rest will follow.', 'Supplier invoice 51');
        $workflow->arriveItem($item, $admin, 1, 'arrival-1', 'The rest will follow.', 'Supplier invoice 51');
        $this->assertSame(1, $item->refresh()->arrived_quantity);
        $this->assertSame('partially_arrived', $item->fulfillment_status);
        $this->assertSame(PersonnelRequestStatus::Ordered, $request->refresh()->status);
        $this->assertSame(0, $request->items()->skip(1)->firstOrFail()->arrived_quantity);
        $this->assertSame(2, $employee->notifications()->count());
        $this->assertSame(2, $request->updates()->count());
        $this->reject(fn () => $workflow->arriveItem($item, $admin, 3, 'arrival-over'), 'quantity');
        $this->reject(fn () => $workflow->arriveItem($item, $admin, 2, 'arrival-1'), 'idempotency_key');
        $workflow->arriveItem($item, $admin, 2, 'arrival-2');
        $this->assertSame(3, $item->refresh()->arrived_quantity);
        $this->assertSame('arrived', $item->fulfillment_status);
        $this->assertSame(PersonnelRequestStatus::Ordered, $request->refresh()->status);
    }

    public function test_partial_uniform_issues_aggregate_assignments_arrival_and_stock_without_double_decrement(): void
    {
        [$request, $employee, $admin] = $this->request();
        $item = $request->items()->firstOrFail();
        $stock = $this->stock(8);
        $service = app(PersonnelRequestFulfillmentService::class);
        $first = $service->issueUniform($item, $stock, $admin, '2026-10-07', null, 'First pickup', 1, 'issue-1');
        $retry = $service->issueUniform($item, $stock, $admin, '2026-10-07', null, 'First pickup', 1, 'issue-1');
        $this->assertTrue($first->is($retry));
        $this->assertSame(7, $stock->refresh()->quantity_on_hand);
        $this->assertSame(1, $item->refresh()->fulfilled_quantity);
        $this->assertSame(1, $item->arrived_quantity);
        $this->assertSame('partially_fulfilled', $item->fulfillment_status);
        $this->assertSame(1, $employee->notifications()->count());
        $this->reject(fn () => $service->issueUniform($item, $stock, $admin, '2026-10-07', null, null, 2, 'issue-1'), 'idempotency_key');
        $this->reject(fn () => $service->issueUniform($item, $stock, $admin, '2026-10-07', null, null, 3, 'issue-over'), 'quantity');
        $this->reject(fn () => $service->issueUniform($item, $stock, $admin, '2026-10-07', null, null, 1), 'idempotency_key');

        $second = $service->issueUniform($item, $stock, $admin, '2026-10-08', null, null, 2, 'issue-2');
        $this->assertFalse($first->is($second));
        $this->assertSame(5, $stock->refresh()->quantity_on_hand);
        $this->assertSame(3, $item->refresh()->fulfilled_quantity);
        $this->assertSame(3, $item->arrived_quantity);
        $this->assertSame('fulfilled', $item->fulfillment_status);
        $this->assertSame(3, $item->assignments()->sum('quantity'));
        $this->assertSame(2, $employee->notifications()->count());
        $this->assertSame(2, $request->updates()->where('event', 'item_fulfilled')->count());
        $legacyRetry = $service->issueUniform($item, $stock, $admin, '2026-10-08');
        $this->assertTrue($first->is($legacyRetry));
        $this->assertSame(PersonnelRequestStatus::Ordered, $request->refresh()->status);
    }

    public function test_batch_stock_failure_rolls_back_all_items_events_notifications_and_retry_manifest(): void
    {
        [$request, $employee, $admin] = $this->request();
        [$first, $second] = $request->items()->orderBy('id')->get()->all();
        $stock = $this->stock(5);
        $shortStock = $this->stock(0);
        $lines = [['item_id' => $second->id, 'quantity' => 1, 'uniform_id' => $shortStock->id],
            ['item_id' => $first->id, 'quantity' => 2, 'uniform_id' => $stock->id]];
        $service = app(PersonnelRequestFulfillmentService::class);
        $this->reject(fn () => $service->issueBatch($request, $lines, $admin, '2026-10-07', 'batch-1'), 'quantity');
        $this->assertDatabaseCount('assigned_equipment', 0);
        $this->assertSame(5, $stock->refresh()->quantity_on_hand);
        $this->assertSame(0, $first->refresh()->fulfilled_quantity);
        $this->assertSame(0, $first->arrived_quantity);
        $this->assertSame(0, $request->updates()->count());
        $this->assertSame(0, $employee->notifications()->count());

        $shortStock->update(['quantity_on_hand' => 2]);
        $assignments = $service->issueBatch($request, $lines, $admin, '2026-10-07', 'batch-1');
        $retry = $service->issueBatch($request, array_reverse($lines), $admin, '2026-10-07', 'batch-1');
        $this->assertSame(array_column($assignments, 'id'), array_column($retry, 'id'));
        $this->assertDatabaseCount('assigned_equipment', 2);
        $this->assertSame(3, $stock->refresh()->quantity_on_hand);
        $this->assertSame(1, $shortStock->refresh()->quantity_on_hand);
        $this->assertSame(2, $employee->notifications()->count());
        $this->assertSame(3, $request->updates()->count());
        $lines[0]['quantity'] = 2;
        $this->reject(fn () => $service->issueBatch($request, $lines, $admin, '2026-10-07', 'batch-1'), 'idempotency_key');
        $this->assertDatabaseCount('assigned_equipment', 2);
    }

    public function test_item_messages_are_scoped_private_notes_stay_private_and_member_replies_keep_active_status(): void
    {
        [$request, $employee, $admin] = $this->request();
        $item = $request->items()->firstOrFail();
        $workflow = app(PersonnelRequestWorkflowService::class);
        $workflow->addNote($request, $admin, null, 'Supplier-only pricing', $item, 'private-note');
        $this->assertSame(0, $employee->notifications()->count());
        $workflow->addNote($request, $admin, 'Your size is due Friday.', 'Supplier estimate', $item, 'public-note');
        $workflow->addNote($request, $admin, 'Your size is due Friday.', 'Supplier estimate', $item, 'public-note');
        $this->assertNull($request->refresh()->employee_response);
        $this->assertSame(1, $employee->notifications()->count());
        $workflow->employeeRespond($request, $employee, 'I can collect Friday.', $item, 'reply-1');
        $workflow->employeeRespond($request, $employee, 'I can collect Friday.', $item, 'reply-1');
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(PersonnelRequestStatus::Ordered, $request->refresh()->status);
        $event = $request->updates()->where('event', 'employee_responded')->sole();
        $this->assertSame($employee->id, $event->changed_by_employee_id);
        $this->assertSame($item->id, $event->metadata['item_id']);
        $this->assertSame(3, $request->updates()->count());

        $workflow->addNote($request, $admin, 'Whole order update.', null, null, 'whole-note');
        $this->assertSame('Whole order update.', $request->refresh()->employee_response);
        $this->assertSame(2, $employee->notifications()->count());
        $request->update(['status' => PersonnelRequestStatus::NeedsInformation, 'information_requested' => ['other']]);
        $workflow->employeeRespond($request, $employee, 'Here is the requested explanation.', null, 'info-reply');
        $this->assertSame(PersonnelRequestStatus::Acknowledged, $request->refresh()->status);
        $this->assertSame(2, $admin->notifications()->count());
    }

    public function test_foreign_items_members_and_unauthorized_admins_cannot_mutate_request(): void
    {
        [$request, $employee, $admin] = $this->request();
        [$otherRequest, $otherEmployee] = $this->request();
        $foreign = $otherRequest->items()->firstOrFail();
        $item = $request->items()->firstOrFail();
        $workflow = app(PersonnelRequestWorkflowService::class);
        $this->reject(fn () => $workflow->addNote($request, $admin, 'Foreign note', null, $foreign, 'foreign-note'), 'item_id');
        $this->reject(fn () => $workflow->employeeRespond($request, $employee, 'Foreign reply', $foreign, 'foreign-reply'), 'item_id');
        try {
            $workflow->employeeRespond($request, $otherEmployee, 'Unauthorized reply');
            $this->fail('Another employee replied to this request.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        try {
            $workflow->arriveItem($item, User::factory()->create(), 1, 'unauthorized-arrival');
            $this->fail('A user without logistics authority changed this item.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $item->refresh()->arrived_quantity);
        }
        $request->update(['status' => PersonnelRequestStatus::Denied]);
        $this->reject(fn () => $workflow->arriveItem($item, $admin, 1, 'denied-arrival'), 'item');
        $this->reject(fn () => app(PersonnelRequestFulfillmentService::class)->issueUniform($item, $this->stock(5), $admin, '2026-10-07', null, null, 1, 'denied-issue'), 'item');
        $this->assertSame(0, $request->updates()->count());
    }

    public function test_item_reply_does_not_clear_whole_order_information_request(): void
    {
        [$request, $employee, $admin] = $this->request();
        $request->update(['status' => PersonnelRequestStatus::NeedsInformation, 'information_requested' => ['other']]);
        $item = $request->items()->firstOrFail();
        $workflow = app(PersonnelRequestWorkflowService::class);
        $workflow->employeeRespond($request, $employee, 'Question about this shirt only.', $item, 'scoped-info-reply');
        $this->assertSame(PersonnelRequestStatus::NeedsInformation, $request->refresh()->status);
        $this->assertSame(['other'], $request->information_requested);
        $this->assertNull($request->acknowledged_at);
        $this->assertSame('Member reply: T-Shirt', $admin->notifications()->sole()->data['title']);
        $this->assertSame(PersonnelRequestStatus::NeedsInformation, $request->updates()->sole()->status);
        $workflow->employeeRespond($request, $employee, 'Here is all the requested information.', null, 'whole-info-reply');
        $this->assertSame(PersonnelRequestStatus::Acknowledged, $request->refresh()->status);
        $this->assertNotNull($request->acknowledged_at);
        $this->assertSame(2, $admin->notifications()->count());
    }

    public function test_whole_order_arrived_explicitly_marks_every_item_and_completion_requires_full_issue(): void
    {
        [$request, , $admin] = $this->request();
        [$first, $second] = $request->items()->orderBy('id')->get()->all();
        $workflow = app(PersonnelRequestWorkflowService::class);
        $service = app(PersonnelRequestFulfillmentService::class);
        $stock = $this->stock(10);
        $service->issueUniform($first, $stock, $admin, '2026-10-07', null, null, 1, 'part-1');
        $workflow->transition($request, PersonnelRequestStatus::Arrived, $admin);
        $this->assertSame(3, $first->refresh()->arrived_quantity);
        $this->assertSame('partially_fulfilled', $first->fulfillment_status);
        $this->assertSame(2, $second->refresh()->arrived_quantity);
        $this->assertSame('arrived', $second->fulfillment_status);
        $this->reject(fn () => $workflow->transition($request, PersonnelRequestStatus::Completed, $admin), 'status');
        $service->issueBatch($request, [['item_id' => $first->id, 'quantity' => 2, 'uniform_id' => $stock->id],
            ['item_id' => $second->id, 'quantity' => 2, 'uniform_id' => $stock->id]], $admin, '2026-10-07', 'remaining-batch');
        $workflow->transition($request, PersonnelRequestStatus::Completed, $admin);
        $this->assertSame(PersonnelRequestStatus::Completed, $request->refresh()->status);
        $this->assertSame(3, $first->refresh()->arrived_quantity);
        $this->assertSame(2, $second->refresh()->arrived_quantity);
    }

    public function test_outer_rollback_does_not_deliver_item_arrival_notifications(): void
    {
        [$request, $employee, $admin] = $this->request();
        $item = $request->items()->firstOrFail();
        DB::beginTransaction();
        app(PersonnelRequestWorkflowService::class)->arriveItem($item, $admin, 1, 'rolled-back');
        $this->assertSame(0, $employee->notifications()->count());
        DB::rollBack();
        $this->assertSame(0, $item->refresh()->arrived_quantity);
        $this->assertSame(0, $request->updates()->count());
        app(PersonnelRequestWorkflowService::class)->arriveItem($item, $admin, 1, 'rolled-back');
        $this->assertSame(1, $employee->notifications()->count());
    }

    public function test_equipment_partial_issue_and_batch_use_same_counters_and_provenance_without_uniform_stock(): void
    {
        [$request, $employee, $admin] = $this->request();
        $request->update(['type' => 'equipment']);
        $request->items()->update(['category' => 'equipment']);
        [$first, $second] = $request->items()->orderBy('id')->get()->all();
        $service = app(PersonnelRequestFulfillmentService::class);
        $assignment = $service->issueEquipment($first, $admin, '2026-10-07', '2031-10-07', 'PPE first pickup', 1, 'ppe-partial');
        $retry = $service->issueEquipment($first, $admin, '2026-10-07', '2031-10-07', 'PPE first pickup', 1, 'ppe-partial');
        $this->assertTrue($assignment->is($retry));
        $this->assertSame($employee->id, $assignment->employee_portal_id);
        $this->assertNull($assignment->user_id);
        $this->assertSame('Personnel PPE', $assignment->category);
        $this->assertSame('2031-10-07', $assignment->expires_at->toDateString());
        $this->reject(fn () => $service->issueBatch($request,
            [['item_id' => $second->id, 'quantity' => 1, 'uniform_id' => 999999]], $admin, '2026-10-07', 'invalid-ppe-stock'), 'uniform_id');
        $issued = $service->issueBatch($request, [['item_id' => $first->id, 'quantity' => 2],
            ['item_id' => $second->id, 'quantity' => 2]], $admin, '2026-10-08', 'ppe-batch');
        $this->assertCount(2, $issued);
        $this->assertSame(3, $first->refresh()->fulfilled_quantity);
        $this->assertSame(3, $first->arrived_quantity);
        $this->assertSame('fulfilled', $first->fulfillment_status);
        $this->assertSame(2, $second->refresh()->fulfilled_quantity);
        $this->assertSame(2, $second->arrived_quantity);
        $this->assertSame(3, $employee->notifications()->count());
        $this->assertDatabaseCount('uniforms', 0);
        $this->assertDatabaseCount('assigned_equipment', 3);
    }

    public function test_arrival_migration_backfills_only_legacy_status_and_issued_quantities_without_deleting_history(): void
    {
        $ids = [];
        foreach (['pending', 'ordered', 'arrived', 'ready_for_pickup', 'completed'] as $status) {
            [$request] = $this->request();
            $request->update(['status' => $status]);
            $item = $request->items()->firstOrFail();
            $item->update(['fulfilled_quantity' => 1]);
            $request->updates()->create(['event' => 'fixture_history', 'status' => $status]);
            $ids[$status] = [$request->id, $item->id, $item->updated_at->toISOString()];
        }
        $migration = require database_path('migrations/2026_10_07_160200_add_arrived_quantity_to_personnel_request_items.php');
        $migration->down();
        $migration->up();
        foreach ($ids as $status => [$requestId, $itemId, $updatedAt]) {
            $item = PersonnelRequestItem::query()->findOrFail($itemId);
            $this->assertSame(in_array($status, ['arrived', 'ready_for_pickup', 'completed'], true) ? 3 : 1, $item->arrived_quantity);
            $this->assertSame($updatedAt, $item->updated_at->toISOString());
            $this->assertSame(1, PersonnelRequest::query()->findOrFail($requestId)->updates()->count());
        }
        $this->assertDatabaseCount('personnel_requests', 5);
        $this->assertDatabaseCount('personnel_request_items', 10);
    }

    /** @return array{PersonnelRequest, Employee, User} */
    private function request(): array
    {
        $employee = Employee::query()->create(['employee_id' => 'ITEM-'.str()->uuid(), 'name' => 'Item Workflow Member',
            'rank' => 'Firefighter', 'password' => 'unused-fixture-credential', 'must_change_password' => false]);
        Role::findOrCreate('logistics_admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('logistics_admin');
        $publicId = (string) str()->ulid();
        $request = PersonnelRequest::query()->create([
            'public_id' => $publicId, 'request_number' => 'TEST-'.$publicId,
            'type' => 'uniform', 'status' => PersonnelRequestStatus::Ordered,
            'beneficiary_employee_id' => $employee->id, 'requester_employee_id' => $employee->id,
            'beneficiary_name' => $employee->name, 'beneficiary_employee_number' => $employee->employee_id,
            'requester_name' => $employee->name, 'requester_employee_number' => $employee->employee_id,
            'idempotency_key' => 'fixture-'.$publicId,
        ]);
        $request->items()->createMany([
            ['item_code' => 't_shirt', 'item_name' => 'T-Shirt', 'category' => 'uniform', 'quantity' => 3, 'size' => 'L'],
            ['item_code' => 'polo_shirt', 'item_name' => 'Polo Shirt', 'category' => 'uniform', 'quantity' => 2, 'size' => 'L'],
        ]);

        return [$request, $employee, $admin];
    }

    private function stock(int $quantity): Uniform
    {
        return Uniform::query()->create(['item_name' => 'Uniform Inventory', 'size' => 'L', 'quantity_on_hand' => $quantity, 'reorder_level' => 0]);
    }

    private function reject(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('An invalid action was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
