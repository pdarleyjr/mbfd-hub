<?php

declare(strict_types=1);

namespace Tests\Feature\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource\Pages\ListPersonnelRequests;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource\Pages\ViewPersonnelRequest;
use App\Models\AssignedEquipment;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\Uniform;
use App\Models\User;
use App\Services\PersonnelRequests\PersonnelRequestFulfillmentService;
use App\Services\PersonnelRequests\PersonnelRequestLifecycleService;
use App\Services\PersonnelRequests\PersonnelRequestSubmissionService;
use App\Services\PersonnelRequests\UniformAllowanceService;
use App\Services\PersonnelRequests\UniformEntitlementService;
use App\Services\RequestArchivalService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PersonnelRequestTrashTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_and_restore_retain_original_archive_state_items_history_and_private_files(): void
    {
        Storage::fake('local');
        $request = $this->request(PersonnelRequestStatus::Cancelled);
        $admin = $this->admin();
        Storage::disk('local')->put('personnel-requests/signatures/test.png', 'retained signature');
        Storage::disk('local')->put('personnel-requests/attachments/test.pdf', 'retained document');
        $request->update(['officer_signature_disk' => 'local', 'officer_signature_path' => 'personnel-requests/signatures/test.png']);
        $request->attachments()->create([
            'public_id' => (string) Str::ulid(), 'document_type' => 'other', 'disk' => 'local',
            'storage_path' => 'personnel-requests/attachments/test.pdf', 'generated_filename' => 'test.pdf',
            'original_filename' => 'evidence.pdf', 'mime_type' => 'application/pdf', 'file_size' => 17,
            'sha256' => hash('sha256', 'retained document'),
        ]);
        app(RequestArchivalService::class)->archive($request, $admin, 'Finished QA order.');
        $archivedAt = $request->refresh()->archived_at;
        $itemId = $request->items()->sole()->id;
        $history = $request->updates()->count();
        $service = app(PersonnelRequestLifecycleService::class);
        Event::fake(['eloquent.deleted: '.PersonnelRequest::class, 'eloquent.restored: '.PersonnelRequest::class]);

        $deleted = $service->delete($request, $admin, 'Remove the selected QA order from normal views.');
        $service->delete($deleted, $admin, 'Repeated click must not replace the original reason.');
        $this->assertTrue($deleted->trashed());
        $this->assertSame($admin->id, $deleted->deleted_by);
        $this->assertSame('Remove the selected QA order from normal views.', $deleted->deletion_reason);
        $this->assertSame(0, PersonnelRequest::query()->whereKey($request->id)->count());
        $this->assertSame($itemId, $deleted->items()->sole()->id);
        $this->assertSame($history + 1, $deleted->updates()->count());
        $this->assertSame(1, $deleted->attachments()->count());
        Storage::disk('local')->assertExists($deleted->officer_signature_path);
        Storage::disk('local')->assertExists('personnel-requests/attachments/test.pdf');

        $restored = $service->restore($deleted, $admin);
        $service->restore($restored, $admin);
        $this->assertFalse($restored->trashed());
        $this->assertSame(PersonnelRequestStatus::Cancelled, $restored->status);
        $this->assertTrue($archivedAt->equalTo($restored->archived_at));
        $this->assertSame('Finished QA order.', $restored->archive_reason);
        $this->assertNull($restored->deleted_by);
        $this->assertNull($restored->deletion_reason);
        $this->assertSame($history + 2, $restored->updates()->count());
        $this->assertSame('Remove the selected QA order from normal views.', $restored->updates()->where('event', 'restored_from_trash')->sole()->metadata['deletion_reason']);
        Event::assertDispatchedTimes('eloquent.deleted: '.PersonnelRequest::class, 1);
        Event::assertDispatchedTimes('eloquent.restored: '.PersonnelRequest::class, 1);
    }

    #[DataProvider('protectedOrders')]
    public function test_unresolved_arrival_and_issue_records_cannot_be_deleted(string $status, int $arrived, int $issued, bool $assignment): void
    {
        $request = $this->request(PersonnelRequestStatus::from($status));
        $item = $request->items()->sole();
        $item->update(['quantity' => max(1, $arrived, $issued), 'arrived_quantity' => $arrived, 'fulfilled_quantity' => $issued]);
        if ($assignment) {
            AssignedEquipment::query()->create([
                'employee_portal_id' => $request->beneficiary_employee_id, 'category' => 'Uniform Inventory',
                'item_description' => 'Recorded issue', 'quantity' => 1, 'issued_at' => today(),
                'source_personnel_request_item_id' => $item->id,
            ]);
        }
        try {
            app(PersonnelRequestLifecycleService::class)->delete($request, $this->admin(), 'Selected cleanup.');
            $this->fail('A request requiring workflow or inventory correction was deleted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('deletion_reason', $exception->errors());
        }
        $this->assertFalse($request->refresh()->trashed());
        $this->assertSame($arrived, $item->refresh()->arrived_quantity);
        $this->assertSame($issued, $item->fulfilled_quantity);
        $this->assertSame($assignment ? 1 : 0, AssignedEquipment::query()->count());
    }

    public static function protectedOrders(): array
    {
        return [
            'pending must first cancel' => ['pending', 0, 0, false],
            'four polos ready for pickup' => ['ready_for_pickup', 4, 0, false],
            'arrived goods even if cancelled' => ['cancelled', 1, 0, false],
            'issued goods even if cancelled' => ['cancelled', 1, 1, false],
            'assignment even with inconsistent counters' => ['cancelled', 0, 0, true],
            'completed order' => ['completed', 1, 1, true],
        ];
    }

    public function test_unauthorized_actor_and_blank_reason_cannot_delete_or_restore(): void
    {
        $request = $this->request(PersonnelRequestStatus::Cancelled);
        $outsider = User::factory()->create();
        $service = app(PersonnelRequestLifecycleService::class);
        try {
            $service->delete($request, $outsider, 'Unauthorized deletion.');
            $this->fail('An unauthorized actor deleted an order.');
        } catch (AuthorizationException) {
            $this->assertFalse($request->refresh()->trashed());
        }
        try {
            $service->delete($request, $this->admin(), '   ');
            $this->fail('Deletion without an audit reason was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('deletion_reason', $exception->errors());
        }
        $deleted = $service->delete($request, $this->admin(), 'Selected cancelled test.');
        $this->expectException(AuthorizationException::class);
        $service->restore($deleted, $outsider);
    }

    public function test_archiving_an_issued_order_retains_stock_and_issue_history_and_delete_is_blocked(): void
    {
        $request = $this->request(PersonnelRequestStatus::Pending);
        $admin = $this->admin();
        $stock = Uniform::query()->create(['item_name' => 'T-Shirt', 'size' => 'L', 'quantity_on_hand' => 5, 'reorder_level' => 0]);
        $assignment = app(PersonnelRequestFulfillmentService::class)->issueUniform($request->items()->sole(), $stock, $admin, today()->toDateString());
        app(RequestArchivalService::class)->archive($request, $admin, 'Retain the completed issue evidence.');
        try {
            app(PersonnelRequestLifecycleService::class)->delete($request, $admin, 'Attempt to delete issued goods.');
            $this->fail('An issued order was deleted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('arrived or issued goods', $exception->errors()['deletion_reason'][0]);
        }
        $this->assertSame(4, $stock->refresh()->quantity_on_hand);
        $this->assertSame($request->items()->sole()->id, $assignment->refresh()->source_personnel_request_item_id);
        $this->assertSame(1, $request->items()->sole()->fulfilled_quantity);
    }

    public function test_archive_does_not_change_allowance_and_trash_never_resets_historical_allowance_or_jacket_holds(): void
    {
        $request = $this->request(PersonnelRequestStatus::Pending);
        $employee = $request->beneficiary;
        $allowances = app(UniformAllowanceService::class);
        $this->assertSame(['tshirts' => 1], $allowances->usedByGroup($employee));
        app(RequestArchivalService::class)->archive($request, $this->admin(), 'Retain annual usage.');
        $this->assertSame(['tshirts' => 1], $allowances->usedByGroup($employee));
        // Legacy/inconsistent trash metadata must not erase the recorded allowance.
        $request->forceFill(['deleted_at' => now()])->save();
        $this->assertSame(['tshirts' => 1], $allowances->usedByGroup($employee));
        $request->items()->sole()->update(['item_code' => 'jacket']);
        $this->assertFalse(app(UniformEntitlementService::class)->jacketEligibility($employee)['can_order']);
    }

    public function test_capability_only_manager_can_delete_and_restore_without_a_legacy_admin_role(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo([
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.personnel.manage', 'web'),
        ]);
        $request = $this->request(PersonnelRequestStatus::Cancelled);
        $service = app(PersonnelRequestLifecycleService::class);
        $this->assertTrue($service->delete($request, $manager, 'Selected cancelled test.')->trashed());
        $this->assertFalse($service->restore($request, $manager)->trashed());
    }

    public function test_stale_submission_retry_of_trashed_order_returns_clear_validation_without_recreating_it(): void
    {
        $request = $this->request(PersonnelRequestStatus::Cancelled);
        app(PersonnelRequestLifecycleService::class)->delete($request, $this->admin(), 'Selected cancelled test.');
        try {
            app(PersonnelRequestSubmissionService::class)->submitUniform($request->beneficiary, [], $request->idempotency_key);
            $this->fail('A deleted order submission was accepted again.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('in Trash', $exception->errors()['idempotency_key'][0]);
        }
        $this->assertSame(1, PersonnelRequest::withTrashed()->count());
        $this->assertSame(1, $request->items()->count());
        $this->assertSame(1, $request->updates()->where('event', 'deleted')->count());
    }

    public function test_super_admin_cannot_purge_or_update_a_trashed_order_or_delete_an_equipment_request(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $request = $this->request(PersonnelRequestStatus::Cancelled);
        $deleted = app(PersonnelRequestLifecycleService::class)->delete($request, $admin, 'Selected cancelled test.');
        $this->assertFalse(Gate::forUser($admin)->allows('forceDelete', $deleted));
        $this->assertFalse(Gate::forUser($admin)->allows('forceDeleteAny', PersonnelRequest::class));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $deleted));
        $this->assertTrue(Gate::forUser($admin)->allows('restore', $deleted));
        $equipment = $this->request(PersonnelRequestStatus::Cancelled);
        $equipment->update(['type' => 'equipment']);
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $equipment));
    }

    public function test_admin_archive_and_trash_filters_are_distinct_and_deleted_order_is_hidden_from_member(): void
    {
        $this->withoutVite();
        $active = $this->request(PersonnelRequestStatus::Cancelled);
        $archived = $this->request(PersonnelRequestStatus::Cancelled);
        $trashed = $this->request(PersonnelRequestStatus::Cancelled);
        $admin = $this->admin();
        app(RequestArchivalService::class)->archive($archived, $admin, 'Keep archived.');
        app(PersonnelRequestLifecycleService::class)->delete($trashed, $admin, 'Selected cancelled test.');
        $this->assertSame(1, PersonnelRequest::query()->active()->count());
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListPersonnelRequests::class)
            ->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$archived, $trashed])
            ->filterTable('archive_state', 'archived')
            ->assertCanSeeTableRecords([$archived])->assertCanNotSeeTableRecords([$active, $trashed])
            ->filterTable('archive_state', 'trash')
            ->assertCanSeeTableRecords([$trashed])->assertCanNotSeeTableRecords([$active, $archived]);
        Livewire::test(ViewPersonnelRequest::class, ['record' => $active->public_id])
            ->assertActionExists('archive')->assertActionExists('delete_order');
        Livewire::test(ViewPersonnelRequest::class, ['record' => $trashed->public_id])
            ->assertActionVisible('restore_from_trash')->assertActionHidden('archive')->assertActionHidden('delete_order');
        $this->actingAs($trashed->beneficiary, 'employee')->get('/employee/my-requests/'.$trashed->public_id)->assertNotFound();
    }

    #[DataProvider('directMutations')]
    public function test_direct_model_mutation_cannot_bypass_audited_trash_service(string $method): void
    {
        $request = $this->request(PersonnelRequestStatus::Cancelled);
        $this->expectException(\LogicException::class);
        $request->{$method}();
    }

    public static function directMutations(): array
    {
        return [['delete'], ['forceDelete'], ['restore']];
    }

    public function test_delete_and_restore_update_the_same_page_header_without_a_reload(): void
    {
        $this->withoutVite();
        $request = $this->request(PersonnelRequestStatus::Cancelled);
        $original = $request->refresh()->getRawOriginal();
        $item = $request->items()->sole()->getRawOriginal();
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::test(ViewPersonnelRequest::class, ['record' => $request->public_id]);
        $page->assertActionVisible('delete_order')
            ->callAction('delete_order', ['deletion_reason' => 'Verify immediate lifecycle controls.'])
            ->assertHasNoActionErrors()
            ->assertActionVisible('restore_from_trash')
            ->assertActionHidden('delete_order')
            ->assertActionHidden('archive')
            ->assertActionHidden('add_note');
        $this->assertSoftDeleted($request);

        $page->callAction('restore_from_trash')->assertHasNoActionErrors()
            ->assertActionHidden('restore_from_trash')
            ->assertActionVisible('delete_order')
            ->assertActionVisible('archive')
            ->assertActionVisible('add_note');
        $this->assertNotSoftDeleted($request);
        foreach (['status', 'assigned_admin_id', 'acknowledged_at', 'completed_at', 'cancelled_at'] as $field) {
            $this->assertSame($original[$field], $request->refresh()->getRawOriginal($field), $field);
        }
        $this->assertSame($item, $request->items()->sole()->getRawOriginal());
        $this->assertSame(1, $request->updates()->where('event', 'deleted')->count());
        $this->assertSame(1, $request->updates()->where('event', 'restored_from_trash')->count());
    }

    public function test_an_initially_trashed_page_restores_all_controls_and_can_delete_again_without_a_reload(): void
    {
        $this->withoutVite();
        $request = $this->request(PersonnelRequestStatus::Cancelled);
        $admin = $this->admin();
        app(PersonnelRequestLifecycleService::class)->delete($request, $admin, 'Retained QA order.');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ViewPersonnelRequest::class, ['record' => $request->public_id])
            ->assertActionVisible('restore_from_trash')
            ->callAction('restore_from_trash')->assertHasNoActionErrors()
            ->assertActionHidden('restore_from_trash')
            ->assertActionVisible('archive')->assertActionVisible('delete_order')->assertActionVisible('add_note')
            ->callAction('add_note', ['idempotency_key' => (string) Str::uuid(), 'internal_note' => 'Private note after restoring the mounted order.'])
            ->assertHasNoActionErrors()
            ->callAction('delete_order', ['deletion_reason' => 'Return QA order to Trash.'])->assertHasNoActionErrors()
            ->assertActionVisible('restore_from_trash')->assertActionHidden('delete_order')->assertActionHidden('archive');

        $this->assertSoftDeleted($request);
        $this->assertSame(PersonnelRequestStatus::Cancelled, $request->refresh()->status);
        $this->assertSame(2, $request->updates()->where('event', 'deleted')->count());
        $this->assertSame(1, $request->updates()->where('event', 'restored_from_trash')->count());
        $this->assertDatabaseHas('personnel_request_updates', [
            'personnel_request_id' => $request->id, 'event' => 'note_added',
            'internal_note' => 'Private note after restoring the mounted order.', 'employee_visible_note' => null,
        ]);
    }

    private function request(PersonnelRequestStatus $status): PersonnelRequest
    {
        $employee = Employee::query()->create(['employee_id' => 'TRASH-'.Str::random(8), 'name' => 'Trash Fixture Member', 'rank' => 'Firefighter']);
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform($employee, [['item_code' => 't_shirt', 'size' => 'L', 'quantity' => 1]], 'trash-'.Str::uuid());
        $request->update(['status' => $status]);

        return $request;
    }

    private function admin(): User
    {
        Role::findOrCreate('logistics_admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('logistics_admin');
        $admin->givePermissionTo([
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.personnel.view', 'web'),
            Permission::findOrCreate('admin.personnel.manage', 'web'),
        ]);

        return $admin;
    }
}
