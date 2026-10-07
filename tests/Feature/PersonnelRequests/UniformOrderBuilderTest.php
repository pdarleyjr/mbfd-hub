<?php

declare(strict_types=1);

namespace Tests\Feature\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource\Pages\ListPersonnelRequests;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource\Pages\ViewPersonnelRequest;
use App\Filament\Employee\Pages\RequestEquipmentPage;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\Uniform;
use App\Models\User;
use App\Services\BidAssignmentReceiver;
use App\Services\PersonnelRequests\PersonnelRequestFulfillmentService;
use App\Services\PersonnelRequests\PersonnelRequestSubmissionService;
use App\Services\PersonnelRequests\PersonnelRequestWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class UniformOrderBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_overage_creates_pending_request_with_server_derived_snapshot_and_note(): void
    {
        $employee = $this->employee('UNIFORM-STRUCTURED');
        $this->assignment($employee, 'Rescue 11', 2027);
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform(
            $employee, [$this->item('t_shirt', ['size' => 'XXXL'], 12)], 'structured-overage-1', [
                'member_note' => '  Replacement shirts after an assignment change.  ',
                'entitlement_profile' => 'combat', 'warnings_at_submission' => [],
                'bid_assignment_snapshot' => ['unit_label' => 'Engine 2'],
            ],
        );

        $this->assertSame(PersonnelRequestStatus::Pending, $request->status);
        $this->assertSame('uniform', $request->type->value);
        $this->assertSame($employee->id, $request->beneficiary_employee_id);
        $this->assertSame($employee->id, $request->requester_employee_id);
        $this->assertSame('rescue', $request->metadata['entitlement_profile']);
        $this->assertSame('Rescue 11', $request->metadata['bid_assignment_snapshot']['unit_label']);
        $this->assertSame(2027, $request->metadata['bid_year']);
        $this->assertSame('Replacement shirts after an assignment change.', $request->metadata['member_note']);
        $this->assertNotEmpty($request->metadata['warnings_at_submission']);
        $this->assertSame(12, $request->items->sole()->quantity);
        $this->assertSame('XXXL', $request->items->sole()->size);
        $this->assertSame(['sleeve' => 'short', 'size' => 'XXXL'], $request->items->sole()->metadata);
        $this->assertSame('submitted', $request->updates->sole()->event);
        $this->assertDatabaseCount('assigned_equipment', 0);
    }

    public function test_procurement_attributes_persist_with_canonical_legacy_sizes_and_server_product_names(): void
    {
        $employee = $this->employee('UNIFORM-SIZES');
        $items = [
            $this->item('uniform_pants', ['waist' => 34, 'inseam' => 32, 'cut' => 'mens', 'untrusted' => 'discard']),
            $this->item('jumpsuit', ['jumpsuit_chest' => 44, 'jumpsuit_length' => 'Regular']),
            $this->item('class_a_long_sleeve_shirt', ['neck' => 16.5, 'sleeve_length' => 34]),
            $this->item('tie', []),
        ];
        $items[0]['item_name'] = 'Browser supplied product';
        $items[0]['size'] = 'Wrong browser summary';
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform($employee, $items, 'structured-sizes-1');
        $byCode = $request->items->keyBy('item_code');

        $this->assertSame('5.11 Tactical Pants', $byCode['uniform_pants']->item_name);
        $this->assertSame("34W × 32L / Men's", $byCode['uniform_pants']->size);
        $this->assertEquals(['waist' => 34, 'inseam' => 32, 'cut' => 'mens'], $byCode['uniform_pants']->metadata);
        $this->assertSame('Chest 44 / Regular', $byCode['jumpsuit']->size);
        $this->assertSame('Neck 16.5 / Sleeve 34', $byCode['class_a_long_sleeve_shirt']->size);
        $this->assertSame('Standard', $byCode['tie']->size);
        $this->assertSame(['uniform'], $request->items->pluck('category')->unique()->values()->all());
    }

    public function test_legacy_size_only_payloads_and_historical_requests_still_render(): void
    {
        $this->withoutVite();
        $employee = $this->employee('UNIFORM-LEGACY');
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform($employee, [
            ['item_code' => 'uniform_pants', 'size' => '34x32', 'quantity' => 1],
        ], 'legacy-size-only-1');

        $this->assertSame('34x32', $request->items->sole()->size);
        $this->assertNull($request->items->sole()->metadata);
        $request->update(['metadata' => null]);
        $this->actingAs($employee, 'employee')->get('/employee/my-requests/'.$request->public_id)
            ->assertOk()->assertSee('34x32')->assertSee($request->request_number);
        $this->actingAs($this->admin())->get('/admin/personnel-uniforms-equipment/personnel-requests/'.$request->public_id)
            ->assertOk()->assertSee('34x32')->assertSee('Workflow history');
    }

    public function test_retry_keeps_original_items_note_snapshot_and_single_submission_event(): void
    {
        $employee = $this->employee('UNIFORM-IDEMPOTENT');
        $this->assignment($employee, 'Engine 2', 2027);
        $service = app(PersonnelRequestSubmissionService::class);
        $first = $service->submitUniform($employee, [$this->item('t_shirt', ['size' => 'L'], 5)], 'uniform-repeat-1', ['member_note' => 'First note']);
        $this->assignment($employee, 'Rescue Float', 2028);
        $retry = $service->submitUniform($employee, [], 'uniform-repeat-1', ['member_note' => 'Retry note']);

        $this->assertTrue($first->is($retry));
        $this->assertSame('First note', $retry->metadata['member_note']);
        $this->assertSame('combat', $retry->metadata['entitlement_profile']);
        $this->assertSame(2027, $retry->metadata['bid_year']);
        $this->assertSame(5, $retry->items->sole()->quantity);
        $this->assertDatabaseCount('personnel_requests', 1);
        $this->assertDatabaseCount('personnel_request_items', 1);
        $this->assertDatabaseCount('personnel_request_updates', 1);

        $this->expectException(ValidationException::class);
        $service->submitUniform($this->employee('UNIFORM-OTHER'), [$this->item('t_shirt', ['size' => 'M'])], 'uniform-repeat-1');
    }

    public function test_assignment_snapshot_remains_historical_after_a_new_bid_is_received(): void
    {
        $employee = $this->employee('UNIFORM-HISTORY');
        $this->assignment($employee, 'Captain 5', 2027);
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform(
            $employee, [$this->item('jumpsuit', ['jumpsuit_chest' => 46, 'jumpsuit_length' => 'Regular'], 2)], 'uniform-history-1',
        );
        $metadata = $request->metadata;
        $this->assignment($employee, 'Marine Float', 2028);

        $this->assertSame($metadata, $request->refresh()->metadata);
        $this->assertSame('Captain 5', $request->metadata['assignment_label']);
        $this->assertSame('rescue', $request->metadata['entitlement_profile']);
        $this->assertSame(1, $request->metadata['calculated_swap_credits']);
        $this->assertSame(3, $request->metadata['standard_allowances']['jumpsuits']);
    }

    public function test_invalid_procurement_inputs_and_invalid_notes_fail_before_persistence(): void
    {
        $employee = $this->employee('UNIFORM-INVALID');
        $service = app(PersonnelRequestSubmissionService::class);
        foreach ([
            ['items' => ['uniform_pants' => $this->item('uniform_pants', ['waist' => 34])], 'metadata' => [], 'error' => 'items.uniform_pants.metadata.inseam'],
            ['items' => ['t_shirt' => $this->item('t_shirt', ['size' => 'INVALID'])], 'metadata' => [], 'error' => 'items.t_shirt.metadata.size'],
            ['items' => ['t_shirt' => $this->item('t_shirt', ['size' => 'L'], -1)], 'metadata' => [], 'error' => 'items.t_shirt.quantity'],
            ['items' => [$this->item('t_shirt', ['size' => 'L'])], 'metadata' => ['member_note' => ['invalid']], 'error' => 'member_note'],
            ['items' => [$this->item('t_shirt', ['size' => 'L'])], 'metadata' => ['member_note' => str_repeat('n', 4001)], 'error' => 'member_note'],
        ] as $index => $case) {
            try {
                $service->submitUniform($employee, $case['items'], 'uniform-invalid-'.$index, $case['metadata']);
                $this->fail('Invalid ordering information was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($case['error'], $exception->errors());
            }
        }
        $this->assertDatabaseCount('personnel_requests', 0);
    }

    public function test_canonical_employee_builder_submission_appears_in_my_requests(): void
    {
        $this->withoutVite();
        $employee = $this->employee('UNIFORM-PORTAL');
        $this->assignment($employee, 'Engine 2', 2027);
        $this->get('/employee/request-equipment')->assertRedirect('/login');
        $this->actingAs($employee, 'employee')->get('/employee/request-equipment')->assertOk()->assertSee('Engine 2');
        $this->assertSame($employee->id, auth('web')->user()->employee_profile_id);
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        Livewire::test(RequestEquipmentPage::class)
            ->set('data.items.t_shirt.quantity', 5)
            ->set('data.items.t_shirt.metadata.size', 'L')
            ->set('data.member_note', 'Extra replacements requested for review.')
            ->call('submit')->assertHasNoErrors();

        $request = PersonnelRequest::query()->sole();
        $this->assertSame($employee->id, $request->beneficiary_employee_id);
        $this->assertSame(5, $request->items()->sole()->quantity);
        $this->get('/employee/my-requests')->assertOk()->assertSee($request->request_number)->assertSee('Pending');
        $this->get('/employee/my-requests/'.$request->public_id)->assertOk()
            ->assertSee('Extra replacements requested for review.')->assertSee('Size L');
    }

    public function test_livewire_validation_preserves_note_and_selected_inputs_for_correction(): void
    {
        $employee = $this->employee('UNIFORM-PORTAL-INVALID');
        $this->actingAs($employee, 'employee');
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        Livewire::test(RequestEquipmentPage::class)
            ->set('data.items.uniform_pants.quantity', 1)
            ->set('data.items.uniform_pants.metadata.waist', 34)
            ->set('data.member_note', 'Keep this note while I correct sizing.')
            ->call('submit')
            ->assertHasErrors(['data.items.uniform_pants.metadata.inseam'])
            ->assertSet('data.member_note', 'Keep this note while I correct sizing.')
            ->assertSet('data.items.uniform_pants.quantity', 1);
        $this->assertDatabaseCount('personnel_requests', 0);
    }

    #[DataProvider('malformedBuilderPayloads')]
    public function test_builder_rejects_malformed_payload_without_server_errors(string $path, mixed $value, string $error): void
    {
        $employee = $this->employee('UNIFORM-MALFORMED');
        $this->actingAs($employee, 'employee');
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        Livewire::test(RequestEquipmentPage::class)
            ->set($path, $value)
            ->call('submit')
            ->assertHasErrors([$error]);

        $this->assertDatabaseCount('personnel_requests', 0);
    }

    public static function malformedBuilderPayloads(): array
    {
        return [
            'non-array catalog rows' => ['data.items', 'invalid-rows', 'data.items'],
            'non-array product row' => ['data.items.uniform_pants', 'invalid-row', 'data.items.uniform_pants'],
            'null product row' => ['data.items.uniform_pants', null, 'data.items.uniform_pants'],
            'non-array ordering fields' => ['data.items.uniform_pants', ['item_code' => 'uniform_pants', 'quantity' => 1, 'metadata' => '34x32'], 'data.items.uniform_pants.metadata'],
            'missing metadata cannot use legacy sizing fallback' => ['data.items.uniform_pants', ['item_code' => 'uniform_pants', 'quantity' => 1, 'size' => '34x32'], 'data.items.uniform_pants.metadata'],
            'array product code' => ['data.items.uniform_pants', ['item_code' => ['uniform_pants'], 'quantity' => 1, 'metadata' => ['waist' => 34, 'inseam' => 32]], 'data.items.uniform_pants.item_code'],
            'array quantity' => ['data.items.t_shirt.quantity', ['invalid'], 'data.items.t_shirt.quantity'],
        ];
    }

    public function test_cleared_required_field_has_live_error_until_product_is_removed(): void
    {
        $employee = $this->employee('UNIFORM-CLEAR-SELECTED');
        $this->actingAs($employee, 'employee');
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        Livewire::test(RequestEquipmentPage::class)
            ->set('data.items.uniform_pants.quantity', 1)
            ->set('data.items.uniform_pants.metadata.waist', 34)
            ->set('data.items.uniform_pants.metadata.inseam', 32)
            ->set('data.items.uniform_pants.metadata.waist', '')
            ->assertHasErrors(['data.items.uniform_pants.metadata.waist'])
            ->set('data.items.uniform_pants.quantity', 0)
            ->assertHasNoErrors();

        $this->assertDatabaseCount('personnel_requests', 0);
    }

    public function test_unselected_products_do_not_require_sizing_or_prevent_other_items_from_submitting(): void
    {
        $employee = $this->employee('UNIFORM-CLEAR-UNSELECTED');
        $this->actingAs($employee, 'employee');
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        Livewire::test(RequestEquipmentPage::class)
            ->set('data.items.uniform_pants.metadata.waist', '')
            ->assertHasNoErrors()
            ->set('data.items.t_shirt.quantity', 1)
            ->set('data.items.t_shirt.metadata.size', 'L')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame('t_shirt', PersonnelRequest::query()->sole()->items()->sole()->item_code);
    }

    public function test_existing_admin_queue_displays_snapshot_advisories_note_and_readable_ordering_details(): void
    {
        $this->withoutVite();
        $employee = $this->employee('UNIFORM-ADMIN');
        $this->assignment($employee, 'Rescue 11', 2027);
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform($employee, [
            $this->item('uniform_pants', ['waist' => 36, 'inseam' => 32, 'cut' => 'mens'], 6),
        ], 'uniform-admin-1', ['member_note' => 'Replacement needed. <script>alert(1)</script>']);
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListPersonnelRequests::class)->assertCanSeeTableRecords([$request]);
        Livewire::test(ViewPersonnelRequest::class, ['record' => $request->public_id])
            ->assertSee('Bid / assignment at submission')->assertSee('Rescue 11')->assertSee('2027')
            ->assertSee('Notes for Support Services')->assertSee('Replacement needed.')
            ->assertSee('Allocation advisories at submission')->assertSee('Waist (inches): 36')
            ->assertSee('Inseam (inches): 32')->assertSee("Requested cut: Men's")
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertActionExists('acknowledge')->assertActionExists('request_information')
            ->assertActionExists('issue_uniform')->assertActionExists('add_note');
    }

    public function test_structured_request_uses_existing_workflow_and_idempotent_inventory_fulfillment(): void
    {
        $employee = $this->employee('UNIFORM-FULFILL');
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform(
            $employee, [$this->item('t_shirt', ['size' => 'L'], 2)], 'uniform-fulfill-1', ['member_note' => 'Replace worn shirts.'],
        );
        $admin = $this->admin();
        $workflow = app(PersonnelRequestWorkflowService::class);
        foreach ([PersonnelRequestStatus::Acknowledged, PersonnelRequestStatus::Ordered, PersonnelRequestStatus::Arrived, PersonnelRequestStatus::ReadyForPickup] as $status) {
            $workflow->transition($request, $status, $admin);
        }
        $uniform = Uniform::query()->create(['item_name' => 'T-Shirt', 'size' => 'L', 'quantity_on_hand' => 8, 'reorder_level' => 1]);
        $fulfillment = app(PersonnelRequestFulfillmentService::class);
        $item = $request->items()->sole();
        $assignment = $fulfillment->issueUniform($item, $uniform, $admin, now()->toDateString());
        $retry = $fulfillment->issueUniform($item, $uniform, $admin, now()->toDateString());
        $this->assertTrue($assignment->is($retry));
        $this->assertSame(6, $uniform->refresh()->quantity_on_hand);
        $this->assertSame($uniform->id, $assignment->uniform_id);
        $this->assertSame($employee->id, $assignment->employee_portal_id);
        $this->assertSame($item->id, $assignment->source_personnel_request_item_id);
        $workflow->transition($request, PersonnelRequestStatus::Completed, $admin);
        $this->assertSame(PersonnelRequestStatus::Completed, $request->refresh()->status);
        $this->assertSame('Replace worn shirts.', $request->metadata['member_note']);
        $this->assertSame(1, $request->updates()->where('event', 'item_fulfilled')->count());
        $this->assertDatabaseCount('assigned_equipment', 1);
    }

    public function test_catalog_render_uses_bounded_assignment_queries_and_counted_recent_requests(): void
    {
        $this->withoutVite();
        $employee = $this->employee('UNIFORM-QUERIES');
        $this->assignment($employee, 'Engine 2', 2027);
        foreach (range(1, 5) as $index) {
            app(PersonnelRequestSubmissionService::class)->submitUniform($employee, [$this->item('t_shirt', ['size' => 'L'])], 'uniform-query-'.$index);
        }
        $this->actingAs($employee, 'employee');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/employee/request-equipment')->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "employee_bid_assignments"'))->count());
        $this->assertSame(0, $queries->filter(fn (string $sql): bool => str_contains($sql, 'select count(*) as aggregate from "personnel_request_items"'))->count());
    }

    private function item(string $code, array $metadata, int $quantity = 1): array
    {
        return ['item_code' => $code, 'metadata' => $metadata, 'quantity' => $quantity];
    }

    private function employee(string $number): Employee
    {
        $employee = Employee::query()->create(['employee_id' => $number, 'name' => 'Uniform Member '.$number,
            'rank' => 'Firefighter', 'password' => 'unused-fixture-credential', 'must_change_password' => false]);
        User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id]);

        return $employee;
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

    private function assignment(Employee $employee, string $unit, int $year): void
    {
        app(BidAssignmentReceiver::class)->receive($employee->employee_id, [
            'payload_version' => 2, 'bid_year' => $year, 'term_label' => ($year - 1).'–'.$year,
            'bid_session_id' => 'UNIFORM-TEST-'.$year, 'employee_id' => $employee->employee_id,
            'rank_label' => 'Firefighter', 'shift_label' => 'A Shift', 'station_label' => 'Station 1',
            'division_label' => 'Operations', 'unit_label' => $unit, 'position_id' => 'A101',
            'position_label' => 'Firefighter #2', 'bid_selection_label' => $unit,
            'assignment_type' => 'Assigned', 'assignment_source' => 'bid_award',
            'a_day_code' => 'G1', 'a_day_label' => 'Group 1', 'picked_at' => '2026-10-05T12:00:00Z',
            'idempotency_key' => 'uniform-bid-'.$employee->employee_id.'-'.$year,
            'is_forced' => false, 'admin_actor_employee_id' => null, 'source_sequence' => 3,
            'source_result_hash' => str_repeat('a', 64), 'source_workbook_sha256' => str_repeat('b', 64),
        ]);
    }
}
