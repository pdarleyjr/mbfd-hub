<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Enums\AccountStatus;
use App\Filament\Admin\Pages\AiResponseAutomation;
use App\Jobs\AiAutomation\ProcessAiBacklogBatch;
use App\Jobs\AiAutomation\ProcessAiIntake;
use App\Models\AiAutomation\AiAutomationSwitchEvent;
use App\Models\AiAutomation\AiBacklogRun;
use App\Models\AiAutomation\AiIntakeEvent;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusDefectParticipant;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\ApparatusIssueMessage;
use App\Models\Employee;
use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Models\HubSupportTicket;
use App\Models\Room;
use App\Models\StationRequest;
use App\Models\StationRequestUpdate;
use App\Models\User;
use App\Notifications\AiResponseNotification;
use App\Services\AiAutomation\AiAutomationSwitch;
use App\Services\AiAutomation\AiIntake;
use App\Services\AiAutomation\BacklogService;
use App\Services\AiAutomation\DomainRegistry;
use App\Services\AiAutomation\IntakeProcessor;
use App\Services\Inspections\IssueMessageService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Dashboards\StationDashboardFixtures;
use Tests\Feature\EmployeeMessaging\BuildsEmployeeMessagingFixtures;
use Tests\Feature\Inspections\Concerns\BuildsDailyScenarioWorld;
use Tests\TestCase;

/**
 * The whole AI Response automation with the REAL adapters, the real member-facing paths (checkout API, station
 * request API, Support Services messaging service), the real ledger, the real in-app notification, and the
 * default (sync) queue. Only mail is faked, so any email would be caught.
 */
final class EndToEndTest extends TestCase
{
    use BuildsDailyScenarioWorld;
    use BuildsEmployeeMessagingFixtures;
    use RefreshDatabase;
    use StationDashboardFixtures;

    private const SAFETY_ITEM = 'SCBA';

    /** Engine 2 checklist: low-cost consumable the playbook answers with the "check your station" reply. */
    private const CONSUMABLE_ITEM = 'Yellow Safety Tape/Duct Tape';

    private User $alpha;

    private User $reviewer;

    private User $requester;

    private Employee $requesterEmployee;

    private Room $room;

    private int $submissionSeq = 0;

    /** @var list<array{0: string, 1: string}> notification class and channel, as actually delivered */
    private array $delivered = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Replies add a 300 line on Saturdays and Sundays; pin a Tuesday so the wording checks never depend on the run day.
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-03 15:00:00', 'America/New_York'));
        // Production seeds the equipment priorities before automation is turned on; so do these tests.
        app(\App\Services\SupportTriage\EquipmentRuleService::class)->seedDefaults();
        Mail::fake();
        $this->buildWorld();
        RateLimiter::clear('employee-logistics-message:1');

        $this->alpha = $this->newMember('Alpha Member');
        $this->reviewer = $this->newReviewer('Logistics Reviewer');

