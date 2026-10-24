<?php

declare(strict_types=1);

namespace Tests\Feature\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource\Pages\ViewPersonnelRequest;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\Uniform;
use App\Models\User;
use App\Services\PersonnelRequests\PersonnelRequestFulfillmentService;
use App\Services\PersonnelRequests\PersonnelRequestSubmissionService;
use App\Services\PersonnelRequests\PersonnelRequestWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Closing a personnel request: Complete straight from the working states once every
 * item is issued, and a real reason on Deny / Cancel that the member can read.
 */
class PersonnelRequestCloseoutTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{PersonnelRequestStatus}> */
    public static function workingStates(): array
    {
        return [
            'pending' => [PersonnelRequestStatus::Pending],
            'acknowledged' => [PersonnelRequestStatus::Acknowledged],
            'ordered' => [PersonnelRequestStatus::Ordered],
        ];
    }

    #[DataProvider('workingStates')]
    public function test_request_completes_in_one_step_once_every_item_is_issued(PersonnelRequestStatus $from): void
    {
        [$request, $employee, $admin] = $this->request($from, 2);
        $workflow = app(PersonnelRequestWorkflowService::class);
        $this->assertFalse($workflow->canComplete($request), 'Unissued items must keep Complete hidden.');

        $this->issueEverything($request, $admin);
        $this->assertSame($from, $request->refresh()->status, 'Issuing does not move the request on its own.');
        $this->assertTrue($workflow->canComplete($request));
        $before = $employee->notifications()->count();

        $workflow->transition($request, PersonnelRequestStatus::Completed, $admin, 'All set, enjoy.');

        $request->refresh();
        $this->assertSame(PersonnelRequestStatus::Completed, $request->status);
        $this->assertNotNull($request->completed_at);
        $this->assertNotNull($request->acknowledged_at, 'Completed requests always carry an acknowledgement time.');
        $this->assertDatabaseHas('personnel_request_updates', [
            'personnel_request_id' => $request->id,
            'event' => 'status_changed',
            'status' => 'completed',
            'employee_visible_note' => 'All set, enjoy.',
            'changed_by_admin_id' => $admin->id,
        ]);
        $this->assertSame($before + 1, $employee->notifications()->count(), 'One closing notification, no Arrived / Ready detour.');
    }

    #[DataProvider('workingStates')]
    public function test_request_with_unissued_items_cannot_be_completed(PersonnelRequestStatus $from): void
    {
        [$request, , $admin] = $this->request($from, 2);
        $stock = $this->stock();
        $first = $request->items()->orderBy('id')->firstOrFail();
        app(PersonnelRequestFulfillmentService::class)->issueUniform($first, $stock, $admin, '2026-10-07', null, null, 1, 'only-one');

        try {
            app(PersonnelRequestWorkflowService::class)->transition($request, PersonnelRequestStatus::Completed, $admin);
            $this->fail('A request with unissued items was completed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame($from, $request->refresh()->status);
        $this->assertNull($request->completed_at);
    }

    public function test_request_waiting_on_the_member_cannot_be_completed(): void
    {
        [$request, , $admin] = $this->request(PersonnelRequestStatus::NeedsInformation, 1);
        $this->issueEverything($request, $admin);

        $workflow = app(PersonnelRequestWorkflowService::class);
        $this->assertFalse($workflow->canComplete($request));
        $this->expectException(ValidationException::class);
        $workflow->transition($request, PersonnelRequestStatus::Completed, $admin);
    }

    /** @return array<string, array{PersonnelRequestStatus, string}> */
    public static function decisions(): array
    {
        return [
            'denied' => [PersonnelRequestStatus::Denied, 'Denied'],
            'cancelled' => [PersonnelRequestStatus::Cancelled, 'Cancelled'],
        ];
    }

    #[DataProvider('decisions')]
    public function test_deny_and_cancel_require_a_meaningful_reason(PersonnelRequestStatus $to): void
    {
        [$request, , $admin] = $this->request(PersonnelRequestStatus::Acknowledged, 1);
        $workflow = app(PersonnelRequestWorkflowService::class);
        $historyBefore = $request->updates()->count();

        foreach ([null, '', '   ', 'no', '  too short  ', str_repeat('x', 1001)] as $reason) {
            try {
                $workflow->transition($request, $to, $admin, $reason);
                $this->fail('A blank, short or oversized reason was accepted: '.var_export($reason, true));
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('employee_note', $exception->errors());
            }
            $this->assertSame(PersonnelRequestStatus::Acknowledged, $request->refresh()->status);
        }
        $this->assertSame($historyBefore, $request->updates()->count());
    }

    #[DataProvider('decisions')]
    public function test_reason_is_trimmed_stored_in_history_and_sent_to_the_member(PersonnelRequestStatus $to, string $label): void
    {
        [$request, $employee, $admin] = $this->request(PersonnelRequestStatus::Pending, 1);

        app(PersonnelRequestWorkflowService::class)->transition($request, $to, $admin, "  Size is not stocked this quarter.  \n");

        $request->refresh();
        $this->assertSame($to, $request->status);
        $this->assertSame('Size is not stocked this quarter.', $request->employee_response);
        $this->assertDatabaseHas('personnel_request_updates', [
            'personnel_request_id' => $request->id,
            'status' => $to->value,
            'employee_visible_note' => 'Size is not stocked this quarter.',
        ]);
        $notification = $employee->notifications()->latest()->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString("{$label}: Size is not stocked this quarter.", json_encode($notification->data, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('decisions')]
    public function test_member_sees_the_reason_in_plain_language(PersonnelRequestStatus $to, string $label): void
    {
        [$request, $employee, $admin] = $this->request(PersonnelRequestStatus::Pending, 1);
        app(PersonnelRequestWorkflowService::class)->transition($request, $to, $admin, 'Duplicate of an order already placed.');

        $this->actingAs($employee, 'employee')->get('/employee/my-requests/'.$request->public_id)
            ->assertOk()
            ->assertSee("{$label}: Duplicate of an order already placed.");
    }

    public function test_completing_does_not_demand_a_reason(): void
    {
        [$request, , $admin] = $this->request(PersonnelRequestStatus::Acknowledged, 1);
        $this->issueEverything($request, $admin);

        app(PersonnelRequestWorkflowService::class)->transition($request, PersonnelRequestStatus::Completed, $admin);

        $this->assertSame(PersonnelRequestStatus::Completed, $request->refresh()->status);
    }

    public function test_admin_page_shows_complete_only_when_every_item_is_issued_and_deny_needs_a_reason(): void
    {
        $this->withoutVite();
        [$request, , $admin] = $this->request(PersonnelRequestStatus::Acknowledged, 1);
        $admin->givePermissionTo([
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.personnel.view', 'web'),
            Permission::findOrCreate('admin.personnel.manage', 'web'),
        ]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::test(ViewPersonnelRequest::class, ['record' => $request->public_id])
            ->assertActionHidden('complete')
            ->assertActionVisible('deny')
            ->callAction('deny', ['employee_note' => 'nope'])
            ->assertHasActionErrors(['employee_note']);
        $this->assertSame(PersonnelRequestStatus::Acknowledged, $request->refresh()->status);

        $this->issueEverything($request, $admin);
        $page->call('$refresh');
        Livewire::test(ViewPersonnelRequest::class, ['record' => $request->public_id])
            ->assertActionVisible('complete')
            ->callAction('complete', ['employee_note' => 'Picked up.'])
            ->assertHasNoActionErrors();

        $this->assertSame(PersonnelRequestStatus::Completed, $request->refresh()->status);
    }

    /** @return array{PersonnelRequest, Employee, User} */
    private function request(PersonnelRequestStatus $status, int $quantity): array
    {
        $employee = Employee::query()->create([
            'employee_id' => 'CLOSE-'.str()->random(8), 'name' => 'Closeout Member', 'rank' => 'Firefighter',
            'password' => bcrypt(str()->random(40)), 'must_change_password' => false,
        ]);
        Role::findOrCreate('logistics_admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('logistics_admin');
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform(
            $employee,
            [['item_code' => 't_shirt', 'size' => 'L', 'quantity' => $quantity]],
            'closeout-'.str()->uuid(),
        );
        $request->forceFill(['status' => $status])->save();

        return [$request->refresh(), $employee, $admin];
    }

    private function stock(): Uniform
    {
        return Uniform::query()->create(['item_name' => 'T-Shirt', 'size' => 'L', 'quantity_on_hand' => 20, 'reorder_level' => 0]);
    }

    private function issueEverything(PersonnelRequest $request, User $admin): void
    {
        $stock = $this->stock();
        foreach ($request->items()->orderBy('id')->get() as $item) {
            app(PersonnelRequestFulfillmentService::class)->issueUniform(
                $item, $stock, $admin, '2026-10-07', null, null, $item->quantity, 'issue-'.$item->id,
            );
        }
    }
}
