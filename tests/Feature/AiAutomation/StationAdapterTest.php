<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Jobs\AiAutomation\ProcessAiIntake;
use App\Jobs\FanOutNotification;
use App\Models\AiAutomation\AiIntakeEvent;
use App\Models\Employee;
use App\Models\Room;
use App\Models\Station;
use App\Models\StationRequest;
use App\Models\StationRequestUpdate;
use App\Models\StationSupplyRequest;
use App\Models\User;
use App\Services\AiAutomation\DomainRegistry;
use App\Services\AiAutomation\Domains\StationAdapter;
use App\Services\AiAutomation\IntakeFacts;
use App\Services\Dashboards\Sources\Station\StationRequestSource;
use App\Support\Dashboards\DashboardQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\Dashboards\StationDashboardFixtures;
use Tests\TestCase;

final class StationAdapterTest extends TestCase
{
    use RefreshDatabase;
    use StationDashboardFixtures;

    private Station $station;

    private Employee $employee;

    private User $member;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->station = $this->station(1);
        $this->employee = Employee::query()->create([
            'employee_id' => '20991',
            'name' => 'Firefighter Requester',
            'rank' => 'Firefighter',
            'password' => Hash::make('test-password-only'),
            'must_change_password' => false,
        ]);
        $this->member = User::factory()->create([
            'employee_id' => $this->employee->employee_id,
            'employee_profile_id' => $this->employee->id,
        ]);
        $this->actingAsCanonicalUser($this->member);
        $this->room = Room::query()->create(['station_id' => $this->station->id, 'name' => 'Kitchen', 'room_type' => 'kitchen']);
    }

    // ---- hooks -----------------------------------------------------------------------------------------------

    public function test_switch_on_a_submitted_station_request_records_one_intake_and_queues_one_job(): void
    {
        $this->switchOn();
        Queue::fake();

        $response = $this->postJson('/api/public/station_request', $this->repairPayload())->assertCreated();
        $id = (int) $response->json('data.id');

        $row = AiIntakeEvent::query()->sole();
        $this->assertSame('station', $row->domain);
        $this->assertSame('station_request', $row->source_type);
        $this->assertSame($id, (int) $row->source_id);
        $this->assertSame('received', $row->event_key);
        $this->assertSame('queued', $row->state);
        Queue::assertPushed(ProcessAiIntake::class, 1);

        // A replay of the same submission is not a new request: still one ledger row, one job.
        $this->postJson('/api/public/station_request', $this->repairPayload())->assertOk();
        $this->assertDatabaseCount('ai_intake_events', 1);
        Queue::assertPushed(ProcessAiIntake::class, 1);
    }

    public function test_switch_off_a_submitted_station_request_records_nothing(): void
    {
        Queue::fake();

        $this->postJson('/api/public/station_request', $this->repairPayload())->assertCreated();

        $this->assertDatabaseCount('ai_intake_events', 0);
        Queue::assertNotPushed(ProcessAiIntake::class);
        Queue::assertPushed(FanOutNotification::class); // the existing staff alert is untouched
    }

    public function test_a_failing_intake_hook_never_fails_the_members_submission(): void
    {
        $this->switchOn();
        Queue::fake();
        $this->app->bind(DomainRegistry::class, static fn () => throw new RuntimeException('registry down'));

        $this->postJson('/api/public/station_request', $this->repairPayload())->assertCreated();

        $this->assertDatabaseCount('station_requests', 1);
        $this->assertDatabaseCount('ai_intake_events', 0);
    }

    public function test_a_station_supply_request_records_an_intake_only_while_the_switch_is_on(): void
    {
        Queue::fake();
        $url = "/api/v2/station-inventory/{$this->station->id}/supply-requests";

        $this->postJson($url, ['actor_shift' => 'A', 'request_text' => 'We are out of paper towels.'])->assertOk();
        $this->assertDatabaseCount('ai_intake_events', 0);

        $this->switchOn();
        $id = (int) $this->postJson($url, ['actor_shift' => 'A', 'request_text' => 'We need trash bags.'])->assertOk()->json('request.id');

        $row = AiIntakeEvent::query()->sole();
        $this->assertSame('station_supply_request', $row->source_type);
        $this->assertSame($id, (int) $row->source_id);
        Queue::assertPushed(ProcessAiIntake::class, 1);
    }

    // ---- facts -----------------------------------------------------------------------------------------------

    public function test_facts_for_an_open_repair_request(): void
    {
        $request = $this->makeRequest(['title' => 'Kitchen refrigerator is warm', 'description' => 'Above safe range.'], 'hvac');

        $facts = $this->adapter()->facts($this->intake('station_request', $request->id));

        $this->assertSame('station', $facts->domain);
        $this->assertSame('station_request', $facts->kind);
        $this->assertSame((int) $this->member->id, $facts->requesterUserId);
        $this->assertSame('hvac', $facts->category);
        $this->assertStringContainsString('Kitchen refrigerator is warm', (string) $facts->memberText);
        $this->assertStringContainsString('Above safe range.', (string) $facts->memberText);
        $this->assertTrue($facts->routed, 'An open request is in the staff worklist and alerts the station_requests audience.');
        $this->assertTrue($facts->eligible());
        $this->assertFalse($facts->alreadyAnswered);
    }

    public function test_facts_flag_missing_closed_archived_legacy_test_and_out_of_scope_requests(): void
    {
        $adapter = $this->adapter();

        $this->assertSame('source_missing', $adapter->facts($this->intake('station_request', 999999))->ineligibleReason);

        foreach (['cancelled', 'completed', 'denied'] as $status) {
            $closed = $this->makeRequest(['status' => $status]);
            $this->assertSame('request_'.$status, $adapter->facts($this->intake('station_request', $closed->id, 'k'.$status))->ineligibleReason);
        }

        $archived = $this->makeRequest();
        $archived->forceFill(['archived_at' => now()])->save();
        $this->assertSame('archived', $adapter->facts($this->intake('station_request', $archived->id))->ineligibleReason);

        $legacy = $this->makeRequest(['legacy_source' => 'big_ticket', 'legacy_id' => 7]);
        $this->assertSame('legacy_import', $adapter->facts($this->intake('station_request', $legacy->id))->ineligibleReason);

        $test = $this->makeRequest(['metadata' => ['is_test' => true]]);
        $this->assertSame('test_data', $adapter->facts($this->intake('station_request', $test->id))->ineligibleReason);

        $noAccount = $this->makeRequest(['actor_user_id' => null]);
        $this->assertSame('no_member_account', $adapter->facts($this->intake('station_request', $noAccount->id))->ineligibleReason);

        $ppe = $this->makeRequest(['request_type' => 'equipment', 'subject_type' => 'PPE'], 'PPE');
        $this->assertSame('out_of_scope_category', $adapter->facts($this->intake('station_request', $ppe->id))->ineligibleReason);

        $uniform = $this->makeRequest(['request_type' => 'equipment', 'subject_type' => 'equipment_item'], 'clothing', 'Uniform shirts');
        $this->assertSame('out_of_scope_category', $adapter->facts($this->intake('station_request', $uniform->id))->ineligibleReason);

        $radio = $this->makeRequest(['request_type' => 'equipment', 'subject_type' => 'equipment_item'], 'communications', 'Portable radio');
        $this->assertTrue($adapter->facts($this->intake('station_request', $radio->id))->eligible());
    }

    public function test_facts_report_already_answered_for_a_human_reply_or_prior_receipt_but_not_a_bare_assignment(): void
    {
        $adapter = $this->adapter();
        $admin = $this->adminWith(['admin.stations.manage']);

        $assignedOnly = $this->makeRequest();
        $assignedOnly->updates()->create(['status' => 'pending', 'changed_by_user_id' => $admin->id, 'metadata' => ['event' => 'workflow_transition']]);
        $this->assertFalse($adapter->facts($this->intake('station_request', $assignedOnly->id))->alreadyAnswered);

        $noted = $this->makeRequest();
        $noted->updates()->create(['status' => 'pending', 'public_note' => 'Vendor is coming Tuesday.', 'changed_by_user_id' => $admin->id]);
        $this->assertTrue($adapter->facts($this->intake('station_request', $noted->id))->alreadyAnswered);

        $moved = $this->makeRequest();
        $moved->updates()->create(['status' => 'under_review', 'changed_by_user_id' => $admin->id]);
        $this->assertTrue($adapter->facts($this->intake('station_request', $moved->id))->alreadyAnswered);

        $humanAcknowledged = $this->makeRequest(['acknowledged_at' => now(), 'acknowledged_by' => $admin->id]);
        $this->assertTrue($adapter->facts($this->intake('station_request', $humanAcknowledged->id))->alreadyAnswered);

        $receipted = $this->makeRequest();
        $adapter->writeReply($this->intake('station_request', $receipted->id), $this->facts(), 'Acknowledged.');
        $this->assertTrue($adapter->facts($this->intake('station_request', $receipted->id, 'again'))->alreadyAnswered);
    }

    public function test_facts_for_a_station_supply_request(): void
    {
        $adapter = $this->adapter();
        $open = $this->makeSupply();

        $facts = $adapter->facts($this->intake('station_supply_request', $open->id));
        $this->assertSame('supply_request', $facts->kind);
        $this->assertSame((int) $this->member->id, $facts->requesterUserId);
        $this->assertSame('We need more dish soap.', $facts->memberText);
        $this->assertFalse($facts->routed, 'Supply requests raise no staff alert, so the receipt must not claim they were forwarded.');
        $this->assertTrue($facts->eligible());

        $denied = $this->makeSupply(['status' => 'denied']);
        $this->assertSame('request_denied', $adapter->facts($this->intake('station_supply_request', $denied->id))->ineligibleReason);

        $archived = $this->makeSupply();
        $archived->forceFill(['archived_at' => now()])->save();
        $this->assertSame('archived', $adapter->facts($this->intake('station_supply_request', $archived->id))->ineligibleReason);

        $answered = $this->makeSupply(['public_response' => 'Ordered.']);
        $this->assertTrue($adapter->facts($this->intake('station_supply_request', $answered->id))->alreadyAnswered);

        $this->assertSame('source_missing', $adapter->facts($this->intake('station_supply_request', 424242))->ineligibleReason);
    }

    // ---- writeReply: station requests ------------------------------------------------------------------------

    public function test_write_reply_appends_one_receipt_and_changes_nothing_else(): void
    {
        Mail::fake();
        Notification::fake();
        $request = $this->makeRequest(['priority' => 'high']);
        $request->updates()->create(['status' => 'pending', 'public_note' => 'Request submitted.', 'metadata' => ['event' => 'submitted']]);
        $before = StationRequest::query()->findOrFail($request->id);
        $intake = $this->intake('station_request', $request->id);

        $record = $this->adapter()->writeReply($intake, $this->facts(), 'Acknowledged. Forwarded to the appropriate personnel.');

        $this->assertSame('station_request_update', $record->type);
        $updates = StationRequestUpdate::query()->where('station_request_id', $request->id)->orderBy('id')->get();
        $this->assertCount(2, $updates);
        $receipt = $updates->last();
        $this->assertSame($record->id, (int) $receipt->id);
        $this->assertNull($receipt->changed_by_user_id);
        $this->assertSame('pending', $receipt->status);
        $this->assertSame('Acknowledged. Forwarded to the appropriate personnel.', $receipt->public_note);
        $this->assertNull($receipt->internal_note);
        $this->assertSame((int) $intake->id, (int) $receipt->automation_intake_id);
        $this->assertSame('auto_receipt', $receipt->metadata['event']);
        $this->assertSame(['intake_id' => (int) $intake->id, 'sender' => 'AI Response'], $receipt->metadata['automation']);
        $this->assertTrue($receipt->isAutoReceipt());
        $this->assertSame('AI Response', $receipt->senderLabel());

        $after = StationRequest::query()->findOrFail($request->id);
        foreach (['status', 'priority', 'acknowledged_at', 'acknowledged_by', 'current_public_response', 'assigned_to_user_id', 'completed_at'] as $column) {
            $this->assertEquals($before->{$column}, $after->{$column}, "{$column} must not change");
        }
        $this->assertEquals($before->updated_at, $after->updated_at, 'The request row itself is not saved.');
        $this->assertNull($after->acknowledged_at);
        $this->assertNull($updates->first(fn ($u) => ($u->metadata['event'] ?? null) === 'workflow_transition'));
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    public function test_write_reply_is_idempotent_per_intake_and_per_request(): void
    {
        $request = $this->makeRequest();
        $adapter = $this->adapter();
        $intake = $this->intake('station_request', $request->id);

        $first = $adapter->writeReply($intake, $this->facts(), 'Acknowledged.');
        $second = $adapter->writeReply($intake, $this->facts(), 'Acknowledged.');
        $third = $adapter->writeReply($this->intake('station_request', $request->id, 'other'), $this->facts(), 'Acknowledged again.');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertSame(1, StationRequestUpdate::query()->where('station_request_id', $request->id)->autoReceipts()->count());
        $this->assertSame('Acknowledged.', StationRequestUpdate::query()->findOrFail($first->id)->public_note);
    }

    public function test_write_reply_for_a_missing_request_or_blank_text_fails_loudly_and_writes_nothing(): void
    {
        $request = $this->makeRequest();

        try {
            $this->adapter()->writeReply($this->intake('station_request', 999999), $this->facts(), 'Acknowledged.');
            $this->fail('A missing request must not be answered.');
        } catch (RuntimeException) {
        }
        try {
            $this->adapter()->writeReply($this->intake('station_request', $request->id), $this->facts(), '   ');
            $this->fail('Blank text must not be written.');
        } catch (RuntimeException) {
        }

        $this->assertDatabaseCount('station_request_updates', 0);
    }

    public function test_the_receipt_never_changes_the_admin_version_and_staff_can_still_act_on_the_version_they_hold(): void
    {
        $request = $this->makeRequest();
        $request->updates()->create(['status' => 'pending', 'public_note' => 'Request submitted.', 'metadata' => ['event' => 'submitted']]);
        $staff = $this->adminWith(['admin.stations.view', 'admin.stations.manage']);
        $source = app(StationRequestSource::class);
        $held = $source->rows($staff, DashboardQuery::now(), 50)[0]->version;
        $heldDetail = $source->detail($staff, (string) $request->id)->issue->version;

        $this->adapter()->writeReply($this->intake('station_request', $request->id), $this->facts(), 'Acknowledged.');

        $this->assertSame($held, $source->rows($staff, DashboardQuery::now(), 50)[0]->version);
        $this->assertSame($heldDetail, $source->detail($staff, (string) $request->id)->issue->version);

        $timeline = collect($source->detail($staff, (string) $request->id)->timeline);
        $this->assertSame('AI Response', $timeline->last()['actor']);

        $result = $source->perform($staff, (string) $request->id, 'acknowledge', ['public_note' => 'Looking at it.'], $held);
        $this->assertSame('done', $result->outcome, 'The assistant receipt must not cause a false conflict.');
        $this->assertNotNull($request->fresh()->acknowledged_at, 'Only the staff action acknowledges the request.');
    }

    public function test_members_see_the_receipt_as_ai_response_and_not_as_a_staff_acknowledgment(): void
    {
        $request = $this->makeRequest();
        $request->updates()->create(['status' => 'pending', 'public_note' => 'Request submitted.', 'metadata' => ['event' => 'submitted']]);
        $this->adapter()->writeReply($this->intake('station_request', $request->id), $this->facts(), 'Acknowledged. Forwarded to the appropriate personnel.');

        $list = $this->getJson("/api/public/stations/{$this->station->id}/requests")->assertOk();
        $list->assertJsonPath('data.0.ai_receipt.sender', 'AI Response')
            ->assertJsonPath('data.0.acknowledged_at', null)
            ->assertJsonPath('data.0.current_public_response', null)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.updates.0.sender', null)
            ->assertJsonPath('data.0.updates.0.is_auto_receipt', false)
            ->assertJsonPath('data.0.updates.1.sender', 'AI Response')
            ->assertJsonPath('data.0.updates.1.is_auto_receipt', true)
            ->assertJsonPath('data.0.updates.1.public_note', 'Acknowledged. Forwarded to the appropriate personnel.')
            ->assertJsonMissingPath('data.0.updates.1.changed_by_user_id')
            ->assertJsonMissingPath('data.0.updates.1.metadata');

        $this->getJson("/api/public/station-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.ai_receipt.sender', 'AI Response');

        $overview = $this->getJson("/api/public/stations/{$this->station->id}/overview")->assertOk();
        $this->assertSame('AI Response', $overview->json('requests.0.ai_receipt.sender'));
    }

    public function test_the_admin_request_page_shows_ai_response_as_the_sender(): void
    {
        $request = $this->makeRequest();
        $this->adapter()->writeReply($this->intake('station_request', $request->id), $this->facts(), 'Acknowledged.');
        $staff = $this->adminWith(['admin.stations.view', 'admin.stations.manage']);
        $this->actingAs($staff);
        $this->withoutVite();

        \Livewire\Livewire::test(\App\Filament\Resources\StationRequestResource\Pages\ViewStationRequest::class, ['record' => $request->id])
            ->assertSuccessful()
            ->assertSee('AI Response')
            ->assertSee('Acknowledged.');
    }

    // ---- writeReply: supply requests -------------------------------------------------------------------------

    public function test_a_supply_receipt_is_stored_apart_from_the_staff_response_and_is_idempotent(): void
    {
        Mail::fake();
        Notification::fake();
        $supply = $this->makeSupply();
        $before = StationSupplyRequest::query()->findOrFail($supply->id);
        $adapter = $this->adapter();
        $intake = $this->intake('station_supply_request', $supply->id);

        $first = $adapter->writeReply($intake, $this->facts('supply_request'), 'Received.');
        $second = $adapter->writeReply($intake, $this->facts('supply_request'), 'Received again.');

        $this->assertSame('station_supply_request_receipt', $first->type);
        $this->assertSame($first->id, $second->id);
        $after = StationSupplyRequest::query()->findOrFail($supply->id);
        $this->assertSame('Received.', $after->ai_receipt_text);
        $this->assertSame((int) $intake->id, (int) $after->ai_receipt_intake_id);
        $this->assertNotNull($after->ai_receipt_at);
        $this->assertSame('open', $after->status);
        $this->assertNull($after->public_response);
        $this->assertEquals($before->updated_at, $after->updated_at);
        Mail::assertNothingSent();
        Notification::assertNothingSent();

        $this->getJson("/api/v2/station-inventory/{$this->station->id}/supply-requests")
            ->assertOk()
            ->assertJsonPath('requests.0.ai_response.sender', 'AI Response')
            ->assertJsonPath('requests.0.ai_response.text', 'Received.')
            ->assertJsonPath('requests.0.public_response', null)
            ->assertJsonPath('requests.0.status', 'open');

        $this->assertTrue($adapter->facts($this->intake('station_supply_request', $supply->id, 'again'))->alreadyAnswered);
    }

    // ---- member url ------------------------------------------------------------------------------------------

    public function test_member_urls_point_at_where_the_member_reads_the_reply(): void
    {
        $request = $this->makeRequest();
        $supply = $this->makeSupply();
        $adapter = $this->adapter();

        $this->assertSame('/daily/stations/'.$this->station->id, $adapter->memberUrl($this->intake('station_request', $request->id)));
        $this->assertSame('/daily/forms-hub/station-inventory', $adapter->memberUrl($this->intake('station_supply_request', $supply->id)));
        $this->assertNull($adapter->memberUrl($this->intake('station_request', 999999)));
        $this->assertSame(['station_request', 'station_supply_request'], $adapter->sourceTypes());
        $this->assertSame(['station_request', 'station_supply_request'], $adapter->backlogSourceTypes());
        $this->assertSame('station', $adapter->domain());
    }

    // ---- backlog ---------------------------------------------------------------------------------------------

    public function test_backlog_candidates_skip_answered_closed_archived_legacy_and_out_of_scope_requests(): void
    {
        $adapter = $this->adapter();
        $admin = $this->adminWith(['admin.stations.manage']);

        $first = $this->makeRequest(['title' => 'Unattended one']);
        $this->makeRequest(['title' => 'Staff answered'])->updates()->create(['status' => 'pending', 'public_note' => 'On it.', 'changed_by_user_id' => $admin->id]);
        $receipted = $this->makeRequest(['title' => 'Already receipted']);
        $adapter->writeReply($this->intake('station_request', $receipted->id), $this->facts(), 'Acknowledged.');
        $this->makeRequest(['status' => 'cancelled']);
        $this->makeRequest(['status' => 'completed']);
        $this->makeRequest()->forceFill(['archived_at' => now()])->save();
        $this->makeRequest(['legacy_source' => 'fire_equipment']);
        $this->makeRequest(['actor_user_id' => null]);
        $this->makeRequest(['acknowledged_at' => now(), 'acknowledged_by' => $admin->id]);
        $this->makeRequest(['request_type' => 'equipment'], 'ppe');
        $second = $this->makeRequest(['title' => 'Unattended two'], 'plumbing');
        $this->makeRequest(['title' => 'Bare assignment only'])->updates()->create(['status' => 'pending', 'changed_by_user_id' => $admin->id]);

        $candidates = $adapter->backlogCandidates('station_request', 0, 50);

        $ids = array_map(fn ($c) => $c->sourceId, $candidates);
        $this->assertSame(
            [$first->id, $second->id, StationRequest::query()->where('title', 'Bare assignment only')->value('id')],
            $ids,
        );
        $this->assertSame('received', $candidates[0]->eventKey);
        $this->assertSame('station_request', $candidates[0]->sourceType);
        $this->assertNotNull($candidates[0]->submittedAt);
        $this->assertSame([], $adapter->backlogCandidates('vehicle_finding', 0, 50));
    }

    public function test_backlog_candidates_page_by_keyset_and_keep_filling_the_limit_past_filtered_rows(): void
    {
        $adapter = $this->adapter();
        $keep = [];
        for ($i = 0; $i < 4; $i++) {
            $keep[] = $this->makeRequest(['title' => "Keep {$i}"])->id;
            $this->makeRequest(['request_type' => 'equipment', 'title' => "PPE {$i}"], 'ppe');
            $this->makeRequest(['status' => 'cancelled']);
        }

        $page = $adapter->backlogCandidates('station_request', 0, 2);
        $this->assertSame([$keep[0], $keep[1]], array_map(fn ($c) => $c->sourceId, $page));

        $next = $adapter->backlogCandidates('station_request', $page[1]->sourceId, 2);
        $this->assertSame([$keep[2], $keep[3]], array_map(fn ($c) => $c->sourceId, $next));

        $this->assertSame([], $adapter->backlogCandidates('station_request', $keep[3], 2));
        $this->assertSame([], $adapter->backlogCandidates('station_request', 0, 0));
    }

    public function test_backlog_candidates_for_supply_requests(): void
    {
        $adapter = $this->adapter();
        $open = $this->makeSupply();
        $this->makeSupply(['public_response' => 'Ordered.']);
        $this->makeSupply(['status' => 'denied']);
        $this->makeSupply(['status' => 'replenished']);
        $archived = $this->makeSupply();
        $archived->forceFill(['archived_at' => now()])->save();
        $receipted = $this->makeSupply();
        $adapter->writeReply($this->intake('station_supply_request', $receipted->id), $this->facts('supply_request'), 'Received.');
        $second = $this->makeSupply();

        $ids = array_map(fn ($c) => $c->sourceId, $adapter->backlogCandidates('station_supply_request', 0, 10));

        $this->assertSame([$open->id, $second->id], $ids);
    }

    // ---- known findings --------------------------------------------------------------------------------------

    public function test_a_repair_request_stores_the_category_the_member_selected(): void
    {
        $payload = $this->repairPayload();
        $payload['items'][0]['category'] = 'hvac';

        $this->postJson('/api/public/station_request', $payload)->assertCreated();

        $request = StationRequest::query()->with('items')->sole();
        $this->assertSame('hvac', $request->items->sole()->category);
        $this->assertNotSame('appliance', $request->items->sole()->category);
        $this->assertSame('hvac', $this->adapter()->facts($this->intake('station_request', $request->id))->category);
    }

    public function test_a_station_request_can_be_read_by_id_only_by_its_requester_or_station_staff(): void
    {
        $request = $this->makeRequest();
        $this->getJson("/api/public/station-requests/{$request->id}")->assertOk()->assertJsonPath('data.id', $request->id);

        $staff = $this->adminWith(['admin.stations.view']);
        $this->actingAsCanonicalUser($staff);
        $this->getJson("/api/public/station-requests/{$request->id}")->assertOk();

        $otherEmployee = Employee::query()->create([
            'employee_id' => '20992',
            'name' => 'Another Member',
            'rank' => 'Firefighter',
            'password' => Hash::make('test-password-only'),
            'must_change_password' => false,
        ]);
        $other = User::factory()->create(['employee_id' => $otherEmployee->employee_id, 'employee_profile_id' => $otherEmployee->id]);
        $this->actingAsCanonicalUser($other);

        $this->getJson("/api/public/station-requests/{$request->id}")->assertNotFound();
        $this->getJson('/api/public/station-requests/987654')->assertNotFound();
        // The station crew's shared Requests tab (redacted, station-wide) is unchanged.
        $this->getJson("/api/public/stations/{$this->station->id}/requests")->assertOk()->assertJsonPath('data.0.id', $request->id);
    }

    // ---- helpers ---------------------------------------------------------------------------------------------

    private function adapter(): StationAdapter
    {
        return app(StationAdapter::class);
    }

    private function switchOn(): void
    {
        DB::table('ai_automation_settings')->update(['enabled' => true]);
    }

    private function intake(string $sourceType, int $sourceId, string $eventKey = 'received'): AiIntakeEvent
    {
        return AiIntakeEvent::query()->create([
            'domain' => 'station',
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'event_key' => $eventKey,
            'origin' => AiIntakeEvent::ORIGIN_LIVE,
            'epoch' => 1,
            'state' => AiIntakeEvent::STATE_QUEUED,
        ]);
    }

    private function facts(string $kind = 'station_request'): IntakeFacts
    {
        return new IntakeFacts('station', $kind, (int) $this->member->id);
    }

    /** @param  array<string, mixed>  $attributes */
    private function makeRequest(array $attributes = [], string $category = 'plumbing', string $itemName = 'Kitchen sink'): StationRequest
    {
        $request = StationRequest::query()->create(array_merge([
            'station_id' => $this->station->id,
            'room_id' => $this->room->id,
            'actor_user_id' => $this->member->id,
            'requested_by_employee_id' => $this->employee->id,
            'requester_name_snapshot' => 'Firefighter Requester',
            'request_type' => 'repair_service',
            'subject_type' => 'room',
            'title' => 'Kitchen sink leak',
            'description' => 'The sink drips constantly.',
            'priority' => 'normal',
            'status' => 'pending',
        ], $attributes))->refresh();
        $request->items()->create(['item_name' => $itemName, 'category' => $category, 'quantity' => 1]);

        return $request;
    }

    /** @param  array<string, mixed>  $attributes */
    private function makeSupply(array $attributes = []): StationSupplyRequest
    {
        $supply = StationSupplyRequest::query()->create(array_merge([
            'station_id' => $this->station->id,
            'actor_user_id' => $this->member->id,
            'actor_employee_id' => $this->employee->id,
            'request_text' => 'We need more dish soap.',
            'status' => 'open',
            'created_by_name' => 'Firefighter Requester',
            'created_by_shift' => 'A',
        ], $attributes));
        if (isset($attributes['public_response'])) {
            $supply->forceFill(['public_response' => $attributes['public_response']])->save();
        }

        return $supply->refresh();
    }

    /** @return array<string, mixed> */
    private function repairPayload(): array
    {
        return [
            'client_submission_id' => '67f5a680-220d-47df-8b94-0ed1d99dd2e4',
            'station_id' => $this->station->id,
            'room_id' => $this->room->id,
            'requested_by_employee_id' => $this->employee->id,
            'request_type' => 'repair_service',
            'subject_type' => 'room',
            'title' => 'Kitchen refrigerator is warm',
            'description' => 'The refrigerator temperature rose above the safe range.',
            'priority' => 'high',
            'items' => [[
                'item_name' => 'Refrigerator',
                'category' => 'appliance',
                'quantity' => 1,
                'reason' => 'Damaged/Broken',
                'condition' => 'needs_repair',
            ]],
        ];
    }
}