        $home = $this->station(1);
        $this->requesterEmployee = Employee::query()->create([
            'employee_id' => '20991', 'name' => 'Firefighter Requester', 'rank' => 'Firefighter',
            'password' => Hash::make('test-password-only'), 'must_change_password' => false,
        ]);
        $this->requester = User::factory()->create([
            'employee_id' => $this->requesterEmployee->employee_id,
            'employee_profile_id' => $this->requesterEmployee->id,
        ]);
        $this->room = Room::query()->create(['station_id' => $home->id, 'name' => 'Kitchen', 'room_type' => 'kitchen']);

        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            $this->delivered[] = [$event->notification::class, $event->channel];
        });
    }

    // ================================================================================== 1. OFF by default

    public function test_with_the_switch_off_nothing_is_recorded_or_answered_anywhere(): void
    {
        $this->assertFalse(app(AiAutomationSwitch::class)->isOn(), 'Fresh install: OFF.');

        $this->submitCheckout(self::CONSUMABLE_ITEM);
        $this->submitStationRequest();
        $this->supportStarts($this->messagingMember('Quiet Member'));

        $this->assertSame(0, AiIntakeEvent::query()->count());
        $this->assertSame(['vehicle' => 0, 'station' => 0, 'support' => 0], $this->assistantRecords());
        $this->assertSame(0, $this->aiNotifications()->count());
        $this->assertSame([], $this->aiDeliveries());
    }

    // ================================================================================== 2. ON: one reply each

    public function test_a_checkout_with_a_missing_consumable_gets_one_station_2_reply_and_one_bell(): void
    {
        $this->switchOn();

        $inspection = $this->submitCheckout(self::CONSUMABLE_ITEM);

        $messages = ApparatusIssueMessage::query()->where('author_context', 'assistant')->get();
        $this->assertCount(1, $messages);
        $this->assertSame((int) $inspection->id, (int) $messages[0]->apparatus_inspection_id);
        $this->assertSame('member', $messages[0]->visibility);
        $this->assertStringContainsString('for the Yellow Safety Tape/Duct Tape first', $messages[0]->body);
        $this->assertStringContainsString('call or text before coming by Station 2', $messages[0]->body);

        $row = AiIntakeEvent::query()->where('source_type', 'apparatus_inspection')->sole();
        $this->assertSame(AiIntakeEvent::STATE_SENT, $row->state);
        $this->assertSame($messages[0]->body, $row->reply_text);
        $this->assertSame('apparatus_issue_message', $row->reply_record_type);
        $this->assertSame((int) $messages[0]->id, (int) $row->reply_record_id);

        $this->assertOneBell($this->alpha, $messages[0]->body);
        $this->assertNoMailAndNoPush();

        // The member's own screen shows it as Support Services (never an 'AI' title).
        $this->actAs($this->alpha);
        $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertOk()->assertSee('Support Services')->assertDontSee('AI Response')->assertSee('Station 2');
    }

    public function test_a_station_request_gets_one_ai_response_update_and_one_bell(): void
    {
        $this->switchOn();

        $id = $this->submitStationRequest();

        $updates = StationRequestUpdate::query()->where('station_request_id', $id)->autoReceipts()->get();
        $this->assertCount(1, $updates);
        $this->assertSame('Support Services', $updates[0]->senderLabel());
        $this->assertNull($updates[0]->changed_by_user_id);
        $this->assertStringStartsWith('Acknowledged.', (string) $updates[0]->public_note);

        $row = AiIntakeEvent::query()->where('source_type', 'station_request')->sole();
        $this->assertSame(AiIntakeEvent::STATE_SENT, $row->state);
        $this->assertSame((int) $updates[0]->id, (int) $row->reply_record_id);

        $this->assertOneBell($this->requester, (string) $updates[0]->public_note);
        $this->assertNoMailAndNoPush();
    }

    public function test_a_support_services_message_gets_one_assistant_message_and_one_bell(): void
    {
        $this->switchOn();
        $member = $this->messagingMember('Support Member');

        $message = $this->supportStarts($member, 'Do you have any spare folding chairs for the open house?');

        $replies = EmployeeLogisticsMessage::query()->where('author_side', EmployeeLogisticsMessage::SIDE_ASSISTANT)->get();
        $this->assertCount(1, $replies);
        $this->assertSame((int) $message->thread_id, (int) $replies[0]->thread_id);
        $this->assertSame('Support Services', $replies[0]->author_name);
        $this->assertNull($replies[0]->author_user_id);

        $row = AiIntakeEvent::query()->where('source_type', 'employee_logistics_message')->sole();
        $this->assertSame(AiIntakeEvent::STATE_SENT, $row->state);
        $this->assertSame($replies[0]->body, $row->reply_text);

        $this->assertOneBell($member, (string) $replies[0]->body);
        $this->assertNoMailAndNoPush();
    }

    public function test_all_three_areas_together_give_exactly_one_reply_each_and_nothing_else(): void
    {
        $this->switchOn();

        $this->submitCheckout(self::CONSUMABLE_ITEM);
        $this->submitStationRequest();
        $this->supportStarts($this->messagingMember('Support Member'));

        $this->assertSame(['vehicle' => 1, 'station' => 1, 'support' => 1], $this->assistantRecords());
        $this->assertSame(3, AiIntakeEvent::query()->where('state', AiIntakeEvent::STATE_SENT)->count());
        $this->assertSame(3, $this->aiNotifications()->count());
        $this->assertNoMailAndNoPush();
    }

    // ============================================================ 2b. domain state is untouched by the reply

    public function test_the_vehicle_reply_leaves_every_defect_inspection_and_unit_field_alone(): void
    {
        $this->switchOn();
        Queue::fake();
        $inspection = $this->submitCheckout(self::CONSUMABLE_ITEM);
        $intake = AiIntakeEvent::query()->where('source_type', 'apparatus_inspection')->sole();
        $this->assertSame(AiIntakeEvent::STATE_QUEUED, $intake->state);
        $before = $this->vehicleSnapshot();
        $defect = ApparatusDefect::query()->sole();
        $actionStatus = $defect->action_status;

        $this->assertSame(AiIntakeEvent::STATE_SENT, app(IntakeProcessor::class)->process((int) $intake->id));

        $this->assertSame(1, ApparatusIssueMessage::query()->where('author_context', 'assistant')->count());
        $this->assertSame($before, $this->vehicleSnapshot(), 'No defect, inspection, unit, event or review record may move.');
        $this->assertSame($actionStatus, $defect->fresh()->action_status);
        $fresh = ApparatusInspection::query()->findOrFail($inspection->id);
        $this->assertSame($inspection->review_status, $fresh->review_status);
        $this->assertSame($inspection->processing_status, $fresh->processing_status);
    }

    public function test_a_missing_kitty_litter_gets_the_exact_station_2_text(): void
    {
        // Kitty Litter is only on the Rescue checklist (Captain 5 scope), so the same finding is relabelled on an
        // Engine 2 consumable before the worker runs; the wording rules only look at the item name.
        $this->switchOn();
        Queue::fake();
        $inspection = $this->submitCheckout(self::CONSUMABLE_ITEM);
        ApparatusDefect::query()->sole()->forceFill(['item' => 'Kitty Litter'])->save();

        app(IntakeProcessor::class)->process((int) AiIntakeEvent::query()->sole()->id);

        $message = ApparatusIssueMessage::query()->where('author_context', 'assistant')->sole();
        $this->assertSame((int) $inspection->id, (int) $message->apparatus_inspection_id);
        $this->assertSame('Please check around the station and other apparatus for the Kitty Litter first. If nothing turns up, call or text before coming by Station 2 so we can check on a replacement.', $message->body);
    }

    public function test_the_station_reply_leaves_status_priority_acknowledgment_and_public_response_alone(): void
    {
        $this->switchOn();
        Queue::fake();
        $id = $this->submitStationRequest();
        $intake = AiIntakeEvent::query()->where('source_type', 'station_request')->sole();
        $before = StationRequest::query()->findOrFail($id);

        $this->assertSame(AiIntakeEvent::STATE_SENT, app(IntakeProcessor::class)->process((int) $intake->id));

        $after = StationRequest::query()->findOrFail($id);
        foreach (['status', 'priority', 'acknowledged_at', 'acknowledged_by', 'current_public_response', 'assigned_to_user_id', 'completed_at', 'updated_at'] as $column) {
            $this->assertEquals($before->{$column}, $after->{$column}, "{$column} must not change");
        }
        $this->assertNull($after->acknowledged_at, 'An AI Response is never an acknowledgment.');
        $this->assertNull($after->acknowledged_by);
        $this->assertSame(1, StationRequestUpdate::query()->where('station_request_id', $id)->autoReceipts()->count());
        $this->assertSame(0, StationRequestUpdate::query()->where('station_request_id', $id)->where('metadata->event', 'workflow_transition')->count());
    }

    public function test_the_support_reply_leaves_the_answered_markers_alone(): void
    {
        $this->switchOn();
        Queue::fake();
        $member = $this->messagingMember('Support Member');
        $message = $this->supportStarts($member);
        $intake = AiIntakeEvent::query()->where('source_type', 'employee_logistics_message')->sole();
        $before = $this->threadState($message);

        $this->assertSame(AiIntakeEvent::STATE_SENT, app(IntakeProcessor::class)->process((int) $intake->id));

        $after = $this->threadState($message);
        $this->assertSame($before, $after);
        $this->assertNotSame(EmployeeLogisticsMessage::SIDE_ASSISTANT, $after['last_message_side']);
        $this->assertSame($message->id, (int) $after['last_member_message_id']);
        $this->assertNull($after['last_logistics_message_id']);
    }

    // ================================================================================== 3. critical item

    public function test_a_missing_scba_gets_the_high_ppe_reply_and_is_flagged_critical(): void
    {
        $this->switchOn();

        $inspection = $this->submitCheckout(self::SAFETY_ITEM);

        $message = ApparatusIssueMessage::query()->where('author_context', 'assistant')->sole();
        $this->assertSame((int) $inspection->id, (int) $message->apparatus_inspection_id);
        // A complete SCBA is HIGH protective equipment: officer first, no Station 2 replacement guidance.
        $this->assertSame('Acknowledged. Notify your officer immediately and coordinate with Support Services or 300 for further direction.', $message->body);
        $row = AiIntakeEvent::query()->where('source_type', 'apparatus_inspection')->sole();
        $this->assertSame($message->body, $row->reply_text);
        $this->assertSame('critical', $row->risk_tier);
        $this->assertTrue((bool) $row->needs_human);
    }

    // ================================================================================== 4. replays and bursts

    public function test_resubmitting_the_same_checkout_never_adds_a_second_reply(): void
    {
        $this->switchOn();
        $payload = $this->checkoutPayload(self::CONSUMABLE_ITEM);

        $this->actAs($this->alpha);
        $this->submit($payload)->assertCreated();
        $this->submit($payload)->assertOk();
        $this->submit($payload)->assertOk();

        $this->assertSame(1, ApparatusInspection::query()->count());
        $this->assertSame(1, ApparatusIssueMessage::query()->where('author_context', 'assistant')->count());
        $this->assertSame(1, AiIntakeEvent::query()->count());
        $this->assertSame(1, $this->aiNotifications()->count());
    }

    public function test_resubmitting_the_same_station_request_never_adds_a_second_reply(): void
    {
        $this->switchOn();
        $key = (string) Str::uuid();

        $first = $this->submitStationRequest($key);
        $second = $this->submitStationRequest($key, expectCreated: false);

        $this->assertSame($first, $second);
        $this->assertSame(1, StationRequest::query()->count());
        $this->assertSame(1, StationRequestUpdate::query()->autoReceipts()->count());
        $this->assertSame(1, AiIntakeEvent::query()->count());
        $this->assertSame(1, $this->aiNotifications()->count());
    }

    public function test_a_retried_support_send_never_adds_a_second_reply(): void
    {
        $this->switchOn();
        $member = $this->messagingMember('Support Member');
        $requestId = $this->rid();

        $this->service()->startThread($member, 'Same text', null, $requestId);
        $this->service()->startThread($member, 'Same text', null, $requestId);

        $this->assertSame(1, EmployeeLogisticsMessage::query()->where('author_side', EmployeeLogisticsMessage::SIDE_ASSISTANT)->count());
        $this->assertSame(1, AiIntakeEvent::query()->count());
    }

    public function test_three_support_messages_in_a_row_produce_one_ai_reply_in_that_thread(): void
    {
        $this->switchOn();
        $member = $this->messagingMember('Chatty Member');

        $first = $this->supportStarts($member, 'One');
        $second = $this->memberReplies($member, $first, 'Two');
        $third = $this->memberReplies($member, $first, 'Three');

        $this->assertSame(1, EmployeeLogisticsMessage::query()->where('thread_id', $first->thread_id)->where('author_side', EmployeeLogisticsMessage::SIDE_ASSISTANT)->count());
        $this->assertSame(1, $this->aiNotifications()->count());
        foreach ([$second, $third] as $later) {
            $skipped = AiIntakeEvent::query()->where('source_id', $later->id)->sole();
            $this->assertSame(AiIntakeEvent::STATE_SKIPPED, $skipped->state);
            $this->assertContains('recent_ai_reply_in_thread', $skipped->reason_codes);
        }
    }

    public function test_three_member_messages_on_a_vehicle_issue_thread_get_one_ai_reply_on_that_thread(): void
    {
        $this->switchOn();
        $inspection = $this->submitCheckout(self::CONSUMABLE_ITEM);
        $defect = ApparatusDefect::query()->sole();
        $messages = app(IssueMessageService::class);
        $this->assertSame(1, ApparatusIssueMessage::query()->where('author_context', 'assistant')->whereNull('apparatus_defect_id')->count());

        foreach (['Any word on this?', 'Still not found.', 'Checking again tomorrow.'] as $body) {
            $messages->post($this->alpha, ['inspection_id' => $inspection->id, 'defect_id' => $defect->id, 'body' => $body]);
        }

        $this->assertSame(1, ApparatusIssueMessage::query()->where('author_context', 'assistant')->where('apparatus_defect_id', $defect->id)->count());
        $this->assertSame(2, ApparatusIssueMessage::query()->where('author_context', 'assistant')->count(), 'One receipt for the checkout, one for the issue thread.');
        $this->assertSame(2, AiIntakeEvent::query()->where('source_type', 'apparatus_issue_message')->where('state', AiIntakeEvent::STATE_SKIPPED)->count());
        $this->assertSame(0, ApparatusIssueMessage::query()->fromStaff()->count(), 'The assistant never counts as a staff reply.');
        $this->assertNoMailAndNoPush();
    }

    public function test_a_burst_that_was_queued_together_is_answered_once_through_the_newest_message(): void
    {
        $this->switchOn();
        Queue::fake(); // the three jobs are waiting when the worker finally runs
        $member = $this->messagingMember('Chatty Member');
        $first = $this->supportStarts($member, 'One');
        $this->memberReplies($member, $first, 'Two');
        $this->memberReplies($member, $first, 'Three');
        $this->assertSame(3, AiIntakeEvent::query()->where('state', AiIntakeEvent::STATE_QUEUED)->count());

        foreach (AiIntakeEvent::query()->orderBy('id')->pluck('id') as $id) {
            app(IntakeProcessor::class)->process((int) $id);
        }

        $this->assertSame(1, EmployeeLogisticsMessage::query()->where('author_side', EmployeeLogisticsMessage::SIDE_ASSISTANT)->count());
        $this->assertSame(1, AiIntakeEvent::query()->where('state', AiIntakeEvent::STATE_SENT)->count());
        $this->assertSame(2, AiIntakeEvent::query()->where('state', AiIntakeEvent::STATE_SKIPPED)->count());
        $sent = AiIntakeEvent::query()->where('state', AiIntakeEvent::STATE_SENT)->sole();
        $this->assertSame(EmployeeLogisticsMessage::query()->where('thread_id', $first->thread_id)->where('author_side', 'member')->max('id'), (int) $sent->source_id);
    }

    // ================================================================================== 5. cooldown

    public function test_a_member_reply_after_a_staff_reply_within_a_day_gets_no_second_ai_reply(): void
    {
        $this->switchOn();
        $member = $this->messagingMember('Returning Member');
        $first = $this->supportStarts($member, 'First question');
        $this->assertSame(1, $this->assistantRecords()['support']);

        $this->logisticsReplies($this->logisticsResponder(), $first, 'We are on it.');
        $second = $this->memberReplies($member, $first, 'Thanks, one more thing.');

        $this->assertSame(1, $this->assistantRecords()['support'], 'No second AI reply in the same thread within 24 hours.');
        $skipped = AiIntakeEvent::query()->where('source_id', $second->id)->sole();
        $this->assertSame(AiIntakeEvent::STATE_SKIPPED, $skipped->state);
        $this->assertContains('recent_ai_reply_in_thread', $skipped->reason_codes);
        $this->assertSame(1, $this->aiNotifications()->count());
    }

    public function test_after_the_cooldown_window_the_same_thread_can_be_answered_again(): void
    {
        $this->switchOn();
        $member = $this->messagingMember('Patient Member');
        $first = $this->supportStarts($member, 'First question');
        $this->logisticsReplies($this->logisticsResponder(), $first, 'We are on it.');

        $this->travel(25)->hours();
        $second = $this->memberReplies($member, $first, 'A new question a day later.');

        $this->assertSame(2, $this->assistantRecords()['support']);
        $this->assertSame(AiIntakeEvent::STATE_SENT, AiIntakeEvent::query()->where('source_id', $second->id)->sole()->state);
    }

    // ================================================================================== 6. OFF mid-flow

    public function test_off_while_work_is_queued_cancels_it_and_a_later_on_never_revives_it(): void
    {
        $this->switchOn();
        Queue::fake(); // hold the jobs
        $this->submitCheckout(self::CONSUMABLE_ITEM);
        $this->submitStationRequest();
        $this->supportStarts($this->messagingMember('Support Member'));
        $ids = AiIntakeEvent::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertCount(3, $ids);
        $this->assertSame(3, AiIntakeEvent::query()->where('state', AiIntakeEvent::STATE_QUEUED)->count());
        Queue::assertPushed(ProcessAiIntake::class, 3);

        $result = app(AiAutomationSwitch::class)->turnOff(null, 'console');
        $this->assertSame(3, $result['suppressed']);

        foreach ($ids as $id) {
            $this->assertSame(AiIntakeEvent::STATE_CANCELLED, app(IntakeProcessor::class)->process($id));
        }
        $this->assertSame(['vehicle' => 0, 'station' => 0, 'support' => 0], $this->assistantRecords());
        $this->assertSame(0, $this->aiNotifications()->count());

        // ON again: the old jobs are inert, even if one is forced back to "queued" under its old epoch.
        app(AiAutomationSwitch::class)->turnOn(null, 'console');
        AiIntakeEvent::query()->whereKey($ids[0])->update(['state' => AiIntakeEvent::STATE_QUEUED]);
        foreach ($ids as $id) {
            $this->assertSame(AiIntakeEvent::STATE_CANCELLED, app(IntakeProcessor::class)->process($id));
        }
        $this->assertSame(['vehicle' => 0, 'station' => 0, 'support' => 0], $this->assistantRecords());
        $this->assertSame(0, $this->aiNotifications()->count());
        $this->assertSame(3, AiIntakeEvent::query()->where('state', AiIntakeEvent::STATE_CANCELLED)->count());
    }

    // ================================================================================== 7. scope

    public function test_workflows_outside_the_three_areas_never_touch_the_ledger(): void
    {
        $this->switchOn();

        // IT / Hub support ticket: created and replied to by its reporter through the real route.
        $ticket = HubSupportTicket::factory()->create(['reported_by_user_id' => $this->alpha->id]);
        $this->actAs($this->alpha);
        $this->post(route('hub-support.reply', $ticket), ['response' => 'After I selected it, nothing happened.']);

        // Personnel / PPE request rows.
        $this->personnelEquipmentRequest();

        $this->assertSame(0, AiIntakeEvent::query()->count());
        $this->assertSame(['vehicle' => 0, 'station' => 0, 'support' => 0], $this->assistantRecords());
        $this->assertSame(0, $this->aiNotifications()->count());
    }

    public function test_the_registry_refuses_unknown_source_types_and_nothing_is_recorded_for_them(): void
    {
        $this->switchOn();
        $registry = app(DomainRegistry::class);

        foreach (['ems_inventory_request', 'narcotics_check', 'captain5_issue', 'roster_day', 'hub_support_ticket', 'personnel_request', '', 'apparatus_inspection '] as $type) {
            $this->assertNull($registry->forSourceType($type), "'{$type}' must not reach the engine");
            AiIntake::record($type, 1);
        }
        foreach (['ems', 'narcotics', 'roster', 'personnel', 'captain5'] as $domain) {
            $this->assertFalse($registry->domainEnabled($domain), "'{$domain}' is not an in-scope domain");
        }
        $this->assertSame(['vehicle', 'station', 'support'], array_values(array_unique(array_map(fn ($a) => $a->domain(), $registry->all()))));
        $this->assertSame(0, AiIntakeEvent::query()->count());
    }

    public function test_a_disabled_domain_flag_keeps_that_area_silent(): void
    {
        $this->switchOn();
        config(['ai_automation.domains.station' => false]);

        $this->submitStationRequest();
        $this->submitCheckout(self::CONSUMABLE_ITEM);

        $this->assertSame(0, AiIntakeEvent::query()->where('domain', 'station')->count());
        $this->assertSame(['vehicle' => 1, 'station' => 0, 'support' => 0], $this->assistantRecords());
    }

    public function test_the_server_side_hard_disable_beats_the_admin_switch(): void
    {
        $this->switchOn();
        config(['ai_automation.hard_disabled' => true]);

        $this->submitStationRequest();
        $this->submitCheckout(self::CONSUMABLE_ITEM);
        $this->supportStarts($this->messagingMember('Support Member'));

        $this->assertSame(0, AiIntakeEvent::query()->count());
        $this->assertSame(['vehicle' => 0, 'station' => 0, 'support' => 0], $this->assistantRecords());
    }

    // ================================================================================== 8. backlog

    public function test_the_backlog_answers_each_unanswered_submission_once_and_ignores_answered_ones(): void
    {
        config(['ai_automation.backlog.batch_size' => 2]);
        $switch = app(AiAutomationSwitch::class);
        $this->assertFalse($switch->isOn());

        // Switch OFF: three unanswered submissions and one answered by staff in each area.
        $unanswered = [
            'vehicle' => $this->submitCheckout(self::CONSUMABLE_ITEM),
            'station' => $this->submitStationRequest(),
            'support' => $this->supportStarts($this->messagingMember('Waiting Member'), 'Where do I pick up a replacement key?'),
        ];
        $answeredInspection = $this->submitCheckout('Spanner Wrench', $this->frontline, 'engine');
        app(IssueMessageService::class)->post($this->reviewer, ['inspection_id' => $answeredInspection->id, 'visibility' => 'member', 'body' => 'We have it.']);
        $answeredRequest = $this->submitStationRequest(title: 'Answered request');
        StationRequest::query()->findOrFail($answeredRequest)->updates()->create([
            'status' => 'pending', 'public_note' => 'Vendor is coming Tuesday.', 'changed_by_user_id' => $this->adminWith(['admin.stations.manage'])->id,
        ]);
        $answeredMember = $this->messagingMember('Answered Member');
        $answeredThread = $this->supportStarts($answeredMember, 'Question already handled');
        $this->logisticsReplies($this->logisticsResponder(), $answeredThread, 'Handled by phone.');

        $this->assertSame(0, AiIntakeEvent::query()->count(), 'Nothing recorded while OFF.');
        $this->assertSame(['vehicle' => 0, 'station' => 0, 'support' => 0], $this->assistantRecords());

        // Preview reads only.
        $backlog = app(BacklogService::class);
        $run = $backlog->preview($this->reviewerAdmin());
        $preview = $run->preview;
        $this->assertSame(AiBacklogRun::STATE_PREVIEWED, $run->state);
        $this->assertSame(3, $preview['would_send']);
        foreach (['vehicle', 'station', 'support'] as $domain) {
            $this->assertSame(1, $preview['by_domain'][$domain]['would_send'], $domain);
        }
        $this->assertSame(0, AiIntakeEvent::query()->count(), 'Preview writes no ledger rows.');
        $this->assertSame(['vehicle' => 0, 'station' => 0, 'support' => 0], $this->assistantRecords());

        // Start refuses while OFF.
        $this->assertFalse($backlog->start($run));
        $this->assertSame(AiBacklogRun::STATE_PREVIEWED, $run->fresh()->state);

        // ON, then process in bounded batches.
        $switch->turnOn(null, 'console');
        Queue::fake();
        Mail::fake();
        $bellsBefore = DB::table('notifications')->count();
        $this->assertTrue($backlog->start($run->fresh()));
        Queue::assertPushed(ProcessAiBacklogBatch::class);
        $epoch = $switch->epoch();
        $batches = 0;
        while ($backlog->processBatch((int) $run->id, $epoch) && $batches < 20) {
            $batches++;
        }
        $this->assertGreaterThan(0, $batches, 'Batch size 2 over three candidates needs more than one batch.');

        $this->assertSame(AiBacklogRun::STATE_COMPLETED, $run->fresh()->state);
        $this->assertSame(['vehicle' => 1, 'station' => 1, 'support' => 1], $this->assistantRecords());
        $this->assertSame(3, AiIntakeEvent::query()->where('origin', AiIntakeEvent::ORIGIN_BACKLOG)->where('state', AiIntakeEvent::STATE_SENT)->count());
        $this->assertSame(0, AiIntakeEvent::query()->where('source_id', $answeredInspection->id)->where('source_type', 'apparatus_inspection')->count());
        $this->assertSame(0, ApparatusIssueMessage::query()->where('author_context', 'assistant')->where('apparatus_inspection_id', $answeredInspection->id)->count());
        $this->assertSame(0, StationRequestUpdate::query()->where('station_request_id', $answeredRequest)->autoReceipts()->count());
        $this->assertSame(0, EmployeeLogisticsMessage::query()->where('thread_id', $answeredThread->thread_id)->where('author_side', EmployeeLogisticsMessage::SIDE_ASSISTANT)->count());
        $this->assertSame(1, ApparatusIssueMessage::query()->where('author_context', 'assistant')->where('apparatus_inspection_id', $unanswered['vehicle']->id)->count());
        $this->assertSame(1, StationRequestUpdate::query()->where('station_request_id', $unanswered['station'])->autoReceipts()->count());
        $this->assertSame(1, EmployeeLogisticsMessage::query()->where('thread_id', $unanswered['support']->thread_id)->where('author_side', EmployeeLogisticsMessage::SIDE_ASSISTANT)->count());

        // Backlog replies are silent: no bell, no mail, no push.
        $this->assertSame($bellsBefore, DB::table('notifications')->count());
        $this->assertSame(0, $this->aiNotifications()->count());
        $this->assertSame([], $this->aiDeliveries());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();

        // A second preview and run sends nothing new.
        $again = $backlog->preview($this->reviewerAdmin());
        $this->assertSame(0, $again->preview['would_send']);
        $this->assertTrue($backlog->start($again->fresh()));
        $batches = 0;
        while ($backlog->processBatch((int) $again->id, $epoch) && $batches < 20) {
            $batches++;
        }
        $this->assertSame(['vehicle' => 1, 'station' => 1, 'support' => 1], $this->assistantRecords());
        $this->assertSame(3, AiIntakeEvent::query()->count());
    }

    public function test_turning_off_during_a_backlog_run_stops_it_and_on_does_not_resume_it(): void
    {
        config(['ai_automation.backlog.batch_size' => 1]);
        $switch = app(AiAutomationSwitch::class);
        $this->submitStationRequest(title: 'One');
        $this->submitStationRequest(title: 'Two');
        $this->submitStationRequest(title: 'Three');
        $backlog = app(BacklogService::class);
        $run = $backlog->preview(null);
        $this->assertSame(3, $run->preview['would_send']);
        $switch->turnOn(null, 'console');
        Queue::fake();
        $this->assertTrue($backlog->start($run->fresh()));
        $epoch = $switch->epoch();

        $this->assertTrue($backlog->processBatch((int) $run->id, $epoch));
        $this->assertSame(1, $this->assistantRecords()['station']);

        $switch->turnOff(null, 'console');
        $this->assertSame(AiBacklogRun::STATE_PAUSED, $run->fresh()->state);
        $this->assertFalse($backlog->processBatch((int) $run->id, $epoch));
        $switch->turnOn(null, 'console');
        $this->assertFalse($backlog->processBatch((int) $run->id, $epoch), 'The old epoch cannot continue.');
        $this->assertFalse($backlog->processBatch((int) $run->id, $switch->epoch()), 'ON alone never resumes a paused run.');
        $this->assertSame(1, $this->assistantRecords()['station']);
    }

    // ================================================================================== 9. admin page

    public function test_a_super_admin_can_open_the_page_and_use_the_switch_actions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = $this->adminUser(super: true);
        $this->actingAs($admin);
        $switch = app(AiAutomationSwitch::class);

        $this->assertTrue(AiResponseAutomation::canAccess());
        $this->get('/admin/ai-response-automation')->assertOk()->assertSee('Support Services Automation');

        $page = Livewire::test(AiResponseAutomation::class)->assertSuccessful();
        $page->callAction('turnOn')->assertHasNoActionErrors();
        $this->assertTrue($switch->isOn());
        $this->assertSame($admin->id, (int) AiAutomationSwitchEvent::query()->latest('id')->value('actor_user_id'));

        Queue::fake();
        $this->submitStationRequest();
        $queued = AiIntakeEvent::query()->sole();
        $this->assertSame(AiIntakeEvent::STATE_QUEUED, $queued->state);

        $this->actingAs($admin); // the member API call above signed in as the requester
        Livewire::test(AiResponseAutomation::class)->callAction('turnOff')->assertHasNoActionErrors();
        $this->assertFalse($switch->isOn());
        $this->assertSame(AiIntakeEvent::STATE_CANCELLED, $queued->fresh()->state, 'OFF from the page cancels queued work at once.');
        $this->assertSame(1, (int) AiAutomationSwitchEvent::query()->latest('id')->value('suppressed_count'));
    }

    public function test_the_page_actions_preview_and_pause_work_for_a_super_admin_only(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->submitStationRequest();
        $this->actingAs($this->adminUser(super: true));

        Livewire::test(AiResponseAutomation::class)->callAction('previewBacklog')->assertHasNoActionErrors();

        $this->assertSame(1, AiBacklogRun::query()->count());
        $this->assertSame(1, AiBacklogRun::query()->sole()->preview['would_send']);
        $this->assertSame(0, AiIntakeEvent::query()->count());
    }

    public function test_an_admin_without_the_ai_capability_cannot_open_the_page_or_run_its_actions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $switch = app(AiAutomationSwitch::class);
        $switch->turnOn(null, 'console');

        foreach ([['admin.access'], ['admin.ai_automation.manage'], []] as $permissions) {
            $this->actingAs($this->adminUser(permissions: $permissions));
            $this->assertFalse(AiResponseAutomation::canAccess(), implode(',', $permissions));
            $this->assertNotSame(200, $this->get('/admin/ai-response-automation')->getStatusCode());
            Livewire::test(AiResponseAutomation::class)->assertForbidden();
        }
        $this->assertTrue($switch->isOn(), 'Nobody without the capability changed the switch.');

        // The capability granted through a role works once the panel entitlement is there.
        $role = Role::findOrCreate('ai-response-stewards', 'web');
        $role->givePermissionTo(Permission::findOrCreate('admin.ai_automation.manage', 'web'));
        $steward = $this->adminUser(permissions: ['admin.access']);
        $steward->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($steward->fresh());
        $this->assertTrue(AiResponseAutomation::canAccess());
        Livewire::test(AiResponseAutomation::class)->callAction('turnOff');
        $this->assertFalse($switch->isOn());
    }

    public function test_an_administrator_who_loses_access_after_the_page_opened_cannot_still_use_its_actions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $switch = app(AiAutomationSwitch::class);
        $steward = $this->adminUser(super: true);
        $this->actingAs($steward);
        $page = Livewire::test(AiResponseAutomation::class)->assertSuccessful();

        // Access is withdrawn while the browser tab is still open.
        $steward->removeRole('super_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($steward->fresh());

        // Raw Livewire calls, as a hand-built request would send them (the test helper refuses hidden actions).
        $page->call('mountAction', 'turnOn')->call('callMountedAction');
        $this->assertFalse($switch->isOn(), 'A stale page must not switch automation ON.');

        $switch->turnOn(null, 'console');
        $page->call('mountAction', 'turnOff')->call('callMountedAction');
        $this->assertTrue($switch->isOn(), 'A stale page must not switch automation OFF either.');

        $page->call('mountAction', 'previewBacklog')->call('callMountedAction');
        $this->assertSame(0, AiBacklogRun::query()->count(), 'A stale page must not start a backlog preview.');

        // The panel's own middleware already refuses the whole Livewire request (404), so the method never runs.
        $page->set('testText', 'anything')->call('runTestResponse')->assertNotFound()->assertSet('testResult', null);
    }

    // ============================================================================================ helpers

    private function switchOn(): void
    {
        app(AiAutomationSwitch::class)->turnOn(null, 'console');
    }

    /** @return array{vehicle: int, station: int, support: int} */
    private function assistantRecords(): array
    {
        return [
            'vehicle' => ApparatusIssueMessage::query()->where('author_context', 'assistant')->count(),
            'station' => StationRequestUpdate::query()->autoReceipts()->count(),
            'support' => EmployeeLogisticsMessage::query()->where('author_side', EmployeeLogisticsMessage::SIDE_ASSISTANT)->count(),
        ];
    }

    private function aiNotifications(): \Illuminate\Database\Query\Builder
    {
        return DB::table('notifications')->where('type', AiResponseNotification::class);
    }

    /** @return list<array{0: string, 1: string}> */
    private function aiDeliveries(): array
    {
        return array_values(array_filter($this->delivered, static fn (array $d): bool => $d[0] === AiResponseNotification::class));
    }

    private function assertOneBell(User $member, string $reply): void
    {
        $rows = $this->aiNotifications()->where('notifiable_id', $member->id)->get();
        $this->assertCount(1, $rows, 'Exactly one in-app notification for the submitter.');
        $payload = json_decode((string) $rows[0]->data, true);
        $this->assertSame('Support Services', $payload['title']);
        $this->assertSame($reply, $payload['body']);
        $this->assertSame(1, $this->aiNotifications()->count(), 'Nobody else is notified.');
        $this->assertSame([[AiResponseNotification::class, 'database']], $this->aiDeliveries());
    }

    private function assertNoMailAndNoPush(): void
    {
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        foreach ($this->aiDeliveries() as [, $channel]) {
            $this->assertSame('database', $channel);
        }
        $this->assertSame([], array_filter($this->aiDeliveries(), static fn (array $d): bool => $d[1] !== 'database'));
    }

    // ---- vehicle

    /**
     * First plain checklist item with this exact name for the template.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: int}
     */
    private function itemNamed(array $payload, string $name): array
    {
        foreach ($payload['compartments'] as $c => $compartment) {
            foreach ($compartment['items'] as $i => $item) {
                if ($item['name'] === $name && ! array_key_exists('value', $item)) {
                    return [$c, $i];
                }
            }
        }
        $this->fail("Checklist item '{$name}' not found.");
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: int}
     */
    private function firstPlainLogisticsItem(array $payload, string $template): array
    {
        $listed = (array) config("captain5_medical_scope.{$template}.medical", []);
        $ambiguous = (array) config("captain5_medical_scope.{$template}.ambiguous", []);
        foreach ($payload['compartments'] as $c => $compartment) {
            foreach ($compartment['items'] as $i => $item) {
                if (! array_key_exists('value', $item) && ! in_array($item['id'], $listed, true) && ! in_array($item['id'], $ambiguous, true)) {
                    return [$c, $i];
                }
            }
        }
        $this->fail('No suitable checklist item.');
    }

    /** @return array<string, mixed> */
    private function checkoutPayload(string $itemName, ?Apparatus $apparatus = null): array
    {
        $apparatus ??= $this->unit;
        $payload = $this->payload($apparatus, (int) $apparatus->fresh()->current_miles + 20, (float) $apparatus->fresh()->current_engine_hours + 1.2);
        [$c, $i] = $this->itemNamed($payload, $itemName);
        $this->markItem($payload, $c, $i, 'Missing', ['notes' => 'Not in the compartment.']);

        return $payload;
    }

    /** One item reported Missing on a unit, submitted through the real endpoint. */
    private function submitCheckout(string $itemName, ?Apparatus $apparatus = null, ?string $template = null): ApparatusInspection
    {
        $apparatus ??= $this->unit;
        $this->actAs($this->alpha);
        if ($template !== null) {
            $payload = $this->payload($apparatus, (int) $apparatus->fresh()->current_miles + 20, (float) $apparatus->fresh()->current_engine_hours + 1.2);
            [$c, $i] = $this->firstPlainLogisticsItem($payload, $template);
            $this->markItem($payload, $c, $i, 'Missing', ['notes' => 'Not in the compartment.']);
        } else {
            $payload = $this->checkoutPayload($itemName, $apparatus);
        }

        return ApparatusInspection::query()->findOrFail($this->submit($payload, $apparatus)->assertCreated()->json('id'));
    }

    /** @return array<string, mixed> */
    private function vehicleSnapshot(): array
    {
        return [
            'defects' => ApparatusDefect::query()->orderBy('id')->get()->toArray(),
            'inspections' => ApparatusInspection::query()->orderBy('id')->get()->toArray(),
            'apparatus' => Apparatus::query()->orderBy('id')->get()->toArray(),
            'events' => ApparatusDefectEvent::query()->orderBy('id')->get()->toArray(),
            'observations' => ApparatusDefectObservation::query()->orderBy('id')->get()->toArray(),
            'review_events' => ApparatusInspectionReviewEvent::query()->orderBy('id')->get()->toArray(),
            'participants' => ApparatusDefectParticipant::query()->orderBy('id')->get()->toArray(),
        ];
    }

    // ---- station

    /** Submits a repair request through the real member API and returns its id. */
    private function submitStationRequest(?string $clientKey = null, bool $expectCreated = true, string $title = 'Kitchen refrigerator is warm'): int
    {
        $this->actingAsCanonicalUser($this->requester);
        $response = $this->postJson('/api/public/station_request', [
            'client_submission_id' => $clientKey ?? (string) Str::uuid(),
            'station_id' => $this->station(1)->id,
            'room_id' => $this->room->id,
            'requested_by_employee_id' => $this->requesterEmployee->id,
            'request_type' => 'repair_service',
            'subject_type' => 'room',
            'title' => $title,
            'description' => 'The refrigerator temperature rose above the safe range.',
            'priority' => 'high',
            'items' => [[
                'item_name' => 'Refrigerator', 'category' => 'appliance', 'quantity' => 1,
                'reason' => 'Damaged/Broken', 'condition' => 'needs_repair',
            ]],
        ]);
        $expectCreated ? $response->assertCreated() : $response->assertOk();

        return (int) $response->json('data.id');
    }

    // ---- support

    private function supportStarts(User $member, string $body = 'Hello Support Services, I need a hand.'): EmployeeLogisticsMessage
    {
        return $this->service()->startThread($member, $body, null, $this->rid());
    }

    /** @return array<string, mixed> */
    private function threadState(EmployeeLogisticsMessage $message): array
    {
        $attributes = $this->storedThread($message)->getAttributes();
        foreach (['message_count', 'last_assistant_message_id', 'updated_at'] as $reply) {
            unset($attributes[$reply]); // the only fields the assistant is allowed to move
        }

        return $attributes;
    }

    // ---- other workflows

    private function personnelEquipmentRequest(): void
    {
        $publicId = (string) Str::ulid();
        $request = \App\Models\PersonnelRequest::query()->create([
            'public_id' => $publicId, 'request_number' => 'TEST-'.$publicId,
            'type' => 'equipment', 'status' => \App\Enums\PersonnelRequestStatus::Ordered,
            'beneficiary_employee_id' => $this->requesterEmployee->id, 'requester_employee_id' => $this->requesterEmployee->id,
            'beneficiary_name' => $this->requesterEmployee->name, 'beneficiary_employee_number' => $this->requesterEmployee->employee_id,
            'requester_name' => $this->requesterEmployee->name, 'requester_employee_number' => $this->requesterEmployee->employee_id,
            'idempotency_key' => 'fixture-'.$publicId,
        ]);
        $request->items()->create(['item_code' => 'hood', 'item_name' => 'Hood', 'category' => 'equipment', 'quantity' => 2]);
    }

    // ---- admin

    private function reviewerAdmin(): User
    {
        return $this->reviewer;
    }

    /** @param  list<string>  $permissions */
    private function adminUser(bool $super = false, array $permissions = []): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create([
            'account_status' => AccountStatus::Active, 'name' => 'Admin '.++$this->submissionSeq, 'must_change_password' => false,
        ]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        if ($super) {
            $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
