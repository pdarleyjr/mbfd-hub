<?php

declare(strict_types=1);

namespace Tests\Feature\MemberReplies;

use App\Enums\AccountStatus;
use App\Filament\Employee\Pages\MySubmissionConversation;
use App\Jobs\FanOutNotification;
use App\Models\Apparatus;
use App\Models\ApparatusServiceTicket;
use App\Models\Employee;
use App\Models\Station;
use App\Models\StationRequest;
use App\Models\StationSupplyRequest;
use App\Models\SubmissionMessage;
use App\Models\User;
use App\Services\MemberReplies\MemberReplyLedger;
use App\Services\MemberReplies\SubmissionConversationService;
use App\Services\StationRequestWorkflowService;
use App\Services\StationSupplyRequestWorkflowService;
use App\Support\MemberReplies\ReplySubject;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** docs/member-replies.md section 2.3: station requests, supply requests and service tickets. */
final class SubmissionConversationTest extends TestCase
{
    use RefreshDatabase;

    private const FIRST_ID = '2b5e4c1a-9d8f-4a3b-8c7d-6e5f4a3b2c11';

    private const SECOND_ID = '2b5e4c1a-9d8f-4a3b-8c7d-6e5f4a3b2c22';

    private Station $station;

    private User $requester;

    private User $other;

    private User $staff;

