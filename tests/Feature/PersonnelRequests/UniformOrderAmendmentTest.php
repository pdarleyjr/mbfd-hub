<?php

declare(strict_types=1);

namespace Tests\Feature\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource\Pages\ViewPersonnelRequest;
use App\Filament\Employee\Pages\RequestEquipmentPage;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\User;
use App\Services\BidAssignmentReceiver;
use App\Services\PersonnelRequests\PersonnelRequestLifecycleService;
use App\Services\PersonnelRequests\PersonnelRequestSubmissionService;
use App\Services\PersonnelRequests\PersonnelRequestWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** A member changes their own uniform order before it is ordered; every change is logged. */
final class UniformOrderAmendmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    }

    public function test_member_edits_own_pending_order_and_the_change_is_logged_with_before_and_after(): void
    {
        $employee = $this->rescueMember('AMEND-OWN');
        $order = $this->submit($employee, [$this->tShirts(2, 'L'), $this->item('tie', [], 1)], 'amend-own-submit', 'First note');
        $tShirtRowId = $order->items->firstWhere('item_code', 't_shirt')->id;

        $amended = $this->amend($employee, $order, [$this->tShirts(3, 'XL'), $this->item('belt', ['size' => 'M'], 1)], 'amend-own-1', 'Changed note');

        $this->assertSame(PersonnelRequestStatus::Pending, $amended->status);
        $this->assertEqualsCanonicalizing(['t_shirt', 'belt'], $amended->items->pluck('item_code')->all());
        $tShirt = $amended->items->firstWhere('item_code', 't_shirt');
        $this->assertSame($tShirtRowId, $tShirt->id, 'A product that stays on the order keeps its row and item history.');
        $this->assertSame(3, $tShirt->quantity);
        $this->assertSame('XL', $tShirt->size);
        $this->assertSame('Changed note', $amended->metadata['member_note']);

        $log = $amended->updates->firstWhere('event', 'member_amended');
        $this->assertNotNull($log);
        $this->assertSame('Order updated by member.', $log->employee_visible_note);
        $this->assertSame($employee->id, $log->changed_by_employee_id);
        $this->assertNull($log->changed_by_admin_id);
        $this->assertSame(PersonnelRequestStatus::Pending, $log->status);
        $this->assertSame([2, 1], array_column($log->metadata['before']['items'], 'quantity'));
        $this->assertSame('First note', $log->metadata['before']['member_note']);
        $this->assertSame('Changed note', $log->metadata['after']['member_note']);
        $this->assertContains('Short Sleeve T-Shirt: quantity 2 to 3, size L to XL.', $log->metadata['changes']);
        $this->assertTrue(collect($log->metadata['changes'])->contains(fn (string $line): bool => str_starts_with($line, 'Removed ')));
        $this->assertTrue(collect($log->metadata['changes'])->contains(fn (string $line): bool => str_starts_with($line, 'Added ')));
        $this->assertContains('Notes for Support Services changed.', $log->metadata['changes']);
    }

    public function test_original_submission_is_kept_once_and_survives_later_edits(): void
    {
        $employee = $this->rescueMember('AMEND-ORIGINAL');
        $order = $this->submit($employee, [$this->tShirts(2, 'L')], 'amend-original-submit', 'Original note');
        $originalSnapshot = $order->metadata['bid_assignment_snapshot'];

        $this->amend($employee, $order, [$this->tShirts(3, 'L')], 'amend-original-1', 'Original note');
        $second = $this->amend($employee, $order, [$this->tShirts(1, 'S')], 'amend-original-2', 'Original note');

        $original = $second->metadata['original_submission'];
        $this->assertSame(2, $original['items'][0]['quantity']);
        $this->assertSame('L', $original['items'][0]['size']);
        $this->assertSame('Original note', $original['member_note']);
        $this->assertSame($originalSnapshot, $second->metadata['bid_assignment_snapshot'], 'The bid assignment snapshot stays as submitted.');
        $this->assertSame(2, $second->updates->where('event', 'member_amended')->count());
        $this->assertSame(1, $second->updates->where('event', 'submitted')->count());
        $this->assertSame(3, $second->updates->where('event', 'member_amended')->last()->metadata['before']['items'][0]['quantity']);
    }

    public function test_retrying_the_same_change_is_saved_once_and_a_reused_key_with_other_items_is_refused(): void
    {
        $employee = $this->rescueMember('AMEND-RETRY');
        $order = $this->submit($employee, [$this->tShirts(2, 'L')], 'amend-retry-submit');

        $this->amend($employee, $order, [$this->tShirts(3, 'L')], 'amend-retry-1');
        $retry = $this->amend($employee, $order, [$this->tShirts(3, 'L')], 'amend-retry-1');

        $this->assertSame(1, $retry->updates->where('event', 'member_amended')->count());
        $this->expectValidationError(fn () => $this->amend($employee, $order, [$this->tShirts(1, 'L')], 'amend-retry-1'), 'idempotency_key');
        $this->assertSame(3, $order->items()->sole()->quantity);
    }

    public function test_saving_with_no_changes_is_refused_and_not_logged(): void
    {
        $employee = $this->rescueMember('AMEND-NOOP');
        $order = $this->submit($employee, [$this->tShirts(2, 'L')], 'amend-noop-submit');

        $this->expectValidationError(fn () => $this->amend($employee, $order, [$this->tShirts(2, 'L')], 'amend-noop-1'), 'items');
        $this->assertSame(0, $order->updates()->where('event', 'member_amended')->count());
    }

    public function test_allowance_counts_this_order_once_while_editing(): void
    {
        $employee = $this->rescueMember('AMEND-ALLOWANCE');
        $order = $this->submit($employee, [$this->tShirts(4, 'L')], 'amend-allowance-submit');

        $sized = $this->amend($employee, $order, [$this->tShirts(4, 'XL')], 'amend-allowance-1');
        $this->assertFalse((bool) $sized->over_allowance, 'Changing a size alone must not count the order against itself.');

        $this->expectValidationError(fn () => $this->amend($employee, $order, [$this->tShirts(5, 'XL')], 'amend-allowance-2'), 'allowance_justification');
        $over = $this->amend($employee, $order, [$this->tShirts(5, 'XL')], 'amend-allowance-3', null, 'One shirt was torn.');
        $this->assertTrue((bool) $over->over_allowance);
        $this->assertSame('One shirt was torn.', $over->allowance_justification);
        $this->assertArrayHasKey('allowance_at_submission', $over->metadata);
    }

    public function test_member_may_change_the_jacket_on_their_own_order(): void
    {
        $employee = $this->rescueMember('AMEND-JACKET');
        $jacket = fn (string $size): array => $this->item('jacket', ['jacket_style' => 'parka_3_in_1', 'size' => $size], 1);
        $order = $this->submit($employee, [$jacket('L')], 'amend-jacket-submit');

        $amended = $this->amend($employee, $order, [$jacket('XL')], 'amend-jacket-1');

        $this->assertSame('jacket', $amended->items->sole()->item_code);
        $this->assertSame('XL', $amended->items->sole()->metadata['size']);
        $other = $this->submit($employee, [$this->tShirts(1, 'L')], 'amend-jacket-other');
        $this->expectValidationError(fn () => $this->amend($employee, $other, [$this->tShirts(1, 'L'), $jacket('M')], 'amend-jacket-2'), 'items.jacket.quantity');
    }

    public function test_member_cannot_edit_someone_elses_order(): void
    {
        $owner = $this->rescueMember('AMEND-OWNER');
        $intruder = $this->rescueMember('AMEND-INTRUDER');
        $order = $this->submit($owner, [$this->tShirts(2, 'L')], 'amend-intruder-submit');

        try {
            $this->amend($intruder, $order, [$this->tShirts(1, 'L')], 'amend-intruder-1');
            $this->fail('Another member edited this order.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(2, $order->items()->sole()->quantity);

        $this->withoutVite();
        $this->actingAs($intruder, 'employee')->get('/employee/request-equipment?edit='.$order->public_id)->assertNotFound();
    }

    public function test_orders_that_are_ordered_received_closed_or_archived_cannot_be_edited(): void
    {
        $employee = $this->rescueMember('AMEND-LOCKED');
        $admin = $this->admin();
        $workflow = app(PersonnelRequestWorkflowService::class);

        $ordered = $this->submit($employee, [$this->tShirts(1, 'L')], 'amend-locked-ordered');
        $workflow->transition($ordered, PersonnelRequestStatus::Acknowledged, $admin);
        $workflow->transition($ordered, PersonnelRequestStatus::Ordered, $admin);
        $this->expectValidationError(fn () => $this->amend($employee, $ordered, [$this->tShirts(2, 'L')], 'amend-locked-1'), 'items');

        $arrived = $this->submit($employee, [$this->tShirts(1, 'L')], 'amend-locked-arrived');
        $workflow->transition($arrived, PersonnelRequestStatus::Acknowledged, $admin);
        $workflow->arriveItem($arrived->items()->sole(), $admin, 1, 'amend-arrive-1');
        $this->expectValidationError(fn () => $this->amend($employee, $arrived, [$this->tShirts(3, 'L')], 'amend-locked-2'), 'items');

        $archived = $this->submit($employee, [$this->tShirts(1, 'L')], 'amend-locked-archived');
        $archived->forceFill(['archived_at' => now()])->save();
        $this->expectValidationError(fn () => $this->amend($employee, $archived, [$this->tShirts(2, 'L')], 'amend-locked-3'), 'items');

        $trashed = $this->submit($employee, [$this->tShirts(1, 'L')], 'amend-locked-trashed');
        $workflow->transition($trashed, PersonnelRequestStatus::Cancelled, $admin, 'Duplicate order.');
        app(PersonnelRequestLifecycleService::class)->delete($trashed, $admin, 'Duplicate order.');
        $this->expectValidationError(fn () => $this->amend($employee, $trashed, [$this->tShirts(2, 'L')], 'amend-locked-4'), 'items');

        $this->assertDatabaseMissing('personnel_request_updates', ['event' => 'member_amended']);
    }

    public function test_detail_page_offers_edit_only_while_editable_and_builder_saves_back_to_the_order(): void
    {
        $this->withoutVite();
        $employee = $this->rescueMember('AMEND-UI');
        $order = $this->submit($employee, [$this->tShirts(2, 'L')], 'amend-ui-submit');

        $this->actingAs($employee, 'employee')->get('/employee/my-requests/'.$order->public_id)
            ->assertOk()->assertSee('data-edit-order', false)->assertSee('/employee/request-equipment?edit='.$order->public_id, false);

        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        $builder = Livewire::withQueryParams(['edit' => $order->public_id])->test(RequestEquipmentPage::class)
            ->assertSet('editingRequestId', $order->public_id)
            ->assertSet('data.items.t_shirt.quantity', 2)
            ->assertSet('data.items.t_shirt.metadata.size', 'L')
            ->assertSee('Save changes')->assertSee('Editing order '.$order->request_number);

        $builder->set('data.items.t_shirt.quantity', 3)->call('submit')
            ->assertHasNoErrors()->assertRedirect('/employee/my-requests/'.$order->public_id);

        $this->assertSame(3, $order->items()->sole()->quantity);
        $this->assertSame(1, PersonnelRequest::query()->count(), 'Editing never creates a second order.');
        $this->get('/employee/my-requests/'.$order->public_id)->assertOk()
            ->assertSee('data-history-edited', false)->assertSee('Order updated by member.')
            ->assertSee('Short Sleeve T-Shirt: quantity 2 to 3.');

        $admin = $this->admin();
        app(PersonnelRequestWorkflowService::class)->transition($order, PersonnelRequestStatus::Acknowledged, $admin);
        app(PersonnelRequestWorkflowService::class)->transition($order, PersonnelRequestStatus::Ordered, $admin);
        $this->actingAs($employee, 'employee')->get('/employee/my-requests/'.$order->public_id)
            ->assertOk()->assertDontSee('data-edit-order', false)->assertSee('Message Support Services');
    }

    public function test_admin_view_shows_the_member_change_and_the_original_order(): void
    {
        $this->withoutVite();
        $employee = $this->rescueMember('AMEND-ADMIN');
        $order = $this->submit($employee, [$this->tShirts(2, 'L')], 'amend-admin-submit');
        $this->amend($employee, $order, [$this->tShirts(3, 'XL')], 'amend-admin-1');

        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ViewPersonnelRequest::class, ['record' => $order->public_id])
            ->assertSee('Member changes')->assertSee('Short Sleeve T-Shirt: quantity 2 to 3, size L to XL.')
            ->assertSee('Original order, before member edits')
            ->assertSee('Order updated by member.');
    }

    public function test_support_services_is_told_when_a_member_edits_an_order(): void
    {
        $employee = $this->rescueMember('AMEND-NOTIFY');
        $admin = $this->admin();
        $admin->forceFill(['account_status' => 'active'])->save();
        $order = $this->submit($employee, [$this->tShirts(2, 'L')], 'amend-notify-submit');
        $before = $admin->notifications()->count();

        $this->amend($employee, $order, [$this->tShirts(3, 'L')], 'amend-notify-1');

        $alerts = $admin->notifications()->get()->slice($before);
        $this->assertCount(1, $alerts);
        $this->assertSame('Member edited a uniform order', $alerts->first()->data['title']);
        $this->assertStringContainsString($order->request_number, $alerts->first()->data['body']);
    }

    private function amend(Employee $employee, PersonnelRequest $order, array $items, string $key, ?string $note = null, ?string $reason = null): PersonnelRequest
    {
        return app(PersonnelRequestSubmissionService::class)->amendUniform($employee, $order, $items, $key, [
            'member_note' => $note, 'allowance_justification' => $reason,
        ]);
    }

    private function submit(Employee $employee, array $items, string $key, ?string $note = null): PersonnelRequest
    {
        return app(PersonnelRequestSubmissionService::class)->submitUniform($employee, $items, $key, ['member_note' => $note]);
    }

    private function expectValidationError(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail("Expected a validation error on {$field}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function tShirts(int $quantity, string $size): array
    {
        return $this->item('t_shirt', ['size' => $size], $quantity);
    }

    private function item(string $code, array $metadata, int $quantity): array
    {
        return ['item_code' => $code, 'metadata' => $metadata, 'quantity' => $quantity];
    }

    private function rescueMember(string $number): Employee
    {
        $employee = Employee::query()->create(['employee_id' => $number, 'name' => 'Amend Member '.$number,
            'rank' => 'Firefighter', 'password' => 'unused-fixture-credential', 'must_change_password' => false]);
        User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id]);
        app(BidAssignmentReceiver::class)->receive($employee->employee_id, [
            'payload_version' => 2, 'bid_year' => 2027, 'term_label' => '2026–2027',
            'bid_session_id' => 'AMEND-TEST', 'employee_id' => $employee->employee_id,
            'rank_label' => 'Firefighter', 'shift_label' => 'A Shift', 'station_label' => 'Station 1',
            'division_label' => 'Operations', 'unit_label' => 'Rescue 11', 'position_id' => 'A101',
            'position_label' => 'Firefighter #2', 'bid_selection_label' => 'Rescue 11',
            'assignment_type' => 'Assigned', 'assignment_source' => 'bid_award',
            'a_day_code' => 'G1', 'a_day_label' => 'Group 1', 'picked_at' => '2026-10-05T12:00:00Z',
            'idempotency_key' => 'amend-bid-'.$employee->employee_id,
            'is_forced' => false, 'admin_actor_employee_id' => null, 'source_sequence' => 3,
            'source_result_hash' => str_repeat('a', 64), 'source_workbook_sha256' => str_repeat('b', 64),
        ]);

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
}
