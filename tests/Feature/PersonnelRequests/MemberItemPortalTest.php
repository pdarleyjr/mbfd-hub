<?php

declare(strict_types=1);

namespace Tests\Feature\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Enums\PersonnelRequestType;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\PersonnelRequestItem;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MemberItemPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_member_sees_item_progress_and_scoped_escaped_messages_without_internal_notes(): void
    {
        $employee = $this->member();
        $request = $this->requestFor($employee);
        $item = $this->item($request, 'Short Sleeve Polo', 9);
        $item->update(['arrived_quantity' => 5, 'fulfilled_quantity' => 2, 'fulfillment_status' => 'partially_fulfilled']);
        $other = $this->item($request, '5.11 Stryke Pants', 3);
        $publicNote = '<script>alert("member")</script> Two polos are available & more will follow.';
        $request->updates()->create([
            'event' => 'item_arrived', 'status' => $request->status,
            'employee_visible_note' => $publicNote, 'internal_note' => 'Private supplier invoice only.',
            'metadata' => ['item_id' => $item->id],
        ]);
        $request->updates()->create([
            'event' => 'note_added', 'status' => $request->status,
            'employee_visible_note' => 'Pants use the new inseam measurement.', 'metadata' => ['item_id' => $other->id],
        ]);
        $request->updates()->create(['event' => 'note_added', 'status' => $request->status, 'internal_note' => 'Hidden administration discussion.']);

        $response = $this->get('/employee/my-requests/'.$request->public_id)->assertOk()
            ->assertSee('Message Support Services')->assertSee('Entire order')->assertSee('Partially issued')
            ->assertSee($publicNote)->assertDontSee('<script>alert("member")</script>', false)
            ->assertDontSee('Private supplier invoice only.')->assertDontSee('Hidden administration discussion.');
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        $row = $xpath->query('//*[@id="item-'.$item->id.'"]')->item(0);
        $this->assertNotNull($row);
        $this->assertSame('9', trim($xpath->query('.//dt[normalize-space()="Requested"]/following-sibling::dd', $row)->item(0)->textContent));
        $this->assertSame('5', trim($xpath->query('.//*[@data-arrived-quantity]', $row)->item(0)->textContent));
        $this->assertSame('2', trim($xpath->query('.//*[@data-issued-quantity]', $row)->item(0)->textContent));
        $this->assertStringContainsString($publicNote, $row->textContent);
        $this->assertStringNotContainsString('Pants use the new inseam measurement.', $row->textContent);
        $pants = $xpath->query('//*[@id="item-'.$other->id.'"]')->item(0);
        $this->assertStringContainsString('Pants use the new inseam measurement.', $pants->textContent);
        $this->assertStringNotContainsString($publicNote, $pants->textContent);
    }

    public function test_active_order_item_message_creates_public_history_and_notifies_the_existing_admin_inbox(): void
    {
        $employee = $this->member();
        $request = $this->requestFor($employee);
        $item = $this->item($request, 'Short Sleeve Polo');
        $admin = $this->admin();
        $unrelated = User::factory()->create();
        $message = '<img src=x onerror=alert(1)> Please confirm the sleeve size.';

        $this->from('/employee/my-requests/'.$request->public_id)
            ->post('/employee/personnel-requests/'.$request->public_id.'/respond', [
                'response' => $message, 'item_id' => $item->id, 'idempotency_key' => 'member-item-message-1',
            ])->assertRedirect('/employee/my-requests/'.$request->public_id)
            ->assertSessionHas('status', 'Your response was sent to Support Services.');
        $update = $request->updates()->sole();
        $this->assertSame('employee_responded', $update->event);
        $this->assertSame($employee->id, $update->changed_by_employee_id);
        $this->assertSame($item->id, $update->metadata['item_id']);
        $this->assertSame($message, $update->employee_visible_note);
        $this->assertNull($update->internal_note);
        $this->assertSame(PersonnelRequestStatus::Ordered, $request->refresh()->status);
        $notice = $admin->notifications()->sole();
        $this->assertSame('Member reply: Short Sleeve Polo', $notice->data['title']);
        $this->assertStringContainsString('Please confirm the sleeve size.', $notice->data['body']);
        $this->assertSame('/admin/personnel-uniforms-equipment/personnel-requests/'.$request->public_id, data_get($notice->data, 'actions.0.url'));
        $this->assertSame(0, $unrelated->notifications()->count());
        $this->get('/employee/my-requests/'.$request->public_id)->assertOk()
            ->assertSee($message)->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    public function test_foreign_item_from_another_order_is_rejected_even_for_the_same_member(): void
    {
        $employee = $this->member();
        $request = $this->requestFor($employee);
        $this->item($request);
        $foreign = $this->item($this->requestFor($employee), 'Another order item');
        $admin = $this->admin();

        $this->post('/employee/personnel-requests/'.$request->public_id.'/respond', [
            'response' => 'This must not attach to another order.', 'item_id' => $foreign->id, 'idempotency_key' => 'foreign-item',
        ])->assertNotFound();
        $this->assertSame(0, $request->updates()->count());
        $this->assertSame(0, $foreign->request->updates()->count());
        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_entire_order_messages_are_available_through_every_active_stage_without_resetting_progress(): void
    {
        $employee = $this->member();
        foreach (PersonnelRequestStatus::cases() as $status) {
            if ($status->isTerminal()) {
                continue;
            }
            $request = $this->requestFor($employee, $status);
            $this->item($request);
            $this->get('/employee/my-requests/'.$request->public_id)->assertOk()->assertSee('Message Support Services');
            $this->post('/employee/personnel-requests/'.$request->public_id.'/respond', [
                'response' => 'A message about the entire order.', 'item_id' => '', 'idempotency_key' => 'stage-'.$status->value,
            ])->assertRedirect();
            $update = $request->updates()->sole();
            $this->assertNull(data_get($update->metadata, 'item_id'));
            $this->assertSame('A message about the entire order.', $update->employee_visible_note);
            $this->assertSame($status === PersonnelRequestStatus::NeedsInformation ? PersonnelRequestStatus::Acknowledged : $status, $request->refresh()->status);
        }
    }

    public function test_other_member_cannot_view_or_message_the_order_and_ownership_is_checked_before_item_lookup(): void
    {
        $owner = $this->member('OWNER-ITEM');
        $request = $this->requestFor($owner);
        $item = $this->item($request);
        $this->member('OTHER-MEMBER');
        $this->get('/employee/my-requests/'.$request->public_id)->assertForbidden();
        foreach ([$item->id, 999999] as $itemId) {
            $this->post('/employee/personnel-requests/'.$request->public_id.'/respond', [
                'response' => 'Unauthorized message.', 'item_id' => $itemId, 'idempotency_key' => 'not-owner-'.$itemId,
            ])->assertForbidden();
        }
        $this->assertSame(0, $request->updates()->count());
    }

    public function test_message_replay_uses_one_history_entry_and_one_admin_notification_and_rejects_changed_payload(): void
    {
        $employee = $this->member();
        $request = $this->requestFor($employee);
        $item = $this->item($request);
        $admin = $this->admin();
        $payload = ['response' => 'The first item fits correctly.', 'item_id' => $item->id, 'idempotency_key' => 'member-replay'];
        $url = '/employee/personnel-requests/'.$request->public_id.'/respond';
        $this->post($url, $payload)->assertRedirect();
        $this->post($url, $payload)->assertRedirect();
        $this->assertSame(1, $request->updates()->count());
        $this->assertSame(1, $admin->notifications()->count());
        $this->post($url, [...$payload, 'response' => 'A different message.'])->assertSessionHasErrors('idempotency_key');
        $this->assertSame(1, $request->updates()->count());
        $this->assertSame(1, $admin->notifications()->count());
    }

    #[DataProvider('terminalStatuses')]
    public function test_terminal_order_has_no_message_form_and_rejects_new_member_messages(PersonnelRequestStatus $status): void
    {
        $employee = $this->member();
        $request = $this->requestFor($employee, $status);
        $item = $this->item($request);
        $this->get('/employee/my-requests/'.$request->public_id)->assertOk()->assertDontSee('Message Support Services');
        $this->post('/employee/personnel-requests/'.$request->public_id.'/respond', [
            'response' => 'New message on a closed order.', 'item_id' => $item->id, 'idempotency_key' => 'closed-order',
        ])->assertForbidden();
        $this->assertSame(0, $request->updates()->count());
    }

    public static function terminalStatuses(): array
    {
        return [
            'completed' => [PersonnelRequestStatus::Completed],
            'denied' => [PersonnelRequestStatus::Denied],
            'cancelled' => [PersonnelRequestStatus::Cancelled],
        ];
    }

    public function test_archived_active_request_is_read_only_and_hides_message_and_attachment_forms(): void
    {
        $employee = $this->member();
        $request = $this->requestFor($employee, PersonnelRequestStatus::NeedsInformation);
        $item = $this->item($request);
        $request->update([
            'archived_at' => now(), 'archive_reason' => 'Internal archive reason must remain private.',
            'information_requested' => ['damage_photo'], 'employee_response' => 'Please provide a photo.',
        ]);
        $this->get('/employee/my-requests/'.$request->public_id)->assertOk()
            ->assertSee('Requested items')->assertDontSee('Message Support Services')
            ->assertDontSee('Upload securely')->assertDontSee('Internal archive reason must remain private.');
        $this->post('/employee/personnel-requests/'.$request->public_id.'/respond', [
            'response' => 'An archived request must remain read only.', 'item_id' => $item->id, 'idempotency_key' => 'archived-message',
        ])->assertForbidden();
        $this->assertSame(0, $request->updates()->count());
    }

    private function member(string $number = 'MEMBER-ITEM-PORTAL'): Employee
    {
        $user = $this->actingAsCanonicalFixture($number, 'Portal Item Member');

        return $user->employeeProfile()->firstOrFail();
    }

    private function requestFor(Employee $employee, PersonnelRequestStatus $status = PersonnelRequestStatus::Ordered): PersonnelRequest
    {
        return PersonnelRequest::query()->create([
            'public_id' => (string) Str::ulid(), 'request_number' => 'PORTAL-'.Str::random(8), 'type' => PersonnelRequestType::Uniform,
            'status' => $status, 'beneficiary_employee_id' => $employee->id, 'requester_employee_id' => $employee->id,
            'beneficiary_name' => $employee->name, 'beneficiary_employee_number' => $employee->employee_id,
            'requester_name' => $employee->name, 'requester_employee_number' => $employee->employee_id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function item(PersonnelRequest $request, string $name = 'Short Sleeve Polo', int $quantity = 3): PersonnelRequestItem
    {
        return $request->items()->create(['item_code' => 'polo_shirt_ss', 'item_name' => $name, 'category' => 'uniform', 'quantity' => $quantity]);
    }

    private function admin(): User
    {
        Role::findOrCreate('logistics_admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('logistics_admin');

        return $admin;
    }
}