    private StationRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->station = Station::query()->create(['station_number' => 1, 'name' => 'Station 1', 'address' => '1 Test Street', 'is_active' => true]);
        $this->requester = $this->member('31001', 'Pat Requester');
        $this->other = $this->member('31002', 'Float Firefighter');
        $this->staff = $this->staff();
        $this->request = StationRequest::query()->create([
            'station_id' => $this->station->id,
            'actor_user_id' => $this->requester->id,
            'requested_by_employee_id' => $this->requester->employee_profile_id,
            'requester_name_snapshot' => 'Pat Requester',
            'request_type' => 'repair_service',
            'subject_type' => 'kitchen',
            'title' => 'Kitchen sink leak',
            'description' => 'The sink drips constantly.',
            'priority' => 'normal',
            'status' => 'pending',
        ])->refresh();
    }

    public function test_station_request_requester_replies_staff_are_told_and_the_request_awaits_staff(): void
    {
        Queue::fake();
        $this->actingAsCanonicalUser($this->requester);
        $rows = collect($this->getJson("/api/public/stations/{$this->station->id}/requests")->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($rows[$this->request->id]['member_conversation']['participant']);

        $this->getJson($this->path('station_request', $this->request->id))->assertOk()
            ->assertJsonPath('data.can_reply', true)->assertJsonCount(0, 'data.messages');
        $this->postJson($this->path('station_request', $this->request->id), ['body' => 'Plumber never came.', 'client_message_id' => self::FIRST_ID])
            ->assertCreated()
            ->assertJsonPath('data.messages.0.author', 'You')
            ->assertJsonPath('data.messages.0.from_staff', false);
        // A retry of the same message stores it once and alerts once.
        $this->postJson($this->path('station_request', $this->request->id), ['body' => 'Plumber never came.', 'client_message_id' => self::FIRST_ID])
            ->assertCreated()->assertJsonCount(1, 'data.messages');

        $this->assertSame(1, SubmissionMessage::query()->count());
        Queue::assertPushed(FanOutNotification::class, 1);
        $this->assertTrue(app(MemberReplyLedger::class)->isAwaiting(ReplySubject::STATION_REQUEST, $this->request->id));
        // Station-wide request lists do not carry the conversation.
        $this->assertStringNotContainsString('Plumber never came.', $this->getJson("/api/public/stations/{$this->station->id}/requests")->getContent());
    }

    public function test_staff_reply_reaches_the_member_and_answers_the_request(): void
    {
        Queue::fake();
        $conversations = app(SubmissionConversationService::class);
        $conversations->postAsMember($this->requester, ReplySubject::STATION_REQUEST, $this->request->id, 'Any update?', self::FIRST_ID);
        $conversations->postAsStaff($this->staff, ReplySubject::STATION_REQUEST, $this->request->id, 'Vendor booked for Friday.', self::SECOND_ID);

        $this->assertFalse(app(MemberReplyLedger::class)->isAwaiting(ReplySubject::STATION_REQUEST, $this->request->id));
        // One alert to staff for the member's message, one to the member for the staff reply.
        Queue::assertPushed(FanOutNotification::class, 2);

        $this->actingAsCanonicalUser($this->requester);
        $this->getJson($this->path('station_request', $this->request->id))->assertOk()
            ->assertJsonPath('data.messages.1.body', 'Vendor booked for Friday.')
            ->assertJsonPath('data.messages.1.from_staff', true)
            ->assertJsonPath('data.messages.1.author', 'Support Services');

        // A later public update through the normal workflow also answers a new member message; an internal note does not.
        $conversations->postAsMember($this->requester, ReplySubject::STATION_REQUEST, $this->request->id, 'Friday works.', '2b5e4c1a-9d8f-4a3b-8c7d-6e5f4a3b2c33');
        app(StationRequestWorkflowService::class)->transition($this->request, ['status' => 'pending', 'internal_note' => 'Vendor PO 5521'], $this->staff);
        $this->assertTrue(app(MemberReplyLedger::class)->isAwaiting(ReplySubject::STATION_REQUEST, $this->request->id));
        app(StationRequestWorkflowService::class)->transition($this->request->fresh(), ['status' => 'acknowledged', 'public_note' => 'Confirmed for Friday.'], $this->staff);
        $this->assertFalse(app(MemberReplyLedger::class)->isAwaiting(ReplySubject::STATION_REQUEST, $this->request->id));
    }

    public function test_another_member_cannot_read_or_reply_and_staff_cannot_reply_as_a_member(): void
    {
        $this->actingAsCanonicalUser($this->other);
        $rows = collect($this->getJson("/api/public/stations/{$this->station->id}/requests")->assertOk()->json('data'))->keyBy('id');
        $this->assertFalse($rows[$this->request->id]['member_conversation']['participant']);
        $this->getJson($this->path('station_request', $this->request->id))->assertNotFound();
        $this->postJson($this->path('station_request', $this->request->id), ['body' => 'Hi', 'client_message_id' => self::FIRST_ID])->assertNotFound();
        // Someone else's record reads exactly like a missing one, even with an invalid body.
        $this->postJson($this->path('station_request', $this->request->id), [])->assertNotFound();
        $this->getJson($this->path('station_request', 999999))->assertNotFound();
        $this->getJson('/api/public/submission-conversations/personnel_request/'.$this->request->id)->assertNotFound();

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(SubmissionConversationService::class)->postAsStaff($this->other, ReplySubject::STATION_REQUEST, $this->request->id, 'Not staff.', self::SECOND_ID);
    }

    public function test_a_member_can_leave_only_so_many_unanswered_messages_and_archived_records_are_closed(): void
    {
        $conversations = app(SubmissionConversationService::class);
        foreach (range(1, SubmissionConversationService::MAX_UNANSWERED) as $n) {
            $conversations->postAsMember($this->requester, ReplySubject::STATION_REQUEST, $this->request->id, "Message {$n}", sprintf('2b5e4c1a-9d8f-4a3b-8c7d-6e5f4a3b%04d', $n));
        }
        try {
            $conversations->postAsMember($this->requester, ReplySubject::STATION_REQUEST, $this->request->id, 'One more', self::SECOND_ID);
            $this->fail('An eleventh unanswered message was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('body', $exception->errors());
        }

        $this->request->forceFill(['archived_at' => now()])->save();
        $this->expectException(ValidationException::class);
        $conversations->postAsStaff($this->staff, ReplySubject::STATION_REQUEST, $this->request->id, 'Closing.', self::SECOND_ID);
    }

    public function test_supply_request_conversation_follows_the_submitter(): void
    {
        Queue::fake();
        $supply = new StationSupplyRequest;
        $supply->forceFill([
            'station_id' => $this->station->id, 'actor_user_id' => $this->requester->id, 'actor_employee_id' => $this->requester->employee_profile_id,
            'request_text' => 'Need 4 boxes of gloves', 'status' => 'open', 'created_by_name' => 'Pat Requester', 'created_by_shift' => 'A',
        ])->save();

        $this->actingAsCanonicalUser($this->requester);
        $rows = collect($this->getJson("/api/v2/station-inventory/{$this->station->id}/supply-requests")->assertOk()->json('requests'))->keyBy('id');
        $this->assertTrue($rows[$supply->id]['member_conversation']['participant']);
        $this->postJson($this->path('station_supply_request', $supply->id), ['body' => 'Size large please.', 'client_message_id' => self::FIRST_ID])->assertCreated();
        $this->assertTrue(app(MemberReplyLedger::class)->isAwaiting(ReplySubject::STATION_SUPPLY_REQUEST, $supply->id));

        // A new member-facing response through the normal edit answers it; admin notes alone do not.
        $this->actingAsCanonicalUser($this->staff);
        app(StationSupplyRequestWorkflowService::class)->update($supply, $this->staff, ['status' => 'open', 'admin_notes' => 'Check stock']);
        $this->assertTrue(app(MemberReplyLedger::class)->isAwaiting(ReplySubject::STATION_SUPPLY_REQUEST, $supply->id));
        app(StationSupplyRequestWorkflowService::class)->update($supply, $this->staff, ['status' => 'ordered', 'public_response' => 'Large gloves ordered.']);
        $this->assertFalse(app(MemberReplyLedger::class)->isAwaiting(ReplySubject::STATION_SUPPLY_REQUEST, $supply->id));

        $this->actingAsCanonicalUser($this->other);
        $this->getJson($this->path('station_supply_request', $supply->id))->assertNotFound();
        $this->assertStringNotContainsString('Check stock', $this->getJson("/api/v2/station-inventory/{$this->station->id}/supply-requests")->getContent());
    }

    public function test_service_ticket_conversation_follows_the_requesting_member(): void
    {
        Queue::fake();
        $ticket = $this->ticket();

        $this->actingAsCanonicalUser($this->requester);
        $rows = collect($this->getJson("/api/public/stations/{$this->station->id}/service-tickets?scope=all")->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($rows[$ticket->id]['member_conversation']['participant']);
        $this->assertArrayNotHasKey('requested_by_employee_id', $rows[$ticket->id]);
        $this->postJson($this->path('apparatus_service_ticket', $ticket->id), ['body' => 'It also pulls left.', 'client_message_id' => self::FIRST_ID])->assertCreated();
        $this->assertTrue(app(MemberReplyLedger::class)->isAwaiting(ReplySubject::APPARATUS_SERVICE_TICKET, $ticket->id));
        Queue::assertPushed(FanOutNotification::class, 1);

        $this->actingAsCanonicalUser($this->other);
        $rows = collect($this->getJson("/api/public/stations/{$this->station->id}/service-tickets?scope=all")->assertOk()->json('data'))->keyBy('id');
        $this->assertFalse($rows[$ticket->id]['member_conversation']['participant']);
        $this->postJson($this->path('apparatus_service_ticket', $ticket->id), ['body' => 'Me too', 'client_message_id' => self::SECOND_ID])->assertNotFound();
    }

    public function test_the_employee_page_shows_the_conversation_and_takes_a_reply_only_for_the_requester(): void
    {
        app(SubmissionConversationService::class)->postAsStaff($this->staff, ReplySubject::STATION_REQUEST, $this->request->id, 'Which sink, kitchen or bay?', self::FIRST_ID);
        Filament::setCurrentPanel(Filament::getPanel('employee'));

        $this->actingAsCanonicalUser($this->requester);
        $this->bindCanonicalSessionToLivewireTestRequests();
        Livewire::test(MySubmissionConversation::class, ['type' => 'station_request', 'id' => $this->request->id])
            ->assertSee('Which sink, kitchen or bay?')
            ->assertSee('Support Services')
            ->set('reply', 'Kitchen.')
            ->call('sendReply')
            ->assertHasNoErrors();
        $this->assertSame('Kitchen.', SubmissionMessage::query()->where('author_side', 'member')->sole()->body);

        $this->actingAsCanonicalUser($this->other);
        $this->bindCanonicalSessionToLivewireTestRequests();
        Livewire::test(MySubmissionConversation::class, ['type' => 'station_request', 'id' => $this->request->id])->assertNotFound();
    }

    private function path(string $type, int $id): string
    {
        return "/api/public/submission-conversations/{$type}/{$id}";
    }

    private function ticket(): ApparatusServiceTicket
    {
        $apparatus = Apparatus::query()->create([
            'station_id' => $this->station->id, 'unit_id' => 'E1', 'name' => 'Engine 1', 'type' => 'Engine', 'vehicle_number' => '1001',
            'designation' => 'E1', 'slug' => 'engine-1', 'status' => 'In Service', 'current_engine_hours' => 100, 'current_miles' => 1000,
        ]);

        return ApparatusServiceTicket::query()->create([
            'client_submission_id' => fake()->uuid(),
            'apparatus_id' => $apparatus->id,
            'station_id' => $this->station->id,
            'unit_designation_snapshot' => 'E1',
            'requested_by_employee_id' => $this->requester->employee_profile_id,
            'requester_name_snapshot' => 'Pat Requester',
            'origin' => 'station',
            'category' => 'repair_mechanical',
            'title' => 'Brakes squeal',
            'description' => 'Front brakes squeal on stops.',
            'priority' => 'routine',
            'status' => 'submitted',
        ])->refresh();
    }

    private function member(string $employeeNumber, string $name): User
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeNumber,
            'name' => $name,
            'rank' => 'Firefighter',
            'password' => Hash::make('test-password-only'),
            'must_change_password' => false,
        ]);

        return User::factory()->create([
            'employee_id' => $employee->employee_id,
            'employee_profile_id' => $employee->id,
            'account_status' => AccountStatus::Active,
        ]);
    }

    private function staff(): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active, 'must_change_password' => false]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['admin.access', 'admin.stations.view', 'admin.stations.manage'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
